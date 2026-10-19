<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos que una empresa importa por CSV (Fase 14). Laravel es el dueño de
 * estos datos (permisos, auditoría, supresión) y los sirve al servidor MCP
 * con la API estándar (contracts/integrations/). Mismos campos que el
 * esquema order.v1: nunca la dirección completa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('number', 64);
            // En minúsculas: se busca siempre por el remitente del email.
            $table->string('customer_email', 254);
            $table->string('customer_name', 200)->nullable();
            $table->string('status', 32);
            $table->timestampTz('placed_at')->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->jsonb('items')->nullable();
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('tracking_url', 2000)->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('estimated_delivery')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->char('shipping_country', 2)->nullable();
            $table->timestamps();

            // Volver a importar actualiza por número de pedido.
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'customer_email', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_orders');
    }
};
