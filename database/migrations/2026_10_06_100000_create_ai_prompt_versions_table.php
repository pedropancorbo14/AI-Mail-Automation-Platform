<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copia de cada versión de prompt usada (los prompts se escriben en
        // código, app/AI/Prompts). Global: los prompts son de la plataforma.
        Schema::create('ai_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);                 // p. ej. email_classification
            $table->unsignedInteger('version');
            $table->text('system_prompt');
            $table->jsonb('output_schema')->nullable();
            // SHA-256 de system_prompt + output_schema: detecta cambios sin subir versión.
            $table->char('content_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_versions');
    }
};
