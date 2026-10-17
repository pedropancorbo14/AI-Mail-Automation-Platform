import { api } from '@/api/http';
import type {
    EmailDetail,
    EmailIntent,
    Paginated,
    ReviewDecision,
    ReviewHistoryEntry,
    ReviewQueueCounts,
    ReviewQueueItem,
    ReviewSummary,
} from '@/api/types';

/** Revisión humana (Fase 13): bandeja, decisiones sobre un email e historial. */

export type QueueScope = 'all' | 'mine' | 'unassigned';

export interface QueueFilters {
    scope: QueueScope;
    intent: EmailIntent | null;
    page: number;
}

export type ReviewQueue = Paginated<ReviewQueueItem> & { meta: { counts: ReviewQueueCounts } };

export function listReviewQueue(filters: QueueFilters, perPage = 25): Promise<ReviewQueue> {
    return api.get<ReviewQueue>('/review-queue', {
        assigned: filters.scope === 'all' ? null : filters.scope,
        intent: filters.intent,
        page: filters.page,
        per_page: perPage,
    });
}

const detail = async (request: Promise<{ data: EmailDetail }>): Promise<EmailDetail> => (await request).data;

/** Aprueba la propuesta de la IA y la envía. Con body, el texto que deja la persona (editado o no). */
export function approveReply(emailId: number, replyId: number, body?: string): Promise<EmailDetail> {
    return detail(api.post(`/emails/${emailId}/replies/${replyId}/approve`, body === undefined ? {} : { body }));
}

export function rejectReply(emailId: number, replyId: number, reason: string | null): Promise<EmailDetail> {
    return detail(api.post(`/emails/${emailId}/replies/${replyId}/reject`, { reason }));
}

/** Envía una respuesta escrita por la persona. */
export function writeReply(emailId: number, body: string): Promise<EmailDetail> {
    return detail(api.post(`/emails/${emailId}/replies`, { body }));
}

export function closeEmail(emailId: number, reason: string | null): Promise<EmailDetail> {
    return detail(api.post(`/emails/${emailId}/close`, { reason }));
}

/** Vuelve a procesar el email con la IA; con ai-service, con una indicación opcional de la persona. */
export function reprocessEmail(emailId: number, instruction: string | null = null): Promise<EmailDetail> {
    return detail(api.post(`/emails/${emailId}/reprocess`, instruction ? { instruction } : {}));
}

/** Aprueba o rechaza la acción que propuso ai-service: la IA sigue con la decisión. */
export function decideAction(emailId: number, approved: boolean): Promise<EmailDetail> {
    return detail(api.post(`/emails/${emailId}/action/${approved ? 'approve' : 'reject'}`));
}

/** Asigna el caso a una persona (null = liberarlo). */
export function assignEmail(emailId: number, userId: number | null): Promise<EmailDetail> {
    return detail(api.put(`/emails/${emailId}/assignee`, { user_id: userId }));
}

/** Avisos de las comprobaciones automáticas sobre un texto antes de enviarlo. */
export async function checkReply(emailId: number, body: string, replyId: number | null): Promise<string[]> {
    return (await api.post<{ data: { warnings: string[] } }>(`/emails/${emailId}/review-checks`, { body, reply_id: replyId })).data.warnings;
}

export interface HistoryFilters {
    decision: ReviewDecision | null;
    user: number | null;
    page: number;
}

export function listReviews(filters: HistoryFilters, perPage = 25): Promise<Paginated<ReviewHistoryEntry>> {
    return api.get<Paginated<ReviewHistoryEntry>>('/reviews', { ...filters, per_page: perPage });
}

export async function getReviewSummary(days: 7 | 30 | 90): Promise<ReviewSummary> {
    return (await api.get<{ data: ReviewSummary }>('/reviews/summary', { days })).data;
}
