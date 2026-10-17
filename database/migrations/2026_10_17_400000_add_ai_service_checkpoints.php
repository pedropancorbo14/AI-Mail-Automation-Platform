<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Esquema ai_service y rol ai_service_checkpoints para los checkpoints de
 * LangGraph de ai-service (contracts/database/ai_service_checkpoints.sql):
 * el grafo en pausa mientras una persona aprueba una acción.
 *
 * Como el rol del RAG, es del clúster: se crea solo si no existe y si el
 * usuario de Laravel puede crear roles (en Docker y en el CI sí; en un
 * PostgreSQL gestionado lo crea el administrador con la contraseña de
 * AI_SERVICE_CHECKPOINTS_DB_PASSWORD).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureRole();

        if (! $this->roleExists()) {
            Log::warning('ai_service_checkpoints no existe y no se puede crear: ai-service no podrá dejar acciones pendientes de aprobación hasta que lo cree un administrador.');

            return;
        }

        DB::unprepared((string) file_get_contents(base_path('contracts/database/ai_service_checkpoints.sql')));
    }

    public function down(): void
    {
        // Borra los checkpoints (ejecuciones en pausa); el rol no se borra: es del clúster.
        DB::unprepared('DROP SCHEMA IF EXISTS ai_service CASCADE');
    }

    private function ensureRole(): void
    {
        $canCreate = DB::selectOne('SELECT rolsuper OR rolcreaterole AS can FROM pg_roles WHERE rolname = current_user')?->can;

        if ($this->roleExists() || ! $canCreate) {
            return;
        }

        $password = str_replace("'", "''", (string) config('ai.remote.checkpoints_db_password'));

        // DO + excepción: los tests en paralelo migran varias BD a la vez y el rol es del clúster.
        DB::unprepared(<<<SQL
            DO \$\$
            BEGIN
                CREATE ROLE ai_service_checkpoints LOGIN PASSWORD '{$password}' NOINHERIT;
            EXCEPTION WHEN duplicate_object THEN NULL;
            END
            \$\$;
            SQL);
    }

    private function roleExists(): bool
    {
        return DB::selectOne("SELECT 1 AS found FROM pg_roles WHERE rolname = 'ai_service_checkpoints'") !== null;
    }
};
