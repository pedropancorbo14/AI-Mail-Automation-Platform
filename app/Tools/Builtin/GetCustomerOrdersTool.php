<?php

namespace App\Tools\Builtin;

use App\ERP\Contracts\OrderProvider;
use App\ERP\Data\Order;
use App\Tools\Contracts\AITool;
use App\Tools\Contracts\HasAvailability;
use App\Tools\Contracts\RequiresSender;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * Últimos pedidos del remitente del email. No acepta un email como
 * argumento a propósito: siempre es el remitente (contexto del backend),
 * así la IA no puede consultar los pedidos de otra persona.
 */
class GetCustomerOrdersTool implements AITool, HasAvailability, RequiresSender
{
    private const int MAX_ORDERS = 10;

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
        return 'get_customer_orders';
    }

    public function description(): string
    {
        return 'Lista los últimos pedidos del cliente que ha escrito el email (número, estado, fecha y total), '
            . 'del más reciente al más antiguo. Úsala cuando el cliente no indique el número de pedido; '
            . 'después usa get_order para ver el detalle del que corresponda.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Cuántos pedidos (1 a ' . self::MAX_ORDERS . '; por defecto 5).',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $limit = max(1, min(self::MAX_ORDERS, (int) ($arguments['limit'] ?? 5)));
        $orders = $this->orders->ordersForCustomer((string) $context->senderEmail(), $limit);

        return ToolResult::success([
            'orders' => array_map(fn (Order $order) => OrderPresenter::summary($order), $orders),
            'note' => $orders === [] ? 'Este cliente no tiene pedidos con el email desde el que escribe.' : null,
        ]);
    }
}
