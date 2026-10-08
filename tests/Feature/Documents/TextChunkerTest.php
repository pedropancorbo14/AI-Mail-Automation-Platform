<?php

use App\Documents\Chunking\TextChunk;
use App\Documents\Chunking\TextChunker;
use App\Documents\Extraction\DocumentSection;

function chunkTexts(array $chunks): array
{
    return array_map(fn (TextChunk $c) => $c->text, $chunks);
}

it('keeps a short section as a single chunk with its source', function () {
    $chunks = (new TextChunker(500, 50))->chunk([new DocumentSection("Tienes 30 días.\n\nEnvía fotos si llegó roto.", ['page' => 2])]);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->text)->toBe("Tienes 30 días.\n\nEnvía fotos si llegó roto.")
        ->and($chunks[0]->metadata)->toBe(['page' => 2])
        ->and($chunks[0]->estimatedTokens)->toBe(11);
});

it('packs whole paragraphs without exceeding the size', function () {
    $paragraphs = array_map(fn ($i) => "Párrafo {$i}. " . str_repeat('palabra ', 25), range(1, 10));
    $chunks = (new TextChunker(500, 0))->chunk([new DocumentSection(implode("\n\n", $paragraphs))]);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->text))->toBeLessThanOrEqual(500)
            ->and($chunk->text)->toStartWith('Párrafo');
    }

    // Sin solapamiento, juntos reconstruyen todos los párrafos en orden.
    expect(implode("\n\n", chunkTexts($chunks)))->toBe(implode("\n\n", array_map('trim', $paragraphs)));
});

it('repeats the end of the previous chunk at the start of the next one', function () {
    $paragraphs = ['Primera parte ' . str_repeat('alfa ', 60) . 'FINAL-UNO.', 'Segunda parte ' . str_repeat('beta ', 60)];
    $chunks = (new TextChunker(400, 60))->chunk([new DocumentSection(implode("\n\n", $paragraphs))]);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[1]->text)->toContain('FINAL-UNO.')
        ->and($chunks[1]->text)->toContain('Segunda parte')
        ->and(mb_strlen($chunks[1]->text))->toBeLessThanOrEqual(400);
});

it('splits huge paragraphs by sentences and huge sentences by words', function () {
    $sentences = implode(' ', array_map(fn ($i) => "Esta es la frase número {$i} del párrafo enorme.", range(1, 40)));
    $giantSentence = str_repeat('palabrasinpuntos ', 100);

    $chunks = (new TextChunker(300, 0))->chunk([new DocumentSection($sentences . "\n\n" . $giantSentence)]);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->text))->toBeLessThanOrEqual(300);
    }
    expect($chunks[0]->text)->toStartWith('Esta es la frase número 1 ')
        ->and(collect($chunks)->contains(fn ($c) => str_contains($c->text, 'número 40 del párrafo enorme.')))->toBeTrue();
});

it('cuts words longer than a chunk', function () {
    $chunks = (new TextChunker(100, 0))->chunk([new DocumentSection(str_repeat('x', 250))]);

    expect(array_map(fn ($c) => mb_strlen($c->text), $chunks))->toBe([100, 100, 50]);
});

it('never mixes sections so citations stay exact', function () {
    $chunks = (new TextChunker(500, 50))->chunk([
        new DocumentSection('Contenido de la página 1.', ['page' => 1]),
        new DocumentSection('Contenido de la página 2.', ['page' => 2]),
        new DocumentSection('# Envíos', ['heading' => 'Envíos']),
    ]);

    expect(array_map(fn ($c) => [$c->text, $c->metadata], $chunks))->toBe([
        ['Contenido de la página 1.', ['page' => 1]],
        ['Contenido de la página 2.', ['page' => 2]],
        ['# Envíos', ['heading' => 'Envíos']],
    ]);
});

it('counts characters, not bytes, with accents and emojis', function () {
    $chunks = (new TextChunker(100, 0))->chunk([new DocumentSection(str_repeat('ñ', 150))]);

    expect(array_map(fn ($c) => mb_strlen($c->text), $chunks))->toBe([100, 50]);
});

it('uses the configured size', function () {
    config(['ai.rag.chunk_max_chars' => 100, 'ai.rag.chunk_overlap_chars' => 0]);

    $chunks = app(TextChunker::class)->chunk([new DocumentSection(str_repeat('x', 250))]);

    expect(count($chunks))->toBe(3)
        ->and(config('ai.rag.chunk_overlap_chars'))->toBe(0);
});

it('rejects nonsense sizes', function () {
    new TextChunker(1000, 600);
})->throws(InvalidArgumentException::class);
