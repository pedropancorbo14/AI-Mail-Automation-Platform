<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los fragmentos de la base de conocimiento ahora son de ai-service (su
 * esquema ai_service): fuera document_chunks (de Laravel) y los permisos y
 * la RLS del antiguo rol de solo lectura ai_service_reader. El rol no se
 * borra: es del clúster y otras bases de datos pueden usarlo (se puede quitar
 * a mano con DROP ROLE ai_service_reader).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS ai_service_tenant_isolation ON documents;
            ALTER TABLE documents DISABLE ROW LEVEL SECURITY;
            SQL);

        if (DB::selectOne("SELECT 1 AS found FROM pg_roles WHERE rolname = 'ai_service_reader'") !== null) {
            DB::unprepared('REVOKE ALL ON documents FROM ai_service_reader');
            DB::unprepared('REVOKE USAGE ON SCHEMA public FROM ai_service_reader');
        }

        Schema::dropIfExists('document_chunks');
    }

    public function down(): void
    {
        // Sin vuelta atrás automática: los fragmentos los tiene ai-service (kb:reindex --all).
    }
};
