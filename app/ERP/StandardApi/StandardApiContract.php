<?php

namespace App\ERP\StandardApi;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Valida respuestas de la API estándar contra los JSON Schema compartidos
 * con ai-service (contracts/integrations/schemas): los mismos esquemas y
 * ejemplos los usan los tests de los dos lados.
 */
class StandardApiContract
{
    private const ID_PREFIX = 'https://contracts.ai-mail-automation.local/integrations/';

    private ?Validator $validator = null;

    /** Primer error del JSON frente al esquema (`order`, `order-list`…), o null si es válido. */
    public function error(string $schema, mixed $data): ?string
    {
        $result = $this->validator()->validate(
            is_string($data) ? json_decode($data) : json_decode((string) json_encode($data)),
            self::ID_PREFIX . "{$schema}.v1.schema.json",
        );

        if ($result->isValid()) {
            return null;
        }

        $errors = (new ErrorFormatter)->format($result->error(), false);
        $first = array_key_first($errors);

        return trim(($first ?: '/') . ' ' . ($errors[$first] ?? ''));
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator;
            $this->validator->setMaxErrors(1);

            foreach (glob(base_path('contracts/integrations/schemas') . '/*.schema.json') ?: [] as $file) {
                $this->validator->resolver()->registerFile(self::ID_PREFIX . basename($file), $file);
            }
        }

        return $this->validator;
    }
}
