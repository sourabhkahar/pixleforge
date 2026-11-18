<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every Pixelforge webhook event we have accepted, keyed by its evt_ id.
 * The primary key is what stops a re-delivered event being applied twice.
 */
class WebhookEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempt' => 'integer',
            'event_created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
