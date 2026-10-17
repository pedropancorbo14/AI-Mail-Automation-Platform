<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión humana (Fase 13).
 *
 *  - email_replies: quién escribió la respuesta (null = la IA), el texto
 *    original de la IA si una persona lo editó, y quién la descartó y por qué.
 *  - emails: a quién está asignado el caso (para que dos personas no
 *    trabajen el mismo email sin saberlo).
 *  - email_reviews: una fila por cada decisión de una persona sobre un email
 *    (aprobar, descartar, responder, cerrar, reprocesar), con cuánto esperó
 *    el caso. Es el historial de la bandeja y la base de sus métricas; se
 *    borra con el email (retención y derecho de supresión).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_replies', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('agent_run_id')->constrained('users')->nullOnDelete();
            $table->longText('original_body')->nullable()->after('body');
            $table->foreignId('edited_by')->nullable()->after('original_body')->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by');
            $table->foreignId('rejected_by')->nullable()->after('send_error')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');

            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::create('email_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_reply_id')->nullable()->constrained('email_replies')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // approved | rejected | replied | closed | reprocessed (App\Automation\Review\ReviewDecision)
            $table->string('decision', 20);
            // Aprobada después de cambiar el texto de la IA.
            $table->boolean('edited')->default(false);
            $table->text('reason')->nullable();
            // Desde que el email entró en revisión humana hasta esta decisión.
            $table->unsignedInteger('waited_seconds')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_reviews');

        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'assigned_to']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn('assigned_at');
        });

        Schema::table('email_replies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('edited_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['original_body', 'edited_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
