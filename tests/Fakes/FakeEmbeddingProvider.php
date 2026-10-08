<?php

namespace Tests\Fakes;

use App\AI\Embeddings\EmbeddingInputType;
use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Embeddings\EmbeddingResult;
use Illuminate\Support\Str;
use Throwable;

/**
 * Embeddings falsos y deterministas para tests: bolsa de palabras con
 * hashing en N dimensiones, normalizada (como los reales). Textos que
 * comparten palabras se parecen, así que la búsqueda semántica de los tests
 * tiene sentido sin Ollama.
 */
class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var list<array{texts: list<string>, type: EmbeddingInputType}> */
    public array $calls = [];

    /** Si se define, embed() lanza esta excepción. */
    public ?Throwable $failWith = null;

    public function __construct(private readonly int $dimensions = 1024)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'bge-m3';
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(array $texts, EmbeddingInputType $type): EmbeddingResult
    {
        $this->calls[] = ['texts' => $texts, 'type' => $type];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return new EmbeddingResult(
            vectors: array_map(fn (string $text) => $this->vector($text), $texts),
            model: $this->model(),
            tokens: array_sum(array_map(fn (string $text) => (int) ceil(mb_strlen($text) / 4), $texts)),
            latencyMs: 120,
        );
    }

    /** @return list<float> */
    public function vector(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text)), flags: PREG_SPLIT_NO_EMPTY);

        foreach ($words as $word) {
            if (mb_strlen($word) < 3) {
                continue;   // ignora "de", "el", "la"...
            }

            $vector[crc32($word) % $this->dimensions] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector)));

        // Texto sin palabras útiles: vector unitario fijo (pgvector no admite el vector cero en coseno).
        if ($norm == 0.0) {
            $vector[0] = 1.0;

            return $vector;
        }

        return array_map(fn (float $v) => $v / $norm, $vector);
    }
}
