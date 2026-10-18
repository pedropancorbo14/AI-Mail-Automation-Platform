<?php

namespace App\Console\Commands;

use App\AI\Remote\AiServiceClient;
use App\AI\Remote\Exceptions\AiServiceUnavailableException;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Prueba la búsqueda semántica de ai-service en la documentación de una empresa. */
#[Signature('kb:search
    {query : Pregunta a buscar}
    {--tenant= : Id de la empresa}
    {--limit= : Número de resultados}')]
#[Description('Busca por significado en la base de conocimiento de una empresa')]
class KnowledgeSearch extends Command
{
    public function handle(AiServiceClient $client): int
    {
        $tenant = Tenant::find($this->option('tenant'));

        if ($tenant === null) {
            $this->error('Indica una empresa existente con --tenant=<id>.');

            return self::FAILURE;
        }

        // Con el umbral de similitud de ai-service.
        try {
            $results = $client->searchKnowledge(
                $tenant,
                (string) $this->argument('query'),
                $this->option('limit') !== null ? (int) $this->option('limit') : null,
            );
        } catch (AiServiceUnavailableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->show(array_map(fn (array $r) => [$r['similarity'], $r['source'], $r['content']], $results));
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
