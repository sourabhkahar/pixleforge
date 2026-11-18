<?php

namespace App\Services\Pricing;

use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Current Pixelforge rates and token rules from GET /v1/pricing (cached),
 * falling back to the documented values if the endpoint is unreachable.
 *
 * Used for ESTIMATES only. Actual costs use the rates Pixelforge locked onto
 * each generation at creation time.
 */
class PricingCatalog
{
    private const CACHE_KEY = 'pixelforge:pricing';

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array{rates: array<string,string>, tokens: array, source: string}
     */
    public function current(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $pricing = $this->normalize($this->client->pricing()) + ['source' => 'pixelforge'];
            Cache::put(self::CACHE_KEY, $pricing, config('pixelforge.pricing_cache_seconds'));

            return $pricing;
        } catch (PixelforgeApiException|\InvalidArgumentException $e) {
            Log::warning('Using fallback Pixelforge pricing', ['error' => $e->getMessage()]);

            // Cache the fallback briefly so a Pixelforge outage doesn't add latency to every page.
            $fallback = $this->normalize(config('pixelforge.fallback_pricing')) + ['source' => 'fallback'];
            Cache::put(self::CACHE_KEY, $fallback, 60);

            return $fallback;
        }
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function normalize(array $pricing): array
    {
        $rates = $pricing['rates'] ?? null;
        $tokens = $pricing['tokens'] ?? null;

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            if (! isset($rates[$key])) {
                throw new \InvalidArgumentException("Pricing is missing rates.{$key}");
            }
            $rates[$key] = (string) $rates[$key];
        }

        foreach (['output', 'reasoning', 'per_reference_image', 'characters_per_text_token'] as $key) {
            if (! isset($tokens[$key])) {
                throw new \InvalidArgumentException("Pricing is missing tokens.{$key}");
            }
        }

        $tokens['text_token_variance'] = (float) ($tokens['text_token_variance'] ?? 0.15);

        return ['rates' => $rates, 'tokens' => $tokens];
    }
}
