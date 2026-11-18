<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationSync;
use App\Services\Webhooks\PixelforgeSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/pixelforge
 *
 * 1. Verify the signature against the RAW body (before decoding).
 * 2. Record the event id — the primary key rejects re-deliveries.
 * 3. Apply the embedded generation object via GenerationSync, which ignores
 *    stale (out-of-order) updates and settles money at most once.
 * Steps 2 and 3 share a transaction: if applying fails we answer 500, the
 * event row is rolled back, and Pixelforge's retry gets a clean second try.
 * The work is a few row updates, well inside Pixelforge's 10s budget; the
 * slow part (downloading the image) is queued.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, PixelforgeSignature $signature, GenerationSync $sync): JsonResponse
    {
        $raw = $request->getContent();

        if (! $signature->verify($raw, $request->header('Pixelforge-Signature'))) {
            Log::warning('Rejected Pixelforge webhook with invalid signature', ['event' => $request->header('Pixelforge-Event-Id')]);

            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $event = json_decode($raw, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        if ($event['type'] === 'ping') {
            return response()->json(['received' => true, 'type' => 'ping']);
        }

        $remote = $event['data']['object'] ?? null;
        if (! is_array($remote) || ($remote['object'] ?? null) !== 'generation') {
            return response()->json(['received' => true, 'ignored' => 'unsupported_event']);
        }

        try {
            $result = DB::transaction(function () use ($event, $remote, $request, $sync) {
                WebhookEvent::create([
                    'id' => $event['id'],
                    'type' => $event['type'],
                    'provider_generation_id' => $remote['id'] ?? null,
                    'payload' => $event,
                    'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                    'event_created_at' => isset($event['created_at']) ? Carbon::parse($event['created_at']) : null,
                ]);

                $generation = $this->findGeneration($remote);
                if ($generation === null) {
                    return 'unknown_generation'; // e.g. another environment sharing the URL; acknowledge
                }

                $changed = $sync->apply($generation, $remote);
                WebhookEvent::whereKey($event['id'])->update(['processed_at' => now()]);

                return $changed ? 'applied' : 'stale';
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        return response()->json(['received' => true, 'result' => $result]);
    }

    /**
     * Match by Pixelforge id, or by the UUID we put in metadata — the latter
     * works even when the webhook beats our own create response.
     */
    private function findGeneration(array $remote): ?Generation
    {
        if (! empty($remote['id'])) {
            $generation = Generation::where('provider_id', $remote['id'])->first();
            if ($generation) {
                return $generation;
            }
        }

        $uuid = $remote['metadata']['generation_uuid'] ?? null;

        return $uuid ? Generation::where('uuid', $uuid)->first() : null;
    }
}
