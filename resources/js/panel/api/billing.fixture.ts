import type { Billing } from '@/api/billing';

/** Respuesta de GET /billing para los tests. */
export function billingFixture(overrides: Partial<Billing['usage']> = {}, subscription: Partial<Billing['subscription']> = {}): Billing {
    return {
        plan: { key: 'trial', name: 'Prueba', price_eur_month: 0, description: 'Para probar.', limits: { ai_emails_per_month: 200, mailboxes: 1, users: 3, documents: 20 } },
        subscription: { status: 'trialing', status_label: 'En prueba', trial_ends_at: '2026-11-16T10:00:00+00:00', current_period_ends_at: null, ai_block_reason: null, ...subscription },
        usage: {
            period: '2026-11',
            emails_received: 120,
            limits: {
                ai_emails_per_month: { used: 50, limit: 200, ratio: 0.25 },
                mailboxes: { used: 1, limit: 1, ratio: 1 },
                users: { used: 2, limit: 3, ratio: 0.6667 },
                documents: { used: 4, limit: 20, ratio: 0.2 },
            },
            ai_capacity_ratio: 0.85,
            ...overrides,
        },
        limit_labels: { ai_emails_per_month: 'Emails con IA al mes', mailboxes: 'Buzones', users: 'Usuarios', documents: 'Documentos' },
        plans: [
            { key: 'trial', name: 'Prueba', price_eur_month: 0, description: 'Para probar.', limits: { ai_emails_per_month: 200, mailboxes: 1, users: 3, documents: 20 }, current: true },
            { key: 'starter', name: 'Inicial', price_eur_month: 49, description: 'Equipos pequeños.', limits: { ai_emails_per_month: 1000, mailboxes: 2, users: 5, documents: 50 }, current: false },
            { key: 'enterprise', name: 'A medida', price_eur_month: null, description: 'A medida.', limits: { ai_emails_per_month: null, mailboxes: null, users: null, documents: null }, current: false },
        ],
    };
}
