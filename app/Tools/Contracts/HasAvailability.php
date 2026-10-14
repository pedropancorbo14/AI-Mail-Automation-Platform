<?php

namespace App\Tools\Contracts;

/**
 * Herramientas que dependen de que la empresa tenga configurada una fuente
 * de datos (p. ej. pedidos): si no la tiene, no se ofrecen a la IA aunque
 * estén activadas. Se evalúa dentro del tenant de la empresa.
 */
interface HasAvailability
{
    public function isAvailable(): bool;
}
