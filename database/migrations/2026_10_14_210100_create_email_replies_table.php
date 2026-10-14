<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Propuestas de respuesta a un email (las prepara el agente). Su envío
        // o revisión humana es cosa de las Fases 9 y 13.
        Schema::create('email_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained('ai_agent_runs')->nullOnDelete();

            $table->longText('body');
            $table->string('language', 10)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('needs_human')->default(true);
            $table->text('needs_human_reason')->nullable();
            // [{type: document|order, reference}]
            $table->jsonb('sources')->nullable();
            // Acciones que debe hacer una persona (aprobar reembolso...).
            $table->jsonb('pending_actions')->nullable();
            $table->text('internal_note')->nullable();

            // draft | approved | rejected | sent (App\AI\Agent\Enums\EmailReplyStatus)
            $table->string('status', 20)->default('draft');

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_replies');
    }
};
