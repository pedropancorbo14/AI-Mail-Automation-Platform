<?php

namespace App\Documents\Enums;

enum DocumentStatus: string
{
    /** Subido, esperando a procesarse. */
    case Pending = 'pending';

    /** Extrayendo texto, troceando y calculando embeddings. */
    case Processing = 'processing';

    /** Indexado: sus trozos aparecen en las búsquedas. */
    case Ready = 'ready';

    /** No se pudo procesar (ver error). */
    case Failed = 'failed';
}
