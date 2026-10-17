/** Tipos de las respuestas de la API /api/v1 que usa el panel. */

export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export type EmailStatus = 'received' | 'processing' | 'ai_review' | 'human_review' | 'approved' | 'sent' | 'ignored' | 'failed';

export type EmailIntent =
    | 'order_status' | 'return_request' | 'cancellation' | 'invoice_request' | 'billing_issue' | 'product_question'
    | 'technical_support' | 'complaint' | 'sales_inquiry' | 'account_change' | 'feedback' | 'auto_reply' | 'spam' | 'other';

export interface Person {
    id: number;
    name: string;
}

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
    /** Persona que se ha asignado el caso (revisión humana). */
    assignee?: Person | null;
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
    /** Escrita por una persona (null = la IA). */
    author?: Person | null;
    /** Texto que propuso la IA, si una persona lo cambió antes de aprobarlo. */
    original_body: string | null;
    edited_by?: Person | null;
    edited_at: string | null;
    language: string | null;
    confidence: number | null;
    needs_human: boolean;
    needs_human_reason: string | null;
    sources: { type: 'document' | 'order' | 'invoice' | 'ticket' | 'customer'; reference: string }[];
    pending_actions: string[];
    internal_note: string | null;
    approved_by?: { id: number; name: string } | null;
    approved_at: string | null;
    sent_automatically: boolean;
    sent_to: string | null;
    sent_at: string | null;
    send_error: string | null;
    rejected_by?: Person | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    /** Avisos de las comprobaciones automáticas sobre la propuesta pendiente. */
    checks: string[];
    created_at: string | null;
}

export interface ToolCall {
    id: number;
    tool: string;
    arguments: Record<string, unknown>;
    result: unknown;
    status: 'success' | 'error' | 'rejected';
    /** Quién decidió la consulta (ai-service): plan del backend, el modelo o una persona. */
    origin?: 'plan' | 'model' | 'approval' | null;
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
    /** Especialistas de ai-service que intervinieron; el primero es el principal. */
    specialists?: string[];
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
    /** Espera a una persona (revisión humana o error). */
    awaiting_person: boolean;
    /** Ya se intentó enviar una respuesta (puede haber llegado): no se reprocesa con la IA. */
    send_attempted: boolean;
    assigned_at: string | null;
    /** Acción que propuso ai-service (p. ej. abrir un ticket) y espera a que una persona decida. */
    pending_action: PendingAction | null;
    /** Se puede reprocesar con una indicación para la IA (solo con ai-service). */
    can_instruct: boolean;
    reviews: Review[];
}

export interface PendingAction {
    tool: string;
    summary: string;
    arguments: Record<string, unknown>;
    proposed_at: string | null;
}

export type ReviewDecision = 'approved' | 'rejected' | 'replied' | 'closed' | 'reprocessed' | 'action_approved' | 'action_rejected';

/** Una decisión de una persona sobre un email. */
export interface Review {
    id: number;
    decision: ReviewDecision;
    decision_label: string;
    edited: boolean;
    reason: string | null;
    waited_seconds: number | null;
    reply_id?: number | null;
    user: Person | null;
    at: string | null;
}

/** Un caso de la bandeja de revisión. */
export interface ReviewQueueItem extends EmailListItem {
    waiting_since: string | null;
    waiting_reason: string | null;
    has_draft: boolean;
    has_pending_action: boolean;
}

export interface ReviewQueueCounts {
    total: number;
    mine: number;
    unassigned: number;
    failed: number;
}

/** Una decisión del historial, con su email. */
export interface ReviewHistoryEntry extends Review {
    email: { id: number; subject: string | null; from: Address; intent: EmailIntent | null; status: EmailStatus } | null;
}

export interface ReviewSummary {
    days: number;
    pending: number;
    decisions: Record<ReviewDecision, number>;
    resolved: number;
    wait_seconds: { average: number | null; median: number | null };
    ai_proposals: { reviewed: number; approved_unchanged: number; approved_edited: number; rejected: number; unchanged_rate: number | null };
    people: { user: { id: number; name: string | null }; total: number; decisions: Record<ReviewDecision, number>; edited: number; average_wait_seconds: number | null }[];
}
