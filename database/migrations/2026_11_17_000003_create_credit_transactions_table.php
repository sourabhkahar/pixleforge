<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 16); // grant | purchase | hold | release | charge
            $table->bigInteger('amount_nanos');          // signed: negative takes credits away
            $table->bigInteger('balance_after_nanos');
            $table->string('description');
            $table->timestamps();

            // The database itself guarantees a generation is never held,
            // released or charged twice — whatever webhooks/pollers race.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
