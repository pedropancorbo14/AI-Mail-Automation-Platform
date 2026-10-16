import { api } from '@/api/http';
import type { EmailDetail, EmailIntent, EmailListItem, EmailStatus, Paginated } from '@/api/types';

export interface EmailFilters {
    status: EmailStatus | null;
    intent: EmailIntent | null;
    mailbox: number | null;
    q: string;
    from: string | null;
    to: string | null;
    page: number;
}

export const emptyFilters = (): EmailFilters => ({ status: null, intent: null, mailbox: null, q: '', from: null, to: null, page: 1 });

export function listEmails(filters: EmailFilters, perPage = 25): Promise<Paginated<EmailListItem>> {
    return api.get<Paginated<EmailListItem>>('/emails', { ...filters, per_page: perPage });
}

export async function getEmail(id: number): Promise<EmailDetail> {
    return (await api.get<{ data: EmailDetail }>(`/emails/${id}`)).data;
}

/** Borra el email con sus adjuntos, respuestas e historial (derecho de supresión). */
export function deleteEmail(id: number): Promise<void> {
    return api.delete<void>(`/emails/${id}`);
}

/** HTML del email ya saneado por el servidor (null si solo tiene texto). */
export async function getEmailHtml(id: number): Promise<{ html: string | null; remote_images: boolean }> {
    return (await api.get<{ data: { html: string | null; remote_images: boolean } }>(`/emails/${id}/html`)).data;
}

/** Enlace de descarga de un adjunto (la cookie de sesión va sola: mismo origen). */
export function attachmentUrl(emailId: number, attachmentId: number): string {
    return `/api/v1/emails/${emailId}/attachments/${attachmentId}`;
}
