<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Herramientas de la IA activadas (o desactivadas) por empresa. Sin
        // fila, manda config('ai.tools.default_enabled').
        Schema::create('tenant_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'tool']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_tools');
    }
};
