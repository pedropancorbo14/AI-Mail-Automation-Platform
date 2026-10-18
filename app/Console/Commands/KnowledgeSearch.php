<?php

namespace App\Console\Commands;

use App\AI\Exceptions\LLMException;
use App\AI\Remote\AIEngine;
use App\AI\Remote\AiServiceClient;
use App\AI\Remote\Exceptions\AiServiceUnavailableException;
use App\Documents\Search\SearchResult;
use App\Documents\Search\SemanticSearchService;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Prueba la búsqueda semántica en la documentación de una empresa. */
#[Signature('kb:search
    {query : Pregunta a buscar}
    {--tenant= : Id de la empresa}
    {--limit= : Número de resultados}
    {--min= : Similitud mínima (0–1)}')]
#[Description('Busca por significado en la base de conocimiento de una empresa')]
class KnowledgeSearch extends Command
{
    public function handle(TenantService $tenants, SemanticSearchService $search): int
    {
        $tenant = Tenant::find($this->option('tenant'));

        if ($tenant === null) {
            $this->error('Indica una empresa existente con --tenant=<id>.');

            return self::FAILURE;
        }

        if (AIEngine::current()->isRemote()) {
            // La búsqueda es de ai-service (su umbral de similitud, no --min).
            try {
                $remote = app(AiServiceClient::class)->searchKnowledge(
                    $tenant,
                    (string) $this->argument('query'),
                    $this->option('limit') !== null ? (int) $this->option('limit') : null,
                );
            } catch (AiServiceUnavailableException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            return $this->show(array_map(fn (array $r) => [$r['similarity'], $r['source'], $r['content']], $remote));
        }

        try {
            $results = $tenants->runAs($tenant, fn () => $search->search(
                (string) $this->argument('query'),
                $this->option('limit') !== null ? (int) $this->option('limit') : null,
                $this->option('min') !== null ? (float) $this->option('min') : null,
            ));
        } catch (LLMException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->show(array_map(fn (SearchResult $r) => [$r->similarity, $r->source(), $r->content], $results));
    }

    /** @param  list<array{0: float, 1: string, 2: string}>  $rows */
    private function show(array $rows): int
    {
        if ($rows === []) {
            $this->warn('Sin resultados por encima de la similitud mínima.');

            return self::SUCCESS;
        }

        $this->table(['Similitud', 'Fuente', 'Texto'], array_map(fn (array $r) => [
            number_format($r[0], 3),
            $r[1],
            Str::limit(preg_replace('/\s+/', ' ', $r[2]), 120),
        ], $rows));

        return self::SUCCESS;
    }
}
