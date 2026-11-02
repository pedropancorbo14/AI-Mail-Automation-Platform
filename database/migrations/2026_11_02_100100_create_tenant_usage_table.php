<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Uso de cada empresa por mes natural (Fase 17). Son contadores y no un
 * recuento de emails: borrar correos (a mano o por la retención) no libera
 * cuota del mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // Primer día del mes (zona horaria de la app).
            $table->date('period');
            // Correos guardados por la ingesta (también los automáticos).
            $table->unsignedInteger('emails_received')->default(0);
            // Correos enviados a la IA (cada reproceso cuenta: se paga otra vez).
            $table->unsignedInteger('ai_emails')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage');
    }
};
