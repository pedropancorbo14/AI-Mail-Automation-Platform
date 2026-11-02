import { api } from '@/api/http';

export type LimitKey = 'ai_emails_per_month' | 'mailboxes' | 'users' | 'documents';
export type SubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'canceled';

export interface PlanInfo {
    key: string;
    name: string;
    price_eur_month: number | null;
    description: string;
    limits: Record<LimitKey, number | null>;
    current?: boolean;
}

export interface LimitUsage {
    used: number;
    /** null = sin límite. */
    limit: number | null;
    ratio: number | null;
}

export interface Billing {
    plan: PlanInfo;
    subscription: {
        status: SubscriptionStatus;
        status_label: string;
        trial_ends_at: string | null;
        current_period_ends_at: string | null;
        /** Por qué la IA no trabaja ahora (null = trabaja). */
        ai_block_reason: string | null;
    };
    usage: {
        period: string;
        emails_received: number;
        limits: Record<LimitKey, LimitUsage>;
        /** Parte usada de la capacidad de IA del mes (null = sin límite). Nunca en dinero. */
        ai_capacity_ratio: number | null;
    };
    limit_labels: Record<LimitKey, string>;
    plans: PlanInfo[];
}

export async function getBilling(): Promise<Billing> {
    return (await api.get<{ data: Billing }>('/billing')).data;
}

/** Límite casi agotado a partir de este uso (aviso en el panel). */
export const NEAR_LIMIT = 0.8;

/** Límites que se acercan al máximo o lo han alcanzado, del más usado al menos. */
export function limitsNearMax(billing: Billing): { label: string; ratio: number }[] {
    const rows = (Object.entries(billing.usage.limits) as [LimitKey, LimitUsage][])
        .filter(([, usage]) => usage.ratio !== null)
        .map(([key, usage]) => ({ label: billing.limit_labels[key], ratio: usage.ratio as number }));

    if (billing.usage.ai_capacity_ratio !== null) {
        rows.push({ label: 'Capacidad de IA del mes', ratio: billing.usage.ai_capacity_ratio });
    }

    return rows.filter((row) => row.ratio >= NEAR_LIMIT).sort((a, b) => b.ratio - a.ratio);
}

/** Cómo se lee un límite: "12 de 200" o "7 (sin límite)". */
export function formatLimit(usage: LimitUsage): string {
    const value = (n: number) => n.toLocaleString('es-ES');

    return usage.limit === null ? `${value(usage.used)} (sin límite)` : `${value(usage.used)} de ${value(usage.limit)}`;
}

/** Precio del plan: "49 € / mes", "Gratis" o "A medida". */
export function formatPrice(plan: PlanInfo): string {
    if (plan.price_eur_month === null) {
        return 'A medida';
    }

    return plan.price_eur_month === 0 ? 'Gratis' : `${plan.price_eur_month} € / mes`;
}
