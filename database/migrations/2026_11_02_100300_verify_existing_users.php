<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Desde la Fase 17 solo entran usuarios con el email confirmado. Los que ya
 * existían los dio de alta el operador o un propietario: se dan por
 * confirmados (si no, nadie podría entrar tras actualizar).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('created_at')]);
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Nada que deshacer: no se sabe cuáles estaban sin confirmar.
    }
};
