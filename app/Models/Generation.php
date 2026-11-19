<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Support\Money;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    protected $guarded = ['id', 'user_id'];

    protected static function booted(): void
    {
        static::creating(function (Generation $generation) {
            $generation->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimate_breakdown' => 'array',
            'status' => GenerationStatus::class,
            'estimated_cost_nanos' => 'integer',
            'max_cost_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charged_nanos' => 'integer',
            'submit_attempts' => 'integer',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Permanent application URL that securely serves/redirects to the Pixelforge image,
     * refreshing expired signed URLs automatically.
     */
    public function displayImageUrl(): ?string
    {
        if ($this->status !== GenerationStatus::Succeeded) {
            return null;
        }

        return route('generations.view', $this);
    }

    public function imageExpired(): bool
    {
        return $this->image_path === null
            && $this->image_url_expires_at !== null
            && $this->image_url_expires_at->isPast();
    }

    public function toFrontend(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'provider_id' => $this->provider_id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images ?? [],
            'status' => $this->status->value,
            'status_detail' => $this->status_detail,
            'terminal' => $this->status->isTerminal(),
            'estimated_cost' => Money::format($this->estimated_cost_nanos),
            'max_cost' => Money::format($this->max_cost_nanos),
            'actual_cost' => $this->charged_nanos !== null ? Money::format($this->charged_nanos) : null,
            'usage' => $this->usage,
            'error' => $this->error_message,
            'error_code' => $this->error_code,
            'image' => $this->displayImageUrl(),
            'image_expired' => $this->imageExpired(),
            'image_stored' => $this->image_path !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
