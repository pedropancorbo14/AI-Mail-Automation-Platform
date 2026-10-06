<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clasificación actual del email (la última). El historial de cada
        // intento, con su coste, está en ai_requests.
        Schema::table('emails', function (Blueprint $table) {
            $table->string('intent', 50)->nullable()->after('status');      // App\AI\Classification\EmailIntent
            $table->decimal('intent_confidence', 4, 3)->nullable()->after('intent');
            $table->string('language', 10)->nullable()->after('intent_confidence');
            $table->text('summary')->nullable()->after('language');
            $table->boolean('needs_human')->nullable()->after('summary');
            $table->text('needs_human_reason')->nullable()->after('needs_human');
            $table->string('sentiment', 20)->nullable()->after('needs_human_reason');
            $table->string('urgency', 20)->nullable()->after('sentiment');
            $table->boolean('injection_suspected')->default(false)->after('urgency');
            // Datos extraídos: order_numbers, invoice_numbers, customer_name.
            $table->jsonb('ai_entities')->nullable()->after('injection_suspected');
            $table->foreignId('classification_ai_request_id')->nullable()->after('ai_entities')
                ->constrained('ai_requests')->nullOnDelete();
            $table->timestamp('classified_at')->nullable()->after('classification_ai_request_id');

            $table->index(['tenant_id', 'intent']);
            $table->index(['tenant_id', 'needs_human']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'intent']);
            $table->dropIndex(['tenant_id', 'needs_human']);
            $table->dropConstrainedForeignId('classification_ai_request_id');
            $table->dropColumn([
                'intent', 'intent_confidence', 'language', 'summary', 'needs_human', 'needs_human_reason',
                'sentiment', 'urgency', 'injection_suspected', 'ai_entities', 'classified_at',
            ]);
        });
    }
};
