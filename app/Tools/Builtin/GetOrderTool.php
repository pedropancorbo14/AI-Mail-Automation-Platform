<?php

namespace App\Tools\Builtin;

use App\ERP\Contracts\OrderProvider;
use App\Tools\Contracts\AITool;
use App\Tools\Contracts\HasAvailability;
use App\Tools\Contracts\RequiresSender;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * Detalle de un pedido (estado, líneas, envío y seguimiento) SOLO si es del
 * remitente del email. Un pedido inexistente y uno de otro cliente reciben
 * la misma respuesta: un email no puede ni averiguar si un número existe.
 */
class GetOrderTool implements AITool, HasAvailability, RequiresSender
{
    public function __construct(private readonly OrderProvider $orders)
    {
    }

    /** Solo si la empresa tiene una fuente de pedidos. */
    public function isAvailable(): bool
    {
        return $this->orders->isConfigured();
    }

    public function name(): string
    {
        return 'get_order';
    }

    public function description(): string
    {
        return 'Devuelve el detalle de un pedido del cliente que ha escrito el email: estado, fecha, productos, '
            . 'total y envío (transportista, número y enlace de seguimiento, fechas previstas y de entrega). '
            . 'Solo encuentra pedidos de ese cliente. Si no lo encuentra, no supongas su estado.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Número de pedido tal como lo da el cliente (con o sin "#"), p. ej. "45821".',
                ],
            ],
            'required' => ['order_number'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $number = self::normalize((string) $arguments['order_number']);
        $order = ($number !== null && mb_strlen($number) <= 40) ? $this->orders->findOrder($number) : null;

        if ($order === null || ! $order->belongsTo((string) $context->senderEmail())) {
            return ToolResult::error('No hay ningún pedido con ese número asociado a este cliente.');
        }

        return ToolResult::success(OrderPresenter::detail($order));
    }

    /** "#45821", "nº 45821", "pedido 45821", "ES-1234" → primer fragmento con dígitos. */
    private static function normalize(string $written): ?string
    {
        preg_match_all('/[A-Za-z0-9\-]+/', $written, $tokens);

        foreach ($tokens[0] as $token) {
            if (preg_match('/\d/', $token)) {
                return trim($token, '-');
            }
        }

        return null;
    }
}
