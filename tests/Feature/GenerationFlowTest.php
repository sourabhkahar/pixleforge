<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Credits\CreditLedger;
use App\Services\Generations\GenerationSync;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Webhooks\PixelforgeSignature;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    /** Fixture usage at fixture rates: 60,000 + 0 + 44,000,000 + 15,425,000 nanodollars. */
    private const FIXTURE_PROVIDER_COST = 59_485_000;

    private const FIXTURE_CHARGE = 74_356_250; // × 1.25

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function user(string $dollars = '5.00'): User
    {
        $user = User::factory()->create();
        app(CreditLedger::class)->grant($user, Money::fromDecimal($dollars), 'Test credits');

        return $user->fresh();
    }

    /** Create a queued generation through the real endpoint, without running the queue. */
    private function startGeneration(User $user, array $input = []): Generation
    {
        // Hold back the Pixelforge jobs so each test drives them explicitly;
        // StoreGenerationImage still runs (sync queue).
        Queue::fake([SubmitGeneration::class, PollGeneration::class]);

        $this->actingAs($user)->post('/generations', $input + [
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertSessionHasNoErrors();

        return Generation::latest('id')->firstOrFail();
    }

    private function assertLedgerConsistent(User $user): void
    {
        $this->assertSame(
            (int) CreditTransaction::where('user_id', $user->id)->sum('amount_nanos'),
            $user->fresh()->balance_nanos,
            'Cached balance must equal the sum of the ledger.',
        );
    }

    // ------------------------------------------------------------------ page

    public function test_generate_page_shows_estimate_and_balance(): void
    {
        $this->actingAs($this->user())->get('/generate')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generate/Index')
                ->has('initialEstimate.price_expected')
                ->where('pricing.markup_percent', 25)
                ->where('pricing.source', 'pixelforge')
                ->where('auth.user.balance', '$5.00'));
    }

    public function test_estimate_endpoint_prices_with_markup(): void
    {
        $this->actingAs($this->user())
            ->getJson('/generations/estimate?prompt_characters=400&size=1536x1024&quality=high&reference_images=2')
            ->assertOk()
            ->assertJsonPath('price_max_nanos', Money::withMarkup(373_875_000, 25))
            ->assertJsonPath('price_max', '$0.46734375');
    }

    // ---------------------------------------------------------------- submit

    public function test_submitting_reserves_the_maximum_price_and_queues(): void
    {
        $user = $this->user();
        $generation = $this->startGeneration($user, ['reference_images' => ['https://example.com/a.png', ' ']]);

        $this->assertSame(['https://example.com/a.png'], $generation->reference_images);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertGreaterThan($generation->estimated_cost_nanos, $generation->max_cost_nanos);
        $this->assertSame(5_000_000_000 - $generation->max_cost_nanos, $user->fresh()->balance_nanos);
        $this->assertDatabaseHas('credit_transactions', ['generation_id' => $generation->id, 'type' => 'hold', 'amount_nanos' => -$generation->max_cost_nanos]);
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_cannot_start_a_generation_the_user_cannot_afford(): void
    {
        Queue::fake();
        $user = $this->user('0.01');

        $this->actingAs($user)->post('/generations', ['prompt' => 'big', 'size' => '1536x1024', 'quality' => 'high'])
            ->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
        $this->assertSame(10_000_000, $user->fresh()->balance_nanos);
        Queue::assertNothingPushed();
    }

    public function test_validation_mirrors_pixelforge_limits(): void
    {
        $this->actingAs($this->user())->post('/generations', [
            'prompt' => str_repeat('a', 4001),
            'size' => '512x512',
            'quality' => 'ultra',
            'reference_images' => ['not-a-url', 'https://1', 'https://2', 'https://3', 'https://4'],
        ])->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images']);
    }

    public function test_submit_job_sends_idempotency_key_and_only_known_fields(): void
    {
        $user = $this->user();
        $generation = $this->startGeneration($user);

        Http::fake(['pixelforge.test/v1/generations' => fn () => Http::response($this->remoteGeneration($generation, 'queued', ['id' => 'gen_A']), 202)]);

        Queue::fake();
        (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        Http::assertSent(function (Request $request) use ($generation) {
            return $request->url() === 'https://pixelforge.test/v1/generations'
                && $request->hasHeader('Idempotency-Key', $generation->uuid)
                && $request->hasHeader('Authorization', 'Bearer pf_test_key')
                && array_keys($request->data()) === ['prompt', 'size', 'quality', 'metadata']
                && $request['metadata']['generation_uuid'] === $generation->uuid;
        });

        $this->assertSame('gen_A', $generation->fresh()->provider_id);
        Queue::assertPushed(PollGeneration::class);
    }

    public function test_overloaded_or_rate_limited_submission_is_retried_after_retry_after(): void
    {
        $generation = $this->startGeneration($this->user());

        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many active generations.']],
            429,
            ['Retry-After' => '5'],
        )]);

        $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        $job->assertReleased();
        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertNull($generation->provider_id);
        $this->assertStringContainsString('too many requests', $generation->status_detail);
    }

    public function test_rejected_prompt_fails_immediately_and_is_free(): void
    {
        $user = $this->user();
        $generation = $this->startGeneration($user, ['prompt' => 'something [blocked]']);

        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Prompt rejected.']],
            400,
        )]);

        (new SubmitGeneration($generation->id))->withFakeQueueInteractions()
            ->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(0, $generation->charged_nanos);
        $this->assertSame(5_000_000_000, $user->fresh()->balance_nanos);
        $this->assertLedgerConsistent($user);
    }

    // -------------------------------------------------------------- webhooks

    public function test_succeeded_webhook_charges_actual_cost_with_markup_and_stores_image(): void
    {
        $user = $this->user();
        $generation = $this->startGeneration($user);
        $generation->forceFill(['provider_id' => 'gen_OK'])->save();
        Http::fake(['cdn.pixelforge.test/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);

        $this->sendWebhook($this->remoteGeneration($generation, 'succeeded'), 'generation.succeeded')
            ->assertOk()->assertJsonPath('result', 'applied');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::FIXTURE_PROVIDER_COST, $generation->provider_cost_nanos);
        $this->assertSame(self::FIXTURE_CHARGE, $generation->charged_nanos);
        $this->assertSame(5_000_000_000 - self::FIXTURE_CHARGE, $user->fresh()->balance_nanos);
        $this->assertNotNull($generation->image_path);
        Storage::disk('public')->assertExists($generation->image_path);
        $this->assertLedgerConsistent($user);
    }

    public function test_duplicate_and_resent_events_never_charge_twice(): void
    {
        $user = $this->user();
        $generation = $this->startGeneration($user);
        Http::fake(['cdn.pixelforge.test/*' => Http::response('PNG', 200)]);
        $succeeded = $this->remoteGeneration($generation, 'succeeded');

        $this->sendWebhook($succeeded, 'generation.succeeded', 'evt_1')->assertOk();
        $this->sendWebhook($succeeded, 'generation.succeeded', 'evt_1')->assertOk()->assertJsonPath('duplicate', true);
        // A different event id carrying the same final state (e.g. a resend) is also harmless.
        $this->sendWebhook($succeeded, 'generation.succeeded', 'evt_2')->assertOk()->assertJsonPath('result', 'stale');

        $this->assertSame(1, CreditTransaction::where('generation_id', $generation->id)->where('type', 'charge')->count());
        $this->assertSame(1, CreditTransaction::where('generation_id', $generation->id)->where('type', 'release')->count());
        $this->assertSame(5_000_000_000 - self::FIXTURE_CHARGE, $user->fresh()->balance_nanos);
        $this->assertSame(2, WebhookEvent::count());
        $this->assertLedgerConsistent($user);
    }

    public function test_out_of_order_events_never_move_a_generation_backwards(): void
    {
        $user = $this->user();
        $generation = $this->startGeneration($user);
        Http::fake(['cdn.pixelforge.test/*' => Http::response('PNG', 200)]);

        $this->sendWebhook($this->remoteGeneration($generation, 'succeeded'), 'generation.succeeded')->assertOk();
        $this->sendWebhook($this->remoteGeneration($generation, 'processing'), 'generation.processing')->assertOk()->assertJsonPath('result', 'stale');
        $this->sendWebhook($this->remoteGeneration($generation, 'failed'), 'generation.failed')->assertOk()->assertJsonPath('result', 'stale');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::FIXTURE_CHARGE, $generation->charged_nanos);
        $this->assertLedgerConsistent($user);
    }

    public function test_webhook_arriving_before_the_create_response_is_matched_by_metadata(): void
    {
        $user = $this->user();
        $generation = $this->startGeneration($user);
        $this->assertNull($generation->provider_id);

        $this->sendWebhook($this->remoteGeneration($generation, 'processing', ['id' => 'gen_EARLY']), 'generation.processing')
            ->assertOk()->assertJsonPath('result', 'applied');

        $generation->refresh();
        $this->assertSame('gen_EARLY', $generation->provider_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);

        // The (late) create response must not resubmit or regress anything.
        Http::fake(['pixelforge.test/v1/generations' => Http::response([], 500)]);
        (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);
    }

    public function test_failed_generation_is_free(): void
    {
        $user = $this->user();
        $generation = $this->startGeneration($user, ['prompt' => 'please [fail]']);

        $this->sendWebhook($this->remoteGeneration($generation, 'failed'), 'generation.failed')->assertOk();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertSame(0, $generation->charged_nanos);
        $this->assertSame(5_000_000_000, $user->fresh()->balance_nanos);
        $this->assertSame(0, CreditTransaction::where('generation_id', $generation->id)->where('type', 'charge')->count());
        $this->assertLedgerConsistent($user);
    }

    public function test_webhooks_with_bad_or_stale_signatures_are_rejected(): void
    {
        $generation = $this->startGeneration($this->user());
        $object = $this->remoteGeneration($generation, 'succeeded');

        $this->sendWebhook($object, 'generation.succeeded', signature: 't='.time().',v1=deadbeef')->assertStatus(400);
        $this->sendWebhook($object, 'generation.succeeded', timestamp: time() - 600)->assertStatus(400);

        $this->assertSame(GenerationStatus::Queued, $generation->fresh()->status);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_ping_events_are_acknowledged(): void
    {
        $body = json_encode(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => []]]);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => app(PixelforgeSignature::class)->sign($body, time()),
        ], $body)->assertOk()->assertJsonPath('type', 'ping');
    }

    // --------------------------------------------------------------- polling

    public function test_polling_settles_a_generation_when_no_webhook_arrives(): void
    {
        $user = $this->user();
        $generation = $this->startGeneration($user);
        $generation->forceFill(['provider_id' => 'gen_POLL', 'status' => GenerationStatus::Processing])->save();

        Http::fake([
            'pixelforge.test/v1/generations/gen_POLL' => Http::response($this->remoteGeneration($generation, 'succeeded')),
            'cdn.pixelforge.test/*' => Http::response('PNG', 200),
        ]);

        (new PollGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
        $this->assertSame(self::FIXTURE_CHARGE, $generation->fresh()->charged_nanos);

        // A webhook for the same result arriving later changes nothing.
        $this->sendWebhook($this->remoteGeneration($generation->fresh(), 'succeeded'), 'generation.succeeded')->assertJsonPath('result', 'stale');
        $this->assertSame(5_000_000_000 - self::FIXTURE_CHARGE, $user->fresh()->balance_nanos);
    }

    // ------------------------------------------------------------ status api

    public function test_status_endpoint_only_returns_own_generations(): void
    {
        $owner = $this->user();
        $generation = Generation::factory()->for($owner)->create();

        $this->actingAs($this->user())->getJson('/generations/status?ids='.$generation->id)
            ->assertOk()->assertJsonCount(0, 'generations');

        $this->actingAs($owner)->getJson('/generations/status?ids='.$generation->id)
            ->assertOk()->assertJsonPath('generations.0.status', 'queued');
    }
}
