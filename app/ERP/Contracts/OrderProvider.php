<?php

namespace App\ERP\Contracts;

use App\ERP\Data\Order;

/**
 * Fuente de pedidos de la empresa actual (tienda online, ERP...). Hoy un
 * proveedor de demostración; en la Fase 14, integraciones reales. Siempre
 * trabaja con la empresa del TenantService.
 */
interface OrderProvider
{
    /** ¿La empresa actual tiene una fuente de pedidos configurada? Si no, no se ofrecen herramientas de pedidos. */
    public function isConfigured(): bool;

    /** Pedido por su número (sin "#"), o null si no existe. */
    public function findOrder(string $number): ?Order;

    /**
     * Pedidos de un cliente por email, del más reciente al más antiguo.
     *
     * @return list<Order>
     */
    public function ordersForCustomer(string $email, int $limit = 10): array;
}
