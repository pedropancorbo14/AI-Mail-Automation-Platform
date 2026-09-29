<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Un usuario pertenece siempre a una única empresa. restrictOnDelete:
            // no se puede borrar un tenant que aún tiene usuarios (el borrado de
            // datos de una empresa se hará de forma explícita, Fase 12).
            $table->foreignId('tenant_id')
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
