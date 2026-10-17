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

export const urgencyLabels: Record<string, string> = { low: 'Baja', normal: 'Normal', high: 'Alta' };

export const sentimentLabels: Record<string, string> = { positive: 'Positivo', neutral: 'Neutral', negative: 'Negativo' };

export function options<T extends string>(labels: Record<T, string>): { label: string; value: T }[] {
    return (Object.entries(labels) as [T, string][]).map(([value, label]) => ({ value, label }));
}

/** Herramientas del agente: nombre y para qué sirven, en palabras de la empresa. */
export const toolLabels: Record<string, { label: string; description: string }> = {
    search_company_documents: {
        label: 'Buscar en la documentación',
        description: 'Consulta las políticas, FAQs y manuales subidos en "Documentación" para responder con lo que dicen.',
    },
    get_order: {
        label: 'Consultar pedido',
        description: 'Estado, productos, envío y seguimiento de un pedido. Solo de los pedidos del cliente que escribe.',
    },
    create_support_ticket: {
        label: 'Abrir un ticket de soporte',
        description: 'Crea un ticket en el sistema de soporte. Solo con la aprobación de una persona.',
    },
    get_customer_orders: {
        label: 'Pedidos del cliente',
        description: 'Últimos pedidos del cliente que escribe, cuando no indica el número.',
    },
};

export const toolLabel = (tool: string): string => toolLabels[tool]?.label ?? tool;

/** Decisiones de la revisión humana. */
export const reviewDecisionLabels: Record<'approved' | 'rejected' | 'replied' | 'closed' | 'reprocessed' | 'action_approved' | 'action_rejected', string> = {
    approved: 'Aprobada',
    rejected: 'Propuesta descartada',
    replied: 'Respondida por una persona',
    closed: 'Cerrado sin responder',
    reprocessed: 'Reprocesado con la IA',
    action_approved: 'Acción de la IA aprobada',
    action_rejected: 'Acción de la IA rechazada',
};
