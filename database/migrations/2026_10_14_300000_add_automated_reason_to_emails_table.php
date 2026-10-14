<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué un email es automático (rebote, respuesta automática, lista,
 * boletín). Lo detecta la ingesta por cabeceras; null = escrito por una
 * persona. La automatización (Fase 9) nunca responde a estos correos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('automated_reason', 500)->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn('automated_reason');
        });
    }
};
