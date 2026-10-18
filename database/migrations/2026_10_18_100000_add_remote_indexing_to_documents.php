<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El indexado de documentos pasa a ai-service (retirada del motor de IA de
 * Laravel): el último document.uploaded.v1 publicado, cuándo y cuántas veces
 * (el reconciliador vuelve a publicar los que se quedan "Indexando").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('index_event_id')->nullable();
            $table->timestamp('index_requested_at')->nullable();
            $table->unsignedSmallInteger('index_attempts')->default(0);
            $table->index(['status', 'index_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['status', 'index_requested_at']);
            $table->dropColumn(['index_event_id', 'index_requested_at', 'index_attempts']);
        });
    }
};
