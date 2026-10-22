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
    appointment: 'Cita',
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
    get_customer: {
        label: 'Datos del cliente',
        description: 'Nombre, número de pedidos y fechas del cliente que escribe.',
    },
    get_appointment: {
        label: 'Consultar cita',
        description: 'Fecha, hora, servicio, profesional y lugar de una cita. Solo de las citas del cliente que escribe.',
    },
    get_customer_appointments: {
        label: 'Citas del cliente',
        description: 'Citas del cliente que escribe, las próximas primero.',
    },
    get_customer_tickets: {
        label: 'Tickets del cliente',
        description: 'Tickets de soporte del cliente que escribe, con su estado.',
    },
    get_invoice: {
        label: 'Consultar factura',
        description: 'Número, pedido, fecha, importe y estado de pago de una factura del cliente que escribe.',
    },
    get_customer_invoices: {
        label: 'Facturas del cliente',
        description: 'Facturas del cliente que escribe, de la más reciente a la más antigua.',
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

/** Especialistas de ai-service (Fase 5). */
export const specialistLabels: Record<string, string> = {
    orders: 'Pedidos',
    billing: 'Facturación',
    support: 'Soporte',
    appointments: 'Citas',
    general: 'General',
};

/** Quién decidió una consulta de ai-service (el plan del backend no se marca). */
export const toolOriginLabels: Record<string, string> = {
    model: 'Pedida por la IA',
    approval: 'Aprobada por una persona',
};

/** Tipo de dato citado en una respuesta. */
export const sourceTypeLabels: Record<string, string> = {
    document: 'Documento',
    order: 'Pedido',
    invoice: 'Factura',
    ticket: 'Ticket',
    customer: 'Cliente',
    appointment: 'Cita',
};

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
