<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guardar en "Enviados" (IMAP APPEND) lo que la plataforma envía por
        // SMTP. Desactivado por defecto: hay servidores SMTP que ya guardan la
        // copia solos y saldría duplicada. Gmail y Microsoft la guardan siempre.
        Schema::table('mail_connections', function (Blueprint $table) {
            $table->boolean('save_sent_copy')->default(false)->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('mail_connections', function (Blueprint $table) {
            $table->dropColumn('save_sent_copy');
        });
    }
};
