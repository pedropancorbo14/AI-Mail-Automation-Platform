<?php

namespace App\Billing\Stripe;

use App\Billing\Plan;
use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lo poco que la plataforma pide a Stripe, con Http de Laravel (sin SDK,
 * como el OAuth de la Fase 3): una página de pago (Checkout) y el portal del
 * cliente (cambiar de plan, tarjeta, facturas, cancelar). Las tarjetas nunca
 * pasan por nuestro servidor.
 */
class StripeClient
{
    public static function enabled(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * URL de la página de pago de Stripe para contratar $plan.
     *
     * @throws StripeException
     */
    public function checkoutUrl(Tenant $tenant, Plan $plan, string $email, string $returnUrl): string
    {
        $params = [
            'mode' => 'subscription',
            'line_items' => [['price' => $plan->stripePrice, 'quantity' => 1]],
            'success_url' => $returnUrl.'?checkout=success',
            'cancel_url' => $returnUrl.'?checkout=cancel',
            // La empresa viaja en los metadatos: así el webhook sabe de quién es la suscripción.
            'client_reference_id' => (string) $tenant->id,
            'metadata' => ['tenant_id' => (string) $tenant->id],
            'subscription_data' => ['metadata' => ['tenant_id' => (string) $tenant->id, 'plan' => $plan->key]],
            'allow_promotion_codes' => 'true',
        ];

        if ($tenant->billing_customer_id) {
            $params['customer'] = $tenant->billing_customer_id;
        } else {
            $params['customer_email'] = $email;
        }

        return $this->post('/checkout/sessions', $params)['url'];
    }

    /**
     * URL del portal del cliente de Stripe.
     *
     * @throws StripeException
     */
    public function portalUrl(Tenant $tenant, string $returnUrl): string
    {
        return $this->post('/billing_portal/sessions', [
            'customer' => $tenant->billing_customer_id,
            'return_url' => $returnUrl,
        ])['url'];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function post(string $path, array $params): array
    {
        try {
            $response = $this->http()->asForm()->post($path, $params);
        } catch (ConnectionException) {
            throw new StripeException('El sistema de pagos no responde. Inténtalo de nuevo en unos minutos.');
        }

        if ($response->failed() || ! is_string($response->json('url'))) {
            // El detalle solo al log (puede llevar ids de Stripe), nunca la clave.
            Log::warning('Stripe rechazó la petición', [
                'path' => $path,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
                'type' => $response->json('error.type'),
            ]);

            throw new StripeException('El sistema de pagos rechazó la petición. Si se repite, contacta con nosotros.');
        }

        return $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.stripe.base_url'))
            ->withToken((string) config('services.stripe.secret'))
            ->timeout((int) config('services.stripe.timeout'))
            ->acceptJson();
    }
}
