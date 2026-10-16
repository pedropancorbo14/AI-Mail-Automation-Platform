<?php

namespace App\Security\Logging;

use App\Security\Redactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * "tap" de los canales de log (config/logging.php): antes de escribir,
 * oculta los secretos del mensaje, del contexto y de los extra (Context de
 * Laravel). Las excepciones del contexto no se tocan (su mensaje lo
 * escribe nuestro código, que ya no incluye credenciales), pero sus trazas
 * ya no llevan argumentos (zend.exception_ignore_args en bootstrap/app.php).
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(fn (LogRecord $record) => $record->with(
            message: Redactor::redactString($record->message),
            context: Redactor::redact($record->context),
            extra: Redactor::redact($record->extra),
        ));
    }
}
