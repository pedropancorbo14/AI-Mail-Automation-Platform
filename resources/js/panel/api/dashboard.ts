import { api } from '@/api/http';
import type { EmailIntent, EmailListItem, EmailStatus } from '@/api/types';

export type DashboardPeriod = 7 | 30 | 90;

export interface Dashboard {
    period: { days: DashboardPeriod; from: string };
    emails: {
        received: number;
        by_status: Record<EmailStatus, number>;
        sent_automatically: number;
        automation_rate: number | null;
        awaiting_human: number;
        failed: number;
    };
    intents: { intent: EmailIntent; count: number }[];
    ai: {
        requests: number;
        failed_requests: number;
        avg_latency_ms: number | null;
    };
    needs_attention: EmailListItem[];
}

export async function getDashboard(days: DashboardPeriod): Promise<Dashboard> {
    return (await api.get<{ data: Dashboard }>('/dashboard', { days })).data;
}

/** Filas del ranking de intenciones: ancho de cada barra respecto a la mayor y % del total. */
export function intentBars(intents: Dashboard['intents']): { intent: EmailIntent; count: number; width: number; share: number }[] {
    const max = Math.max(0, ...intents.map((row) => row.count));
    const total = intents.reduce((sum, row) => sum + row.count, 0);

    return intents.map((row) => ({
        ...row,
        width: max > 0 ? Math.round((row.count / max) * 100) : 0,
        share: total > 0 ? row.count / total : 0,
    }));
}
