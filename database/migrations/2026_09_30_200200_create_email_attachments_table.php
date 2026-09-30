<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // Ojo: el cascade borra la fila, no el fichero del disco (limpieza en Fase 12).
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();

            // Id del adjunto dentro del mensaje en el proveedor (para descargarlo).
            $table->string('provider_attachment_id');
            // Nombre que puso el remitente: solo para mostrar, NUNCA como ruta.
            $table->string('filename');
            $table->string('content_type');
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('inline')->default(false);
            $table->string('content_id')->nullable();

            // pending | stored | skipped | failed (App\Mail\Enums\AttachmentStatus)
            $table->string('status', 20)->default('pending');
            // Fichero en disco privado (null mientras no se haya guardado).
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->char('sha256', 64)->nullable();
            // Motivo de skipped/failed (demasiado grande, error del proveedor...).
            $table->text('error')->nullable();

            $table->timestamps();

            $table->unique(['email_id', 'provider_attachment_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
