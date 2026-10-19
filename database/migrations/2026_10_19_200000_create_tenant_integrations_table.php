<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistemas propios que conecta una empresa (Fase 14). De momento uno: la API
 * estándar (contracts/integrations/) en su ERP o programa propio, con su URL
 * y su clave cifrada. El servidor MCP la recibe de Laravel por la ruta
 * interna /internal/integrations/{tenant}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('base_url', 2000);
            // Clave de la API de la empresa, cifrada con APP_KEY.
            $table->text('credentials');
            // Qué partes de la API implementa la empresa.
            $table->boolean('orders')->default(true);
            $table->boolean('invoices')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('status', 16)->default('pending');
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_integrations');
    }
};
