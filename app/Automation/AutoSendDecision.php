<?php

namespace App\Automation;

/** Resultado de AutoSendPolicy: si la respuesta puede salir sin persona y, si no, por qué. */
final readonly class AutoSendDecision
{
    /** @param  list<string>  $reasons  Motivos para que la revise una persona (vacío = puede enviarse). */
    public function __construct(public array $reasons)
    {
    }

    public function allowed(): bool
    {
        return $this->reasons === [];
    }

    public function reason(): string
    {
        return implode(' ', $this->reasons);
    }
}
