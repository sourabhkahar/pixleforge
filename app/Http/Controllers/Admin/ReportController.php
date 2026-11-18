<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator report: per UTC day and per user — what users were charged, what
 * Pixelforge charged us, and the profit — with each day's provider cost
 * checked against Pixelforge's own GET /v1/usage.
 *
 * Days are bucketed by completed_at (UTC), matching Pixelforge's billing day.
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = CarbonImmutable::parse($validated['end'] ?? now('UTC')->toDateString(), 'UTC')->startOfDay();
        $start = CarbonImmutable::parse($validated['start'] ?? $end->subDays(13)->toDateString(), 'UTC')->startOfDay();
        if ($start->diffInDays($end) > 91) {
            $start = $end->subDays(91); // Pixelforge's /v1/usage allows at most 92 days
        }

        $rows = Generation::query()
            ->with('user:id,name,email')
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$start, $end->endOfDay()])
            ->get(['id', 'user_id', 'completed_at', 'provider_cost_nanos', 'charged_nanos']);

        $byDay = $rows->groupBy(fn (Generation $g) => $g->completed_at->utc()->toDateString());
        $usage = $this->providerUsage($pixelforge, $start->toDateString(), $end->toDateString());

        $days = collect(CarbonPeriod::create($start, $end))->reverse()->map(function ($date) use ($byDay, $usage) {
            $day = $date->toDateString();
            /** @var Collection<int, Generation> $generations */
            $generations = $byDay->get($day, collect());

            $users = $generations->groupBy('user_id')->map(fn (Collection $g) => $this->totals($g) + [
                'user' => $g->first()->user?->only(['id', 'name', 'email']),
            ])->sortByDesc('charged_nanos')->values();

            $ours = $this->totals($generations);
            $theirs = $usage['days'][$day] ?? null;

            return $ours + [
                'date' => $day,
                'users' => $users,
                'pixelforge_cost' => $theirs ? Money::format($theirs['cost_nanos']) : null,
                'pixelforge_generations' => $theirs['generations'] ?? null,
                'matches' => $theirs === null ? null
                    : ($theirs['cost_nanos'] === $ours['provider_cost_nanos'] && $theirs['generations'] === $ours['generations']),
            ];
        })->values();

        return Inertia::render('Admin/Report', [
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'totals' => $this->totals($rows) + [
                'pixelforge_cost' => isset($usage['total']) ? Money::format($usage['total']) : null,
            ],
            'days' => $days,
            'usageError' => $usage['error'] ?? null,
        ]);
    }

    private function totals(Collection $generations): array
    {
        $charged = (int) $generations->sum('charged_nanos');
        $provider = (int) $generations->sum('provider_cost_nanos');

        return [
            'generations' => $generations->count(),
            'charged_nanos' => $charged,
            'provider_cost_nanos' => $provider,
            'charged' => Money::format($charged),
            'provider_cost' => Money::format($provider),
            'profit' => Money::format($charged - $provider),
        ];
    }

    private function providerUsage(PixelforgeClient $pixelforge, string $start, string $end): array
    {
        try {
            $response = Cache::remember("pixelforge:usage:{$start}:{$end}", 60, fn () => $pixelforge->usage($start, $end));
        } catch (PixelforgeApiException $e) {
            return ['error' => 'Could not load Pixelforge usage: '.$e->getMessage()];
        }

        $days = collect($response['data'] ?? [])->mapWithKeys(fn (array $day) => [$day['date'] => [
            'generations' => (int) ($day['generations'] ?? 0),
            'cost_nanos' => Money::fromDecimal((string) ($day['cost_usd'] ?? '0')),
        ]])->all();

        return [
            'days' => $days,
            'total' => isset($response['total']['cost_usd']) ? Money::fromDecimal((string) $response['total']['cost_usd']) : null,
        ];
    }
}
