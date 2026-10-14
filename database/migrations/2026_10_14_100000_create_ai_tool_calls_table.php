<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada vez que se pide una herramienta (se ejecute o no): auditoría
        // de qué consultó la IA, con qué argumentos y qué obtuvo.
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // Petición de IA que pidió la herramienta (null si se llamó a mano).
            $table->foreignId('ai_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_id')->nullable()->constrained()->nullOnDelete();

            $table->string('tool', 100);
            $table->jsonb('arguments');
            // Lo que se devolvió al modelo (datos o {error}).
            $table->jsonb('result')->nullable();

            // success | error | rejected (App\Tools\Enums\ToolCallStatus)
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'tool']);
            $table->index('ai_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
    }
};
