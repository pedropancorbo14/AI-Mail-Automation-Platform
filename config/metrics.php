<?php

/*
| Métricas de Prometheus (Fase 11). La app las expone en GET /metrics y
| Prometheus las recoge cada 15 s; Grafana las dibuja.
*/
return [

    // false = no se registra nada y /metrics responde 404.
    'enabled' => (bool) env('METRICS_ENABLED', true),

    // Prefijo de todas las métricas propias (aimail_emails_ingested_total...).
    'namespace' => 'aimail',

    // Token que Prometheus envía como "Authorization: Bearer ...". Sin token,
    // /metrics responde 404: las métricas revelan volumen y costes por empresa.
    'token' => env('METRICS_TOKEN'),

    /*
    | Dónde se acumulan los contadores entre peticiones y procesos: "redis"
    | (compartido por PHP-FPM y los workers de Horizon, persistente con AOF) o
    | "memory" (solo dura la petición: tests).
    */
    'storage' => env('METRICS_STORAGE', 'redis'),

    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD'),
        // Base de datos propia (0 = colas/sesiones, 1 = caché): las métricas no se
        // mezclan con nada ni se borran al vaciar la caché.
        'database' => (int) env('METRICS_REDIS_DB', 2),
        'timeout' => 0.5,
    ],
];
