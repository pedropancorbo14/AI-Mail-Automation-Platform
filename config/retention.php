<?php

/*
 * Retención de datos (Fase 12). El borrado lo hace "php artisan data:prune"
 * cada noche (routes/console.php).
 */

return [
    // Días que se conservan los emails (con sus adjuntos, respuestas e
    // historial; de los registros de IA se borra el contenido y se quedan
    // tokens y costes). Cada empresa puede elegir otro valor entre los
    // límites de abajo. 0 = sin límite.
    'emails_days' => (int) env('RETENTION_EMAILS_DAYS', 365),
    'min_email_days' => 30,
    'max_email_days' => 3650,

    // Días que se conserva el registro de auditoría. 0 = sin límite.
    'audit_days' => (int) env('RETENTION_AUDIT_DAYS', 730),
];
