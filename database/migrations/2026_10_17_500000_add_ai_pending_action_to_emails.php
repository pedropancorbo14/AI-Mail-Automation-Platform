<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación humana de acciones de ai-service (AI-Service, Fase 4).
 *
 *  - ai_pending_action: la acción que propuso ai-service y espera a una
 *    persona (action_id, tool, arguments, summary, thread_id, proposed_at) y,
 *    cuando alguien decide, approved / decided_by / decided_at (para
 *    republicar la decisión si se pierde). Null = ninguna.
 *  - ai_review_instruction: indicación de la persona al reprocesar; viaja en
 *    el siguiente email.received.v1 y se borra al llegar el resultado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->jsonb('ai_pending_action')->nullable()->after('ai_request_attempts');
            $table->text('ai_review_instruction')->nullable()->after('ai_pending_action');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn(['ai_pending_action', 'ai_review_instruction']);
        });
    }
};
