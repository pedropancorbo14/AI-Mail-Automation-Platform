<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de auditoría (Fase 12): qué hizo cada persona en la plataforma,
 * cuándo y desde dónde. Solo se escribe: un trigger impide modificar
 * filas (borrar solo lo hace la retención de datos).
 *
 * user_id sin clave foránea a propósito: con nullOnDelete, borrar un
 * usuario modificaría sus registros antiguos (y el trigger lo impediría).
 * Se guarda además una copia del email de quien actuó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Null = sin empresa conocida (p. ej. login fallido con un email que no existe).
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            // {"campo": {"from": ..., "to": ...}} — nunca secretos.
            $table->jsonb('changes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'action']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs es de solo escritura: sus filas no se pueden modificar';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_immutable();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_immutable();');
        Schema::dropIfExists('audit_logs');
    }
};
