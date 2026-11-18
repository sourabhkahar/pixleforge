<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never hit the real Pixelforge from tests. /v1/pricing answers with
        // the documented rates; individual tests fake the other endpoints.
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response([
                'object' => 'pricing',
                'currency' => 'usd',
                'unit' => 'per_1m_tokens',
            ] + config('pixelforge.fallback_pricing')),
        ]);
    }
}
