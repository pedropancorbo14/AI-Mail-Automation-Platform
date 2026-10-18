<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Antes: rol de solo lectura de ai-service (ai_service_reader) sobre
 * documents y document_chunks, con RLS por empresa. Ya no hace falta: desde
 * la retirada del motor de IA de Laravel, ai-service indexa y busca los
 * documentos en su propio esquema (ai_service.knowledge_chunks). Se deja
 * vacía para no romper el historial de migraciones; lo que creó en bases de
 * datos existentes lo quita 2026_10_18_300000_drop_document_chunks_and_reader.
 */
return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
