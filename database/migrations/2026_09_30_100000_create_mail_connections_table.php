<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            // imap | microsoft | google (App\Mail\Enums\MailProviderType)
            $table->string('provider', 20);
            $table->string('email');

            // JSON cifrado con la APP_KEY (host/usuario/contraseña o tokens OAuth).
            // text: el cifrado aumenta el tamaño y los tokens OAuth son largos.
            $table->text('credentials');

            // pending | active | failing | disabled
            $table->string('status', 20)->default('pending');
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            // Una empresa no puede conectar dos veces la misma cuenta.
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_connections');
    }
};
