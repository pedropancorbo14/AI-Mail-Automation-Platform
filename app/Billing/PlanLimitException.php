<?php

namespace App\Billing;

use RuntimeException;

/**
 * La empresa ya tiene lo que permite su plan (buzones, usuarios,
 * documentos). La API la convierte en un 402 con el motivo.
 */
class PlanLimitException extends RuntimeException
{
    public function __construct(public readonly PlanLimit $limit, public readonly Plan $plan, public readonly int|float $max)
    {
        parent::__construct(sprintf(
            'Tu plan «%s» permite %s %s. Cambia de plan en «Plan y uso» para añadir más.',
            $plan->name,
            $max,
            mb_strtolower($limit->label()),
        ));
    }
}
