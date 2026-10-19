<?php

namespace App\ERP\Csv;

use RuntimeException;

/**
 * El CSV no se puede importar. Todo o nada: con un solo error no se guarda
 * ninguna fila. `$rows` = errores por fila ([fila, [mensajes]]) para que la
 * persona los corrija en su Excel; `$totalRowErrors` cuenta también los que
 * no se devuelven (se recortan a integrations.csv.max_reported_errors).
 */
class OrderImportException extends RuntimeException
{
    /** @param list<array{row: int, errors: list<string>}> $rows */
    public function __construct(
        string $message,
        public readonly array $rows = [],
        public readonly int $totalRowErrors = 0,
    ) {
        parent::__construct($message);
    }
}
