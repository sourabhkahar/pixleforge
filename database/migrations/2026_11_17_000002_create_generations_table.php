<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Our public id: sent to Pixelforge as Idempotency-Key and in metadata,
            // so a webhook can be matched even if it beats the create response.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Request
            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->json('reference_images');

            // Pixelforge state
            $table->string('provider_id')->nullable()->unique();
            $table->string('status', 16)->default('queued')->index();
            $table->string('status_detail')->nullable(); // e.g. "Pixelforge is busy — retrying"
            $table->json('rates')->nullable();           // locked by Pixelforge at creation
            $table->json('usage')->nullable();           // token counts, known once succeeded
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Money, all integer nanodollars.
            $table->bigInteger('estimated_cost_nanos');      // what we told the user (expected, incl. markup)
            $table->bigInteger('max_cost_nanos');            // worst case incl. markup = amount held
            $table->bigInteger('provider_cost_nanos')->nullable(); // what Pixelforge bills us
            $table->bigInteger('charged_nanos')->nullable();       // what the user actually paid
            $table->json('estimate_breakdown')->nullable();

            // Image
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();    // our own copy, survives URL expiry

            // Lifecycle
            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable(); // Pixelforge's completed_at (UTC) = billing day
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
