<?php

namespace Database\Seeders;

use App\Documents\DocumentService;
use App\Models\Document;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Documentación de ejemplo de "Empresa Demo" (devoluciones, envíos,
 * garantía, pagos) para probar el RAG en local. Idempotente: no vuelve a
 * subir los que ya estén. El indexado va en cola (necesita Ollama con
 * bge-m3 y Horizon). Se ejecuta aparte, no desde DatabaseSeeder (que
 * desactiva los eventos de modelo y BelongsToTenant los necesita):
 *
 *   php artisan db:seed --class=KnowledgeBaseSeeder
 */
class KnowledgeBaseSeeder extends Seeder
{
    public function run(TenantService $tenants, DocumentService $documents): void
    {
        $tenant = Tenant::where('slug', 'empresa-demo')->firstOrFail();

        $tenants->runAs($tenant, function () use ($documents) {
            foreach (glob(database_path('seeders/knowledge/*.md')) as $path) {
                $content = file_get_contents($path);

                if (Document::where('sha256', hash('sha256', $content))->exists()) {
                    continue;
                }

                // Título = el encabezado principal del documento.
                $title = preg_match('/^#\s+(.+)$/m', $content, $match) ? trim($match[1]) : Str::headline(basename($path, '.md'));
                $documents->upload($content, basename($path), $title);
                $this->command?->info("Subido: {$title}");
            }
        });
    }
}
