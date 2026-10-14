<?php

return [

    /*
    | Fuente de los pedidos (App\ERP\Contracts\OrderProvider). De momento solo
    | "demo": pedidos de ejemplo de un JSON por empresa. Las integraciones
    | reales (Shopify, WooCommerce, API del ERP...) llegan en la Fase 14.
    */
    'orders' => [
        'provider' => env('ORDERS_PROVIDER', 'demo'),
        'demo_file' => env('ORDERS_DEMO_FILE', database_path('seeders/demo/orders.json')),
    ],

];
