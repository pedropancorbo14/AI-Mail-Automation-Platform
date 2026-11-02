<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Avisos de Stripe ya tratados (Fase 17): Stripe entrega cada evento al menos
 * una vez y puede repetirlo; con el id único se aplica una sola vez.
 * tenants.billing_event_at: hora (de Stripe) del último aviso aplicado a la
 * suscripción, para no volver atrás si llegan desordenados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id');
            $table->string('type', 100);
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            // applied / ignored / stale (más antiguo que el último aplicado).
            $table->string('result', 20);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['provider', 'event_id']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestampTz('billing_event_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('billing_event_at');
        });
    }
};
