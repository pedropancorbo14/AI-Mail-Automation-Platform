<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estado de sincronización de cada carpeta de cada buzón.
        Schema::create('mail_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // Sin el buzón el cursor no sirve de nada: se borra con él.
            $table->foreignId('mail_connection_id')->constrained()->cascadeOnDelete();
            $table->string('folder');

            // Cursor opaco de MailProvider::newMessages(). Null = aún sin punto de partida.
            $table->text('cursor')->nullable();

            $table->timestamp('last_synced_at')->nullable();   // último intento
            $table->timestamp('last_success_at')->nullable();  // último intento correcto
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            // Última vez que el proveedor invalidó el cursor (puede faltar correo intermedio).
            $table->timestamp('resynced_at')->nullable();

            $table->timestamps();

            $table->unique(['mail_connection_id', 'folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sync_states');
    }
};
