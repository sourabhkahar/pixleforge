<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pixelforge's signed image URLs expire 24h after completion, so we keep our
 * own copy on the public disk.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public readonly int $generationId) {}

    public function handle(PixelforgeClient $pixelforge): void
    {
        $generation = Generation::find($this->generationId);
        if ($generation === null || $generation->image_path !== null) {
            return;
        }

        $url = $generation->image_url;

        // Signed URL gone stale? Ask Pixelforge for a fresh one (valid until the same expiry).
        if (! $url || ($generation->image_url_expires_at?->isPast() ?? false)) {
            $remote = $pixelforge->getGeneration((string) $generation->provider_id);
            $url = $remote['image_url'] ?? null;
            if (! $url) {
                return; // expired for good
            }
        }

        $response = Http::timeout(60)->get($url)->throw();

        $path = "generations/{$generation->user_id}/{$generation->uuid}.png";
        Storage::disk('public')->put($path, $response->body());

        $generation->forceFill(['image_path' => $path])->save();
    }
}
