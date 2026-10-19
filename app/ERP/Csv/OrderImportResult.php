<?php

namespace App\ERP\Csv;

final readonly class OrderImportResult
{
    public function __construct(
        public int $rows,
        public int $created,
        public int $updated,
    ) {
    }

    public function toArray(): array
    {
        return ['rows' => $this->rows, 'created' => $this->created, 'updated' => $this->updated];
    }
}
