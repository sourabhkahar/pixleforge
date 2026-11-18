<?php

namespace Tests\Support;

use App\Models\Generation;
use App\Services\Webhooks\PixelforgeSignature;

trait PixelforgeFixtures
{
    protected array $rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /** A Pixelforge generation object in the given state. */
    protected function remoteGeneration(Generation $generation, string $status, array $overrides = []): array
    {
        $base = [
            'id' => $generation->provider_id ?? 'gen_'.strtoupper(substr(md5($generation->uuid), 0, 26)),
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images ?? [],
            'metadata' => ['generation_uuid' => $generation->uuid, 'user_id' => (string) $generation->user_id],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => $this->rates,
            'error' => null,
            'created_at' => '2026-11-17T10:00:00Z',
            'started_at' => $status === 'queued' ? null : '2026-11-17T10:00:03Z',
            'completed_at' => null,
        ];

        if ($status === 'succeeded') {
            $base['usage'] = [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 1234,
                'total_tokens' => 2346,
            ];
            $base['image_url'] = 'https://cdn.pixelforge.test/img/'.$base['id'].'.png?sig=abc';
            $base['image_url_expires_at'] = now()->addDay()->toIso8601String();
            $base['completed_at'] = '2026-11-17T10:00:20Z';
        }

        if ($status === 'failed') {
            $base['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'];
            $base['completed_at'] = '2026-11-17T10:00:20Z';
        }

        return array_replace_recursive($base, $overrides);
    }

    /** POST a correctly signed webhook. */
    protected function sendWebhook(array $object, string $type, ?string $eventId = null, ?int $timestamp = null, ?string $signature = null)
    {
        $event = [
            'id' => $eventId ?? 'evt_'.bin2hex(random_bytes(8)),
            'object' => 'event',
            'type' => $type,
            'created_at' => now()->toIso8601String(),
            'data' => ['object' => $object],
        ];
        $body = json_encode($event);
        $timestamp ??= time();
        $signature ??= app(PixelforgeSignature::class)->sign($body, $timestamp);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $signature,
            'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
            'HTTP_PIXELFORGE_EVENT_TYPE' => $type,
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
        ], $body);
    }
}
