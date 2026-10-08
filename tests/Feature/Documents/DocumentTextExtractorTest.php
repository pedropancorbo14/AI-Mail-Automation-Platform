<?php

use App\Documents\Exceptions\DocumentProcessingException;
use App\Documents\Extraction\DocumentSection;
use App\Documents\Extraction\DocumentTextExtractor;
use Tests\Support\Pdf;

function extractSections(string $content, string $mime): array
{
    return array_map(
        fn (DocumentSection $s) => [$s->text, $s->metadata],
        (new DocumentTextExtractor())->extract($content, $mime),
    );
}

it('extracts the text of each pdf page with its page number', function () {
    $pdf = Pdf::make([
        "Política de devoluciones\nTienes 30 días para devolver el producto.",
        '',
        'Garantía de dos años en electrónica.',
    ]);

    $sections = extractSections($pdf, 'application/pdf');

    expect($sections)->toHaveCount(2)
        ->and($sections[0][0])->toContain('Política de devoluciones')->toContain('30 días')
        ->and($sections[0][1])->toBe(['page' => 1])
        ->and($sections[1][0])->toContain('Garantía de dos años')
        ->and($sections[1][1])->toBe(['page' => 3]);
});

it('explains that scanned pdfs without text are not supported yet', function () {
    (new DocumentTextExtractor())->extract(Pdf::make(['', '']), 'application/pdf');
})->throws(DocumentProcessingException::class, 'escaneado');

it('reports broken pdfs clearly', function () {
    (new DocumentTextExtractor())->extract('%PDF-1.4 esto no es un pdf', 'application/pdf');
})->throws(DocumentProcessingException::class);

it('splits markdown by headings keeping the heading as source', function () {
    $markdown = "Introducción general.\n\n# Devoluciones\n\nTienes 30 días.\n\n## Productos dañados\nEnvía fotos.\n\n# Envíos\nEn 24-48 h.";

    expect(extractSections($markdown, 'text/markdown'))->toBe([
        ['Introducción general.', []],
        ["# Devoluciones\n\nTienes 30 días.", ['heading' => 'Devoluciones']],
        ["## Productos dañados\nEnvía fotos.", ['heading' => 'Productos dañados']],
        ["# Envíos\nEn 24-48 h.", ['heading' => 'Envíos']],
    ]);
});

it('joins headings without text to the next section', function () {
    $markdown = "# Preguntas frecuentes\n\n## ¿Cómo pago?\n\nCon tarjeta o Bizum.\n\n## ¿Y la factura?\nPor email.";

    expect(extractSections($markdown, 'text/markdown'))->toBe([
        ["# Preguntas frecuentes\n\n## ¿Cómo pago?\n\nCon tarjeta o Bizum.", ['heading' => '¿Cómo pago?']],
        ["## ¿Y la factura?\nPor email.", ['heading' => '¿Y la factura?']],
    ]);
});

it('converts html to plain text', function () {
    $sections = extractSections('<h1>FAQ</h1><p>¿Hacéis envíos a <b>Canarias</b>?</p><script>alert(1)</script>', 'text/html');

    expect($sections[0][0])->toContain('FAQ')->toContain('¿Hacéis envíos a Canarias?')
        ->not->toContain('<b>')->not->toContain('alert');
});

it('cleans plain text for postgres', function () {
    $sections = extractSections("Texto con NUL\0 y latin1 \xE9\r\n\r\n\r\n\r\nFin   con    espacios", 'text/plain');

    expect($sections[0][0])->toBe("Texto con NUL y latin1 ?\n\nFin con espacios");
});

it('rejects unsupported formats and empty documents', function (string $content, string $mime, string $message) {
    expect(fn () => (new DocumentTextExtractor())->extract($content, $mime))
        ->toThrow(DocumentProcessingException::class, $message);
})->with([
    'word' => ['PK...', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'no soportado'],
    'vacío' => ["  \n\n ", 'text/plain', 'vacío'],
]);

it('decides the type from the extension first', function () {
    expect(DocumentTextExtractor::mimeFor('faq.md', 'text/plain'))->toBe('text/markdown')
        ->and(DocumentTextExtractor::mimeFor('POLITICA.PDF'))->toBe('application/pdf')
        ->and(DocumentTextExtractor::mimeFor('notas', 'text/plain'))->toBe('text/plain')
        ->and(DocumentTextExtractor::mimeFor('contrato.docx', 'application/zip'))->toBeNull();
});
