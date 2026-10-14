import { api } from '@/api/http';
import type { EmailIntent, EmailListItem, EmailStatus, Paginated } from '@/api/types';

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
