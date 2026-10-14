<?php

namespace App\ERP\Enums;

/** Estado normalizado de un pedido (cada integración traduce el suyo a estos). */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pendiente de pago',
            self::Processing => 'En preparación',
            self::Shipped => 'Enviado',
            self::Delivered => 'Entregado',
            self::Cancelled => 'Cancelado',
            self::Returned => 'Devuelto',
        };
    }
}
