<?php

namespace App\AI;

use App\AI\Data\LLMResponse;
use App\AI\Data\LLMUsage;

/**
 * Coste en USD de una llamada a partir de los precios de config/ai.php
 * (USD por millón de tokens). Con fallbacks, cada modelo factura sus propios
 * tokens a su tarifa. Si falta el precio de algún modelo el coste es null
 * (desconocido), nunca 0: así no parece gratis en los informes.
 */
class CostCalculator
{
    public function forResponse(LLMResponse $response): ?string
    {
        $parts = $response->usageByModel ?: [['model' => $response->model, 'usage' => $response->usage]];
        $total = 0.0;

        foreach ($parts as $part) {
            $cost = $this->microUsd($part['model'], $part['usage']);

            if ($cost === null) {
                return null;
            }

            $total += $cost;
        }

        return self::format($total);
    }

    public function forUsage(string $model, LLMUsage $usage): ?string
    {
        $cost = $this->microUsd($model, $usage);

        return $cost === null ? null : self::format($cost);
    }

    /** Millonésimas de dólar: tokens × (USD por millón de tokens). */
    private function microUsd(string $model, LLMUsage $usage): ?float
    {
        $price = config("ai.pricing.{$model}");

        if (! is_array($price)) {
            return null;
        }

        return $usage->inputTokens * $price['input']
            + $usage->outputTokens * $price['output']
            + $usage->cacheWriteTokens * $price['cache_write']
            + $usage->cacheReadTokens * $price['cache_read'];
    }

    /** USD con 6 decimales (columna decimal(12,6)). */
    private static function format(float $microUsd): string
    {
        return number_format($microUsd / 1_000_000, 6, '.', '');
    }
}
