<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rol de solo lectura de ai-service sobre la base de conocimiento, con RLS
 * por empresa (contracts/database/knowledge_base_reader.sql).
 *
 * El rol es del clúster de PostgreSQL (no de la BD): se crea solo si no
 * existe y si el usuario de Laravel puede crear roles (en Docker y en el CI
 * sí; en un PostgreSQL gestionado lo crea el administrador con la misma
 * contraseña de AI_SERVICE_DB_PASSWORD). Permisos y RLS se aplican siempre
 * que el rol exista.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureRole();

        if (! $this->roleExists()) {
            Log::warning('ai_service_reader no existe y no se puede crear: ai-service no podrá usar el RAG hasta que lo cree un administrador.');

            return;
        }

        DB::unprepared((string) file_get_contents(base_path('contracts/database/knowledge_base_reader.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS ai_service_tenant_isolation ON documents;
            DROP POLICY IF EXISTS ai_service_tenant_isolation ON document_chunks;
            ALTER TABLE documents DISABLE ROW LEVEL SECURITY;
            ALTER TABLE document_chunks DISABLE ROW LEVEL SECURITY;
            SQL);

        if ($this->roleExists()) {
            DB::unprepared('REVOKE ALL ON documents, document_chunks FROM ai_service_reader');
        }
        // El rol no se borra: es del clúster y otras bases de datos pueden usarlo.
    }

    private function ensureRole(): void
    {
        $canCreate = DB::selectOne('SELECT rolsuper OR rolcreaterole AS can FROM pg_roles WHERE rolname = current_user')?->can;

        if ($this->roleExists() || ! $canCreate) {
            return;
        }

        $password = str_replace("'", "''", (string) config('ai.remote.db_password'));

        // DO + excepción: los tests en paralelo migran varias BD a la vez y el rol es del clúster.
        DB::unprepared(<<<SQL
            DO \$\$
            BEGIN
                CREATE ROLE ai_service_reader LOGIN PASSWORD '{$password}' NOINHERIT;
            EXCEPTION WHEN duplicate_object THEN NULL;
            END
            \$\$;
            SQL);
    }

    private function roleExists(): bool
    {
        return DB::selectOne("SELECT 1 AS found FROM pg_roles WHERE rolname = 'ai_service_reader'") !== null;
    }
};
