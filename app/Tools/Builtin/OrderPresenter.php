<?php

namespace App\Tools\Builtin;

use App\ERP\Data\Order;
use App\ERP\Data\OrderItem;
use Carbon\CarbonImmutable;

/** Pedido en el formato que ve la IA: lo útil para responder, sin datos de más. */
final class OrderPresenter
{
    /** @return array<string, mixed> */
    public static function detail(Order $order): array
    {
        return self::summary($order) + [
            'items' => array_map(fn (OrderItem $item) => [
                'sku' => $item->sku,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice,
            ], $order->items),
            'shipment' => $order->shipment === null ? null : [
                'carrier' => $order->shipment->carrier,
                'tracking_number' => $order->shipment->trackingNumber,
                'tracking_url' => $order->shipment->trackingUrl,
                'shipped_at' => self::date($order->shipment->shippedAt),
                'estimated_delivery' => self::date($order->shipment->estimatedDelivery),
                'delivered_at' => self::date($order->shipment->deliveredAt),
            ],
            'shipping_city' => $order->shippingCity,
            'shipping_country' => $order->shippingCountry,
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(Order $order): array
    {
        return [
            'order_number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'placed_at' => self::date($order->placedAt),
            'total' => $order->total,
            'currency' => $order->currency,
            'customer_name' => $order->customerName,
        ];
    }

    private static function date(?CarbonImmutable $date): ?string
    {
        return $date?->toIso8601String();
    }
}
