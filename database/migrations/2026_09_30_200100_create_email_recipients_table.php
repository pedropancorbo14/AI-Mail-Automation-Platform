<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_recipients', function (Blueprint $table) {
            $table->id();
            // Redundante con emails.tenant_id a propósito: así las consultas
            // directas a esta tabla también se filtran por empresa (BelongsToTenant).
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();

            // to | cc | bcc | reply_to (App\Mail\Enums\RecipientType)
            $table->string('type', 10);
            $table->string('email');
            $table->string('name')->nullable();
            // Orden dentro de la cabecera (el primer "to" suele ser el principal).
            $table->unsignedSmallInteger('position')->default(0);

            $table->index('email_id');
            // Buscar los correos en los que aparece una dirección.
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_recipients');
    }
};
