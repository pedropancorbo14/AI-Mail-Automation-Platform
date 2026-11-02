<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\Stripe\StripeEventHandler;
use App\Billing\Stripe\StripeSignature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Avisos de Stripe (Fase 17). Pública a propósito (Stripe no tiene nuestro
 * token): solo se acepta con la firma del secreto del webhook. Sin secreto
 * configurado no existe (404).
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeEventHandler $handler): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            abort(404);
        }

        $payload = $request->getContent();

        if (! StripeSignature::valid($payload, $request->header('Stripe-Signature'), $secret, (int) config('services.stripe.webhook_tolerance'))) {
            Log::warning('Aviso de Stripe con una firma no válida');

            return response()->json(['message' => 'Firma no válida.'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            return response()->json(['message' => 'Aviso no válido.'], 400);
        }

        $result = $handler->handle($event);
        Log::info('Aviso de Stripe', ['type' => $event['type'] ?? null, 'result' => $result]);

        // 200 también si se ignora: si no, Stripe lo reintentaría durante días.
        return response()->json(['received' => true, 'result' => $result]);
    }
}
