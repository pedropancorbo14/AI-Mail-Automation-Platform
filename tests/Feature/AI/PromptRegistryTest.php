<?php

use App\AI\Prompts\Prompt;
use App\AI\Prompts\PromptRegistry;
use App\Models\AiPromptVersion;

function testPrompt(int $version = 1, string $system = 'Clasifica el email.', ?array $schema = ['type' => 'object']): Prompt
{
    return new class($version, $system, $schema) implements Prompt
    {
        public function __construct(private int $v, private string $s, private ?array $schema)
        {
        }

        public function key(): string
        {
            return 'test_prompt';
        }

        public function version(): int
        {
            return $this->v;
        }

        public function system(): string
        {
            return $this->s;
        }

        public function outputSchema(): ?array
        {
            return $this->schema;
        }
    };
}

it('stores a prompt version the first time it is used', function () {
    $version = (new PromptRegistry())->resolve(testPrompt());

    expect($version->fresh()->only('key', 'version', 'system_prompt', 'output_schema'))->toBe([
        'key' => 'test_prompt',
        'version' => 1,
        'system_prompt' => 'Clasifica el email.',
        'output_schema' => ['type' => 'object'],
    ]);
});

it('reuses the stored version', function () {
    $first = (new PromptRegistry())->resolve(testPrompt());
    $again = (new PromptRegistry())->resolve(testPrompt());

    expect($again->id)->toBe($first->id)
        ->and(AiPromptVersion::count())->toBe(1);
});

it('keeps every version for the audit', function () {
    (new PromptRegistry())->resolve(testPrompt(1));
    (new PromptRegistry())->resolve(testPrompt(2, 'Clasifica el email con más detalle.'));

    expect(AiPromptVersion::orderBy('version')->pluck('version')->all())->toBe([1, 2]);
});

it('fails when a prompt changes without a new version', function () {
    (new PromptRegistry())->resolve(testPrompt(1));

    (new PromptRegistry())->resolve(testPrompt(1, 'Texto cambiado a escondidas.'));
})->throws(LogicException::class, 'sin subir de versión');

it('also detects schema changes', function () {
    (new PromptRegistry())->resolve(testPrompt(1));

    (new PromptRegistry())->resolve(testPrompt(1, schema: ['type' => 'object', 'required' => ['intent']]));
})->throws(LogicException::class);
