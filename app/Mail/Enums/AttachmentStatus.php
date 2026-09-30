<?php

namespace App\Mail\Enums;

enum AttachmentStatus: string
{
    /** Metadatos guardados, fichero aún sin descargar. */
    case Pending = 'pending';

    /** Fichero guardado en disco (disk/path/sha256). */
    case Stored = 'stored';

    /** No se descarga a propósito (p. ej. supera el tamaño máximo). Ver error. */
    case Skipped = 'skipped';

    /** El proveedor no lo pudo entregar (no reintentable). Ver error. */
    case Failed = 'failed';
}
