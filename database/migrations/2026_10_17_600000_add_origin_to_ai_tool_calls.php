<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién decidió cada consulta de ai-service (Fase 5): plan (el backend, según
 * la intención), model (el modelo al redactar) o approval (una acción aprobada
 * por una persona). Null = el motor de Laravel o un resultado anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
