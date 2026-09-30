<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // Un buzón con correos guardados no se borra sin decidir antes qué
            // pasa con ellos (retención: Fase 12). Se puede desactivar.
            $table->foreignId('mail_connection_id')->constrained()->restrictOnDelete();

            // Dónde está en el proveedor (carpeta + UID IMAP / id Graph / id Gmail).
            $table->string('folder');
            $table->string('provider_message_id');

            // Cabeceras de hilo (sin < >). El Message-ID puede faltar.
            $table->string('message_id', 998)->nullable();
            $table->string('in_reply_to', 998)->nullable();
            $table->jsonb('references')->nullable();

            // SHA-256 del Message-ID, o de una huella del correo si no lo tiene.
            // Evita guardar dos veces el mismo correo de un buzón.
            $table->char('dedup_hash', 64);

            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->text('subject');
            $table->longText('text_body')->nullable();
            // HTML tal cual llega: SIEMPRE sanearlo antes de mostrarlo (Fase 10).
            $table->longText('html_body')->nullable();
            // Fecha de la cabecera Date (la pone el remitente).
            $table->timestamp('sent_at')->nullable();

            // Ciclo de vida (App\Mail\Enums\EmailStatus): received → processing →
            // ai_review → human_review → approved → sent | failed.
            $table->string('status', 20)->default('received');
            // Ingesta terminada (correo + adjuntos guardados). Null = a medias.
            $table->timestamp('ingested_at')->nullable();

            $table->timestamps();

            $table->unique(['mail_connection_id', 'dedup_hash']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sent_at']);
            $table->index(['tenant_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
