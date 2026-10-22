<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 14: el sistema propio de una empresa también puede servir sus citas
 * (agenda de una clínica, un taller…) por la API estándar (/v1/appointments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->boolean('appointments')->default(false)->after('invoices');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->dropColumn('appointments');
        });
    }
};
