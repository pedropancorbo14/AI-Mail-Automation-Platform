import { api } from '@/api/http';
import type { Paginated } from '@/api/types';

export interface MailConnectionSummary {
    id: number;
    name: string;
    email: string;
    provider: 'imap' | 'microsoft' | 'google';
    status: 'pending' | 'active' | 'failing' | 'disabled';
}

export function listMailConnections(page = 1): Promise<Paginated<MailConnectionSummary>> {
    return api.get<Paginated<MailConnectionSummary>>('/mail-connections', { page });
}
