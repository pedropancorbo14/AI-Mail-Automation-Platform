/** Tipos de las respuestas de la API /api/v1 que usa el panel. */

export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export type EmailStatus = 'received' | 'processing' | 'ai_review' | 'human_review' | 'approved' | 'sent' | 'ignored' | 'failed';

export type EmailIntent =
    | 'order_status' | 'return_request' | 'cancellation' | 'invoice_request' | 'billing_issue' | 'product_question'
    | 'technical_support' | 'complaint' | 'sales_inquiry' | 'account_change' | 'feedback' | 'auto_reply' | 'spam' | 'other';

export interface Address {
    email: string | null;
    name: string | null;
}

export interface EmailListItem {
    id: number;
    subject: string | null;
    from: Address;
    mailbox?: { id: number; name: string; email: string };
    status: EmailStatus;
    status_label: string;
    intent: EmailIntent | null;
    intent_confidence: number | null;
    summary: string | null;
    language: string | null;
    urgency: string | null;
    sentiment: string | null;
    injection_suspected: boolean;
    automated_reason: string | null;
    attachments_count?: number;
    sent_at: string | null;
    received_at: string | null;
}
