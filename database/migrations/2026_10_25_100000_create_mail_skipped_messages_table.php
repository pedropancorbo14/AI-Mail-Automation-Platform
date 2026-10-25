<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Correo que falla siempre al guardarlo (mensaje "envenenado"): cuál es
        // y cuántas veces seguidas ha fallado. Al llegar al límite se aparta.
        Schema::table('mail_sync_states', function (Blueprint $table) {
            $table->string('failing_message_id')->nullable()->after('consecutive_failures');
            $table->unsignedInteger('failing_message_attempts')->default(0)->after('failing_message_id');
        });

        // Correos apartados: la sincronización los salta y sigue con el resto.
        // El correo sigue en el buzón del cliente (nunca se modifica); aquí solo
        // lo necesario para encontrarlo. Se borra con el buzón, con la retención
        // y con el derecho de supresión (from_email).
        Schema::create('mail_skipped_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('mail_connection_id')->constrained()->cascadeOnDelete();
            $table->string('folder');
            $table->string('provider_message_id');
            $table->string('message_id', 998)->nullable();
            $table->string('from_email')->nullable();
            $table->string('subject', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error');
            $table->unsignedInteger('attempts');
            $table->timestamp('created_at')->nullable();

            $table->unique(['mail_connection_id', 'folder', 'provider_message_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_skipped_messages');

        Schema::table('mail_sync_states', function (Blueprint $table) {
            $table->dropColumn(['failing_message_id', 'failing_message_attempts']);
        });
    }
};
