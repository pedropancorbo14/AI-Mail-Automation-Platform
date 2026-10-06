<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de TODAS las llamadas a la IA (también las fallidas):
        // auditoría, costes y métricas (Grafana, Fase 11).
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // El registro de coste se conserva aunque se borre el email.
            $table->foreignId('email_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_prompt_version_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('purpose', 100);             // = clave del prompt (email_classification...)
            $table->string('provider', 30);             // anthropic
            $table->string('requested_model', 100);
            $table->string('model', 100)->nullable();   // el que respondió (fallback incluido)
            $table->boolean('used_fallback')->default(false);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            // USD; null si el modelo no tiene precio configurado.
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // success | failed | refused (App\AI\Enums\AiRequestStatus)
            $table->string('status', 20);
            $table->string('stop_reason', 30)->nullable();
            $table->text('error')->nullable();
            $table->string('provider_request_id')->nullable();

            // Resultado (clasificación): también va a emails; aquí, para auditar cada intento.
            $table->string('intent', 50)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->jsonb('response')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
