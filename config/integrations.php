<?php

/*
|--------------------------------------------------------------------------
| Integraciones con los sistemas de cada empresa (Fase 14)
|--------------------------------------------------------------------------
|
| Los datos de negocio (pedidos, facturas, tickets) los consulta el servidor
| MCP de ai-service. Laravel le dice qué tiene conectado cada empresa y le
| sirve, con la API estándar (contracts/integrations/), lo que la empresa
| importa por CSV.
*/

return [
    // Token con el que el servidor MCP lee la API interna de Laravel
    // (configuración de integraciones y pedidos importados). Sin token, esas
    // rutas responden 404. Mismo valor que AI_SERVICE_MCP_LARAVEL_TOKEN.
    'data_token' => env('AI_SERVICE_DATA_TOKEN'),

    'csv' => [
        'max_upload_mb' => (int) env('ORDERS_CSV_MAX_MB', 5),
        'max_rows' => (int) env('ORDERS_CSV_MAX_ROWS', 20000),
        // Errores por fila que se devuelven como máximo (el resto se cuenta).
        'max_reported_errors' => 50,
    ],
];
