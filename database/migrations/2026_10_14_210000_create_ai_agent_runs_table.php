<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada vez que el agente trabaja un email: el resumen de lo que hizo.
        // El detalle está en sus ai_requests (un paso cada una) y ai_tool_calls.
        Schema::create('ai_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('email_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_prompt_version_id')->nullable()->constrained()->restrictOnDelete();

            // running | completed | failed (App\AI\Agent\Enums\AgentRunStatus)
            $table->string('status', 20)->default('running');
            // end_turn | max_iterations | max_tool_calls | timeout | budget | refusal | error
            $table->string('stop_reason', 30)->nullable();
            $table->text('error')->nullable();

            $table->unsignedSmallInteger('iterations')->default(0);
            $table->unsignedSmallInteger('tool_calls_count')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Respuesta final del modelo (JSON del esquema), para auditar.
            $table->jsonb('final_output')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index('email_id');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->foreignId('agent_run_id')->nullable()->after('email_id')->constrained('ai_agent_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('agent_run_id'));
        Schema::dropIfExists('ai_agent_runs');
    }
};
