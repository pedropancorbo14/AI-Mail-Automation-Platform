<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo sombra (AI-Service, Fase 5): con el motor de Laravel activo, una
 * muestra de emails se procesa también en ai-service sin efectos, para
 * comparar los dos motores. Una fila por copia: lo que recomendó ai-service
 * (nunca se aplica al email), lo que costó y, cuando los dos motores han
 * terminado, la comparación.
 *
 * Se borra con el email (cascade): el borrador y la clasificación son datos
 * del cliente y siguen la retención del email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_shadow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            // event_id del email.received.v1 en sombra (= causation_id del resultado).
            $table->uuid('event_id')->unique();
            $table->string('status', 20)->default('pending'); // pending, completed, failed
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();

            // Lo que recomendó ai-service.
            $table->string('category', 40)->nullable();
            $table->decimal('confidence', 4, 3)->nullable(); // de la clasificación
            $table->decimal('reply_confidence', 4, 3)->nullable();
            $table->string('decision', 20)->nullable(); // auto_send, human_review, escalate
            $table->text('draft')->nullable();
            $table->jsonb('reasons')->nullable();
            $table->jsonb('specialists')->nullable();
            $table->jsonb('tool_calls')->nullable(); // resumen: herramienta, estado, origen
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error', 500)->nullable();

            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_shadow_runs');
    }
};
