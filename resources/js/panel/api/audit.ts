import { api } from '@/api/http';
import type { Paginated } from '@/api/types';

export type AuditCategory = 'auth' | 'user' | 'tenant' | 'mail_connection' | 'automation' | 'document';

export interface AuditLogEntry {
    id: number;
    action: string;
    /** null = la plataforma o alguien sin cuenta (login fallido). */
    actor: { id: number | null; name: string | null; email: string | null } | null;
    subject: { type: string | null; id: number | null; label: string | null } | null;
    /** {campo: {from, to}}; los secretos llegan como "[oculto]". */
    changes: Record<string, { from: unknown; to: unknown } | string> | null;
    metadata: Record<string, unknown> | null;
    ip: string | null;
    user_agent: string | null;
    request_id: string | null;
    created_at: string;
}

export interface AuditFilters {
    category: AuditCategory | null;
    user: number | null;
    from: string | null;
    to: string | null;
    page: number;
}

export function listAuditLogs(filters: AuditFilters): Promise<Paginated<AuditLogEntry>> {
    return api.get<Paginated<AuditLogEntry>>('/audit-logs', { ...filters });
}
