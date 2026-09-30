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

];
