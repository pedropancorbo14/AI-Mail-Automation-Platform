<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación y envío de una respuesta (Fase 9): quién la aprobó (null = las
 * reglas de envío automático), a quién y cuándo se envió, Message-ID
 * asignado (para reconocer respuestas del cliente a ella) y el error si no
 * se pudo enviar. Estados nuevos: sending y failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_replies', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->boolean('sent_automatically')->default(false)->after('approved_at');
            $table->string('sent_to')->nullable()->after('sent_automatically');
            $table->string('sent_message_id', 998)->nullable()->after('sent_to');
            $table->timestamp('sent_at')->nullable()->after('sent_message_id');
            $table->text('send_error')->nullable()->after('sent_at');

            // Límite anti-bucles: respuestas automáticas a una dirección en las últimas 24 h.
            $table->index(['tenant_id', 'sent_to', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('email_replies', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'sent_to', 'sent_at']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'sent_automatically', 'sent_to', 'sent_message_id', 'sent_at', 'send_error']);
        });
    }
};
