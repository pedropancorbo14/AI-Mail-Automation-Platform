<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Protección del gasto de IA (Fase 12): presupuesto diario por empresa
 * (null = el de la plataforma) e índice para contar los emails de un
 * remitente en las últimas 24 h.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('ai_daily_budget_usd', 10, 2)->nullable()->after('auto_send_intents');
        });

        // Funcional: se compara sin mayúsculas (si no, "Bob@x" y "bob@x" contarían aparte).
        DB::statement('CREATE INDEX emails_tenant_sender_created_index ON emails (tenant_id, lower(from_email), created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS emails_tenant_sender_created_index');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('ai_daily_budget_usd');
        });
    }
};
