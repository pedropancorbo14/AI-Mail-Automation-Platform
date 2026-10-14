<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatización por empresa (Fase 9): modo off | draft | auto
 * (App\Automation\AutomationMode, por defecto draft = todo a revisión
 * humana) e intenciones a las que la IA puede responder sola en modo auto
 * (null = las de ai.automation.default_auto_send_intents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('automation_mode', 10)->default('draft')->after('is_active');
            $table->jsonb('auto_send_intents')->nullable()->after('automation_mode');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['automation_mode', 'auto_send_intents']);
        });
    }
};
