<?php

namespace App\Http\Controllers;

use App\Actions\StartGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Pricing\CostCalculator;
use App\Services\Pricing\PricingCatalog;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request, PricingCatalog $pricing, CostCalculator $calculator): Response
    {
        $generations = $request->user()->generations()
            ->latest('id')
            ->paginate(12)
            ->through(fn (Generation $generation) => $generation->toFrontend());

        $catalog = $pricing->current();

        return Inertia::render('Generate/Index', [
            'options' => [
                'sizes' => collect(config('pixelforge.sizes'))->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'qualities' => collect(config('pixelforge.qualities'))->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            ],
            'limits' => config('pixelforge.limits'),
            'defaults' => ['size' => '1024x1024', 'quality' => 'medium'],
            // Initial estimate for the defaults so the price shows before any typing.
            'initialEstimate' => $calculator->estimate($catalog, 0, '1024x1024', 'medium', 0)->toArray(),
            'pricing' => [
                'rates' => $catalog['rates'],
                'markup_percent' => $calculator->markupPercent(),
                'source' => $catalog['source'],
            ],
            'generations' => $generations,
        ]);
    }

    /**
     * Live price estimate for the form. Takes only the prompt's LENGTH —
     * text tokens depend on nothing else.
     */
    public function estimate(Request $request, PricingCatalog $pricing, CostCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'prompt_characters' => ['required', 'integer', 'min:0', 'max:'.config('pixelforge.limits.prompt_max')],
            'size' => ['required', Rule::in(array_keys(config('pixelforge.sizes')))],
            'quality' => ['required', Rule::in(array_keys(config('pixelforge.qualities')))],
            'reference_images' => ['required', 'integer', 'min:0', 'max:'.config('pixelforge.limits.reference_images_max')],
        ]);

        $estimate = $calculator->estimate(
            $pricing->current(),
            (int) $data['prompt_characters'],
            $data['size'],
            $data['quality'],
            (int) $data['reference_images'],
        );

        return response()->json($estimate->toArray());
    }

    public function store(StoreGenerationRequest $request, StartGeneration $start): RedirectResponse
    {
        $data = $request->validated();

        try {
            $generation = $start->handle(
                $request->user(),
                $data['prompt'],
                $data['size'],
                $data['quality'],
                $data['reference_images'] ?? [],
            );
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors(['credits' => $e->getMessage()])->withInput();
        }

        return to_route('generations.index')->with(
            'success',
            'Generation #'.$generation->id.' queued. Up to '.Money::format($generation->max_cost_nanos)
            .' is reserved; you\'ll be charged the actual cost when it finishes.',
        );
    }

    /**
     * Lightweight JSON status for the gallery's live updates.
     */
    public function status(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->take(50);

        $generations = $request->user()->generations()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (Generation $generation) => $generation->toFrontend());

        $balance = (int) $request->user()->fresh()->balance_nanos;

        return response()->json([
            'generations' => $generations,
            'balance' => Money::format($balance),
            'balance_nanos' => $balance,
        ]);
    }
}
