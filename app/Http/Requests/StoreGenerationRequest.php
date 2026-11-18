<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own validation so bad input is caught before any
 * credits are reserved.
 */
class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $urls = collect($this->input('reference_images', []))
            ->map(fn ($url) => is_string($url) ? trim($url) : $url)
            ->filter(fn ($url) => $url !== null && $url !== '')
            ->values()
            ->all();

        $this->merge([
            'prompt' => is_string($this->input('prompt')) ? trim($this->input('prompt')) : $this->input('prompt'),
            'reference_images' => $urls,
        ]);
    }

    public function rules(): array
    {
        $limits = config('pixelforge.limits');

        return [
            'prompt' => ['required', 'string', 'max:'.$limits['prompt_max']],
            'size' => ['required', 'string', Rule::in(array_keys(config('pixelforge.sizes')))],
            'quality' => ['required', 'string', Rule::in(array_keys(config('pixelforge.qualities')))],
            'reference_images' => ['array', 'max:'.$limits['reference_images_max']],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to :max reference images.',
            'reference_images.*.url' => 'Reference image #:position must be a valid http(s) URL.',
        ];
    }
}
