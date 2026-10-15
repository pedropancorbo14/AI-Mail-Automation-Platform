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

export interface EmailReply {
    id: number;
    agent_run_id: number | null;
    status: 'draft' | 'approved' | 'sending' | 'rejected' | 'sent' | 'failed';
    body: string;
    language: string | null;
    confidence: number | null;
    needs_human: boolean;
    needs_human_reason: string | null;
    sources: { type: 'document' | 'order'; reference: string }[];
    pending_actions: string[];
    internal_note: string | null;
    approved_by?: { id: number; name: string } | null;
    approved_at: string | null;
    sent_automatically: boolean;
    sent_to: string | null;
    sent_at: string | null;
    send_error: string | null;
    created_at: string | null;
}

export interface ToolCall {
    id: number;
    tool: string;
    arguments: Record<string, unknown>;
    result: unknown;
    status: 'success' | 'error' | 'rejected';
    error: string | null;
    duration_ms: number | null;
}

export interface AgentStep {
    id: number;
    status: 'success' | 'failed' | 'refused';
    model: string | null;
    latency_ms: number | null;
    error: string | null;
    tool_calls: ToolCall[];
}

export interface AgentRun {
    id: number;
    status: 'running' | 'completed' | 'failed';
    stop_reason: string | null;
    stop_reason_label: string | null;
    error: string | null;
    iterations: number;
    tool_calls_count: number;
    duration_ms: number | null;
    started_at: string | null;
    finished_at: string | null;
    steps: AgentStep[];
}

export interface StatusChange {
    from: EmailStatus | null;
    to: EmailStatus;
    to_label: string;
    reason: string | null;
    user: { id: number; name: string } | null;
    at: string | null;
}

export interface EmailAttachment {
    id: number;
    filename: string;
    content_type: string;
    size: number;
    inline: boolean;
    status: 'pending' | 'stored' | 'skipped' | 'failed';
    error: string | null;
}

export interface EmailDetail extends EmailListItem {
    to: Address[];
    cc: Address[];
    reply_to: Address[];
    message_id: string | null;
    in_reply_to: string | null;
    text_body: string | null;
    has_html: boolean;
    classification: {
        needs_human: boolean;
        needs_human_reason: string | null;
        entities: { order_numbers?: string[]; invoice_numbers?: string[]; customer_name?: string | null };
        classified_at: string;
        model: string | null;
    } | null;
    attachments: EmailAttachment[];
    status_history: StatusChange[];
    replies: EmailReply[];
    agent_runs: AgentRun[];
}
