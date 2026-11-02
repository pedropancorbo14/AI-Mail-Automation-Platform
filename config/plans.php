<?php

/*
| Planes de la plataforma SaaS (Fase 17). Cada empresa tiene uno
| (tenants.plan). Límites a null = sin límite.
|
|  - ai_emails_per_month: emails que pasan por la IA en el mes natural.
|    Los que lo superan se guardan igual y van a una persona (nunca se
|    pierde un correo de un cliente).
|  - ai_budget_usd_per_month: gasto de IA del mes (cost_usd de ai_requests,
|    precios de config/ai.php). Se suma al presupuesto diario de AiSpendGuard.
|  - mailboxes, users, documents: cuántos puede tener la empresa a la vez.
|
| Los precios son orientativos (se muestran en el panel); lo que se cobra lo
| decide el precio de Stripe de cada plan (STRIPE_PRICE_*).
*/

$price = fn (string $key) => ($value = env($key)) === null || $value === '' ? null : $value;

return [
    // Plan de las empresas que se dan de alta solas (registro público).
    'default' => env('SAAS_DEFAULT_PLAN', 'trial'),

    // Días de prueba de un alta nueva.
    'trial_days' => (int) env('SAAS_TRIAL_DAYS', 14),

    // Días que se mantiene la IA tras un pago fallido (past_due) antes de
    // pararla; Stripe sigue reintentando el cobro mientras tanto.
    'past_due_grace_days' => (int) env('SAAS_PAST_DUE_GRACE_DAYS', 7),

    'plans' => [
        'trial' => [
            'name' => 'Prueba',
            'price_eur_month' => 0,
            'description' => 'Para probar la plataforma con un buzón durante el periodo de prueba.',
            'stripe_price' => null,
            'limits' => [
                'ai_emails_per_month' => 200,
                'ai_budget_usd_per_month' => 5,
                'mailboxes' => 1,
                'users' => 3,
                'documents' => 20,
            ],
        ],
        'starter' => [
            'name' => 'Inicial',
            'price_eur_month' => 49,
            'description' => 'Pequeños equipos de atención al cliente.',
            'stripe_price' => $price('STRIPE_PRICE_STARTER'),
            'limits' => [
                'ai_emails_per_month' => 1000,
                'ai_budget_usd_per_month' => 30,
                'mailboxes' => 2,
                'users' => 5,
                'documents' => 50,
            ],
        ],
        'business' => [
            'name' => 'Empresa',
            'price_eur_month' => 149,
            'description' => 'Varios buzones y equipos con más volumen.',
            'stripe_price' => $price('STRIPE_PRICE_BUSINESS'),
            'limits' => [
                'ai_emails_per_month' => 5000,
                'ai_budget_usd_per_month' => 150,
                'mailboxes' => 5,
                'users' => 20,
                'documents' => 200,
            ],
        ],
        // A medida: lo asigna el operador (tenant:plan). Sin límites del plan;
        // siguen el presupuesto diario y los demás frenos de seguridad.
        'enterprise' => [
            'name' => 'A medida',
            'price_eur_month' => null,
            'description' => 'Volumen y condiciones a medida. Contacta con nosotros.',
            'stripe_price' => null,
            'limits' => [
                'ai_emails_per_month' => null,
                'ai_budget_usd_per_month' => null,
                'mailboxes' => null,
                'users' => null,
                'documents' => null,
            ],
        ],
    ],
];
