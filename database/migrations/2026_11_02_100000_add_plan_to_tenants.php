<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Plan y suscripción de cada empresa (Fase 17). Las que ya existían pasan al
 * plan "enterprise" activo: siguen funcionando como hasta ahora, sin límites
 * de plan. Las nuevas nacen en prueba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan', 40)->default('trial');
            $table->string('subscription_status', 20)->default('trialing');
            // null en prueba = sin fecha de fin (prueba dada por el operador).
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            // Desde cuándo está en past_due (para el periodo de gracia).
            $table->timestampTz('past_due_since')->nullable();
            $table->string('billing_customer_id')->nullable()->unique();
            $table->string('billing_subscription_id')->nullable()->unique();
        });

        DB::table('tenants')->update(['plan' => 'enterprise', 'subscription_status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'plan', 'subscription_status', 'trial_ends_at', 'current_period_ends_at',
                'past_due_since', 'billing_customer_id', 'billing_subscription_id',
            ]);
        });
    }
};
