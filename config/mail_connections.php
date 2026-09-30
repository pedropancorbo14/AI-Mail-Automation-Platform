<?php

return [

    /*
    | Anti-SSRF: por defecto no se permite conectar a servidores de correo que
    | resuelvan a IPs privadas, loopback o reservadas (red interna, Redis,
    | Postgres, metadatos de la nube 169.254.169.254...). Solo se activa en
    | desarrollo para usar servidores locales como GreenMail.
    */
    'allow_private_hosts' => (bool) env('MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS', false),

    // Segundos de espera al conectar con un servidor IMAP/SMTP.
    'timeout' => (int) env('MAIL_CONNECTIONS_TIMEOUT', 15),

    // Tamaño máximo de un adjunto descargado (MB): protege la memoria de los workers.
    'max_attachment_mb' => (int) env('MAIL_CONNECTIONS_MAX_ATTACHMENT_MB', 25),

    // Disco (config/filesystems.php) donde se guardan los adjuntos recibidos.
    // Debe ser PRIVADO: "local" (storage/app/private) o un bucket S3 sin acceso público.
    'attachments_disk' => env('MAIL_ATTACHMENTS_DISK', 'local'),

    /*
    | OAuth (Microsoft/Google): URL del panel a la que volver tras autorizar
    | (se añade ?status=connected&connection_id=... o ?status=error&message=...).
    | Vacío = el callback responde JSON (útil mientras no existe el panel).
    */
    'oauth_redirect_to' => env('MAIL_CONNECTIONS_OAUTH_REDIRECT_TO'),

    // Minutos de validez del "state" de un flujo OAuth iniciado.
    'oauth_state_ttl' => (int) env('MAIL_CONNECTIONS_OAUTH_STATE_TTL', 10),

];
