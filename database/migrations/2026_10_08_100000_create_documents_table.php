<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documentación interna de cada empresa para el RAG.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            // Nombre con el que se subió: solo para mostrar, NUNCA como ruta.
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            // Fichero original en disco privado.
            $table->string('disk');
            $table->string('path');
            $table->char('sha256', 64);

            // pending | processing | ready | failed (App\Documents\Enums\DocumentStatus)
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedInteger('chunks_count')->default(0);
            // Modelo con el que se calcularon los embeddings: si cambia, hay que reindexar.
            $table->string('embedding_model', 100)->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // La misma empresa no sube dos veces el mismo fichero.
            $table->unique(['tenant_id', 'sha256']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
