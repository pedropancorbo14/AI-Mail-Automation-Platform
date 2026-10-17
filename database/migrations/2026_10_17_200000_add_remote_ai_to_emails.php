<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de IA remoto (ai-service, AI_ENGINE=python).
 *
 *  - ai_event_id: event_id del último email.received.v1 publicado para el
 *    email (se compara con el causation_id del resultado).
 *  - ai_requested_at: cuándo se publicó. Si pasa el plazo sin resultado, el
 *    reconciliador lo vuelve a publicar.
 *  - ai_request_attempts: publicaciones hechas; agotadas → failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->uuid('ai_event_id')->nullable()->after('classified_at');
            $table->timestamp('ai_requested_at')->nullable()->after('ai_event_id');
            $table->unsignedSmallInteger('ai_request_attempts')->default(0)->after('ai_requested_at');
            // El reconciliador busca emails en processing publicados hace tiempo.
            $table->index(['status', 'ai_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['status', 'ai_requested_at']);
            $table->dropColumn(['ai_event_id', 'ai_requested_at', 'ai_request_attempts']);
        });
    }
};
