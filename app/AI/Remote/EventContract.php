<?php

namespace App\AI\Remote;

use App\AI\Remote\Exceptions\InvalidEventException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Valida eventos contra los JSON Schema compartidos con ai-service
 * (/contracts/events). Los mismos esquemas y ejemplos los usan los tests de
 * los dos lados: si uno cambia sin el otro, falla el CI.
 */
class EventContract
{
    private const ID_PREFIX = 'https://contracts.ai-mail-automation.local/events/';

    private ?Validator $validator = null;

    public function __construct(private readonly ?string $directory = null) {
    }

    /**
     * @param  array<string, mixed>|string  $payload  Evento (array o JSON).
     * @return array<string, mixed> El evento como array, ya validado.
     *
     * @throws InvalidEventException
     */
    public function validate(string $event, array|string $payload): array
    {
        $data = is_string($payload) ? json_decode($payload) : json_decode((string) json_encode($payload));

        if (! is_object($data)) {
            throw new InvalidEventException("{$event}: no es un objeto JSON.");
        }

        $result = $this->validator()->validate($data, self::ID_PREFIX . "{$event}.schema.json");

        if (! $result->isValid()) {
            $errors = (new ErrorFormatter)->format($result->error(), false);
            $first = array_key_first($errors);

            throw new InvalidEventException(sprintf('%s no cumple el contrato: %s %s', $event, $first ?: '/', $errors[$first] ?? ''));
        }

        return json_decode((string) json_encode($data), true);
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator;
            $this->validator->setMaxErrors(1);
            $directory = $this->directory ?? base_path('contracts/events');

            foreach (glob("{$directory}/*.schema.json") ?: [] as $file) {
                $this->validator->resolver()->registerFile(self::ID_PREFIX . basename($file), $file);
            }
        }

        return $this->validator;
    }
}
