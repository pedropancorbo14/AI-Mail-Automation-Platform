import { ApiError, api } from '@/api/http';
import type { Paginated } from '@/api/types';

export type OrderStatus = 'pending_payment' | 'processing' | 'shipped' | 'delivered' | 'cancelled' | 'returned';

/** Pedido importado (mismo formato que la API estándar, order.v1). */
export interface CustomerOrder {
    id: number;
    number: string;
    customer_email: string;
    customer_name: string | null;
    status: OrderStatus;
    placed_at: string | null;
    total: number | null;
    currency: string | null;
    items: { name: string; quantity: number }[];
    shipment: { carrier?: string; tracking_number?: string; tracking_url?: string } | null;
    shipping_city: string | null;
    shipping_country: string | null;
    updated_at: string | null;
}

export interface OrdersPage extends Paginated<CustomerOrder> {
    meta: Paginated<CustomerOrder>['meta'] & { total_orders: number; last_imported_at: string | null };
}

export interface ImportResult {
    rows: number;
    created: number;
    updated: number;
}

export interface RowError {
    row: number;
    errors: string[];
}

export const orderStatusLabels: Record<OrderStatus, string> = {
    pending_payment: 'Pendiente de pago',
    processing: 'En preparación',
    shipped: 'Enviado',
    delivered: 'Entregado',
    cancelled: 'Cancelado',
    returned: 'Devuelto',
};

export function listOrders(page = 1, q = ''): Promise<OrdersPage> {
    return api.get<OrdersPage>('/orders', { page, q });
}

export async function importOrders(file: File): Promise<ImportResult> {
    const body = new FormData();
    body.append('file', file);

    return (await api.post<{ data: ImportResult }>('/orders/import', body)).data;
}

export async function deleteAllOrders(): Promise<number> {
    return (await api.delete<{ data: { deleted: number } }>('/orders')).data.deleted;
}

/** Errores por fila de un CSV rechazado (422 de la importación). */
export function rowErrors(error: unknown): { rows: RowError[]; total: number } {
    if (!(error instanceof ApiError) || error.status !== 422) {
        return { rows: [], total: 0 };
    }

    const rows = Array.isArray(error.body.rows) ? (error.body.rows as RowError[]) : [];

    return { rows, total: Number(error.body.total_row_errors ?? rows.length) };
}

/** Plantilla para rellenar en Excel (la sirve la API; un test comprueba que se importa). */
export const TEMPLATE_URL = '/api/v1/orders/template';
