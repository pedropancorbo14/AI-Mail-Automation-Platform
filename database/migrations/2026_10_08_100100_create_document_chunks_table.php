<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trozos de cada documento con su embedding (búsqueda semántica, pgvector).
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // Orden del trozo dentro del documento (0, 1, 2...).
            $table->unsignedInteger('position');
            $table->text('content');
            $table->unsignedInteger('token_count')->default(0);
            // Dónde está en el original, para citar la fuente: page, heading...
            $table->jsonb('metadata')->nullable();

            // Debe coincidir con config('ai.embeddings.dimensions') (bge-m3: 1024).
            $table->vector('embedding', 1024);

            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'position']);
            $table->index('tenant_id');
            // HNSW + vector_cosine_ops: búsqueda aproximada por distancia coseno (<=>).
            $table->vectorIndex('embedding');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
