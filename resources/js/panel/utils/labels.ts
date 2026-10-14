import type { EmailIntent, EmailStatus } from '@/api/types';

type TagType = 'default' | 'info' | 'success' | 'warning' | 'error';

export const statusLabels: Record<EmailStatus, string> = {
    received: 'Recibido',
    processing: 'Procesando',
    ai_review: 'Revisión de la IA',
    human_review: 'Revisión humana',
    approved: 'Aprobado',
    sent: 'Enviado',
    ignored: 'Sin respuesta',
    failed: 'Error',
};

/** Color de la etiqueta de cada estado: lo que pide atención, en ámbar o rojo. */
export const statusTagTypes: Record<EmailStatus, TagType> = {
    received: 'default',
    processing: 'info',
    ai_review: 'info',
    human_review: 'warning',
    approved: 'success',
    sent: 'success',
    ignored: 'default',
    failed: 'error',
};

export const intentLabels: Record<EmailIntent, string> = {
    order_status: 'Estado de pedido',
    return_request: 'Devolución',
    cancellation: 'Cancelación',
    invoice_request: 'Factura',
    billing_issue: 'Problema de cobro',
    product_question: 'Pregunta de producto',
    technical_support: 'Soporte técnico',
    complaint: 'Queja',
    sales_inquiry: 'Consulta comercial',
    account_change: 'Cambio de cuenta',
    feedback: 'Opinión',
    auto_reply: 'Respuesta automática',
    spam: 'Spam',
    other: 'Otros',
};

export const urgencyLabels: Record<string, string> = { low: 'Baja', medium: 'Media', high: 'Alta' };

export const sentimentLabels: Record<string, string> = { positive: 'Positivo', neutral: 'Neutral', negative: 'Negativo' };

export function options<T extends string>(labels: Record<T, string>): { label: string; value: T }[] {
    return (Object.entries(labels) as [T, string][]).map(([value, label]) => ({ value, label }));
}
