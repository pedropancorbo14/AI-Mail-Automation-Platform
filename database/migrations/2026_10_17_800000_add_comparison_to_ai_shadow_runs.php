<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comparación de una copia en sombra con lo que hizo el motor de Laravel
 * (ai:shadow-compare, cuando los dos han terminado). Decisiones normalizadas:
 * auto_send (la respuesta se podía enviar sola), human_review (a una
 * persona; "escalar" cuenta como tal), ignore (no necesita respuesta) o failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_shadow_runs', function (Blueprint $table) {
            $table->timestamp('compared_at')->nullable()->after('error');
            $table->string('laravel_intent', 40)->nullable()->after('compared_at');
            $table->string('laravel_decision', 20)->nullable()->after('laravel_intent');
            $table->string('python_decision', 20)->nullable()->after('laravel_decision');
            $table->boolean('intent_match')->nullable()->after('python_decision');
            $table->boolean('decision_match')->nullable()->after('intent_match');
            $table->decimal('laravel_cost_usd', 12, 6)->nullable()->after('decision_match');
            $table->unsignedInteger('laravel_duration_ms')->nullable()->after('laravel_cost_usd');
            $table->index(['tenant_id', 'compared_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_shadow_runs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'compared_at']);
            $table->dropColumn([
                'compared_at', 'laravel_intent', 'laravel_decision', 'python_decision',
                'intent_match', 'decision_match', 'laravel_cost_usd', 'laravel_duration_ms',
            ]);
        });
    }
};
