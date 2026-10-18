<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retirada del motor de IA de Laravel: sin dos motores no hay nada que
 * comparar en sombra. La comparación con Claude real quedó en
 * ai-service/evals/retirement-gate-2026-10-18.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_shadow_runs');
    }

    public function down(): void
    {
        Schema::create('ai_shadow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id')->unique();
            $table->string('status', 20)->default('pending');
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->timestamps();
        });
    }
};
