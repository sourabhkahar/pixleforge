<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreditsAndReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registrations_get_signup_credits(): void
    {
        $this->post('/register', [
            'name' => 'New', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertSame(5_000_000_000, $user->balance_nanos);
    }

    public function test_buy_credits_adds_a_purchase(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits/purchase', ['amount' => '10.00'])->assertRedirect();
        $this->actingAs($user)->post('/credits/purchase', ['amount' => '999.00'])->assertSessionHasErrors('amount');

        $this->assertSame(10_000_000_000, $user->fresh()->balance_nanos);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $user->id, 'type' => 'purchase', 'amount_nanos' => 10_000_000_000]);
    }

    public function test_admin_report_reconciles_with_pixelforge_usage(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $alice = User::factory()->create(['name' => 'Alice']);

        $make = fn (User $user, string $completed, int $provider) => Generation::factory()->for($user)->create([
            'status' => GenerationStatus::Succeeded,
            'completed_at' => $completed,
            'provider_cost_nanos' => $provider,
            'charged_nanos' => Money::withMarkup($provider, 25),
        ]);
        $make($alice, '2026-11-16 23:59:59', 100_000_000);
        $make($alice, '2026-11-17 00:00:01', 59_485_000);
        $make($admin, '2026-11-17 08:00:00', 300_000_000);
        Generation::factory()->for($alice)->create(['status' => GenerationStatus::Failed, 'completed_at' => '2026-11-17 09:00:00', 'provider_cost_nanos' => 0, 'charged_nanos' => 0]);

        Http::fake(['pixelforge.test/v1/usage*' => Http::response([
            'object' => 'usage',
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-17',
            'data' => [
                ['date' => '2026-11-16', 'generations' => 1, 'cost_usd' => '0.100000000'],
                ['date' => '2026-11-17', 'generations' => 2, 'cost_usd' => '0.359485000'],
            ],
            'total' => ['generations' => 3, 'cost_usd' => '0.459485000'],
        ])]);

        $this->actingAs($admin)->get('/admin/report?start=2026-11-16&end=2026-11-17')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Report')
                ->where('totals.generations', 3)
                ->where('totals.provider_cost', '$0.459485')
                ->where('totals.pixelforge_cost', '$0.459485')
                ->where('totals.charged', '$0.57435625')
                ->where('totals.profit', '$0.11487125')
                ->where('days.0.date', '2026-11-17')
                ->where('days.0.generations', 2)
                ->where('days.0.matches', true)
                ->has('days.0.users', 2)
                ->where('days.1.matches', true));

        $this->actingAs($alice)->get('/admin/report')->assertForbidden();
    }

    public function test_grant_command(): void
    {
        $user = User::factory()->create(['email' => 'top@example.com']);

        $this->artisan('credits:grant', ['email' => 'top@example.com', 'amount' => '2.5'])
            ->expectsOutputToContain('New balance: $2.50')
            ->assertSuccessful();

        $this->assertSame(2_500_000_000, $user->fresh()->balance_nanos);
    }
}
