import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import ReviewHistoryPage from '@/pages/ReviewHistoryPage.vue';
import type { ReviewHistoryEntry, ReviewSummary } from '@/api/types';

const summary: ReviewSummary = {
    days: 30,
    pending: 4,
    decisions: { approved: 3, rejected: 1, replied: 1, closed: 1, reprocessed: 0, action_approved: 0, action_rejected: 0 },
    resolved: 5,
    wait_seconds: { average: 2700, median: 1800 },
    ai_proposals: { reviewed: 4, approved_unchanged: 2, approved_edited: 1, rejected: 1, unchanged_rate: 0.5 },
    people: [
        { user: { id: 2, name: 'Bea' }, total: 4, decisions: { approved: 2, rejected: 1, replied: 1, closed: 0, reprocessed: 0, action_approved: 0, action_rejected: 0 }, edited: 1, average_wait_seconds: 3600 },
    ],
};

const entry = (overrides: Partial<ReviewHistoryEntry> = {}): ReviewHistoryEntry => ({
    id: 1, decision: 'approved', decision_label: 'Aprobada', edited: true, reason: null, waited_seconds: 900,
    user: { id: 2, name: 'Bea' }, at: '2026-10-17T09:00:00Z',
    email: { id: 12, subject: 'Pedido 45821', from: { email: 'cliente@cliente.test', name: null }, intent: 'order_status', status: 'sent' },
    ...overrides,
});

const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200 });

function stubApi(): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url.startsWith('/api/v1/reviews/summary') ? json({ data: summary })
            : json({ data: [entry(), entry({ id: 2, decision: 'closed', decision_label: 'Cerrado sin responder', edited: false, reason: 'Resuelto por teléfono.', email: { id: 13, subject: 'Factura', from: { email: 'b@cliente.test', name: null }, intent: 'invoice_request', status: 'ignored' } })], meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 } }),
    ));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountPage() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/revision/historial', name: 'review-history', component: ReviewHistoryPage },
            { path: '/revision', name: 'review', component: { template: '<div />' } },
            { path: '/emails/:id', name: 'email', component: { template: '<div />' } },
        ],
    });
    await router.push('/revision/historial');
    const wrapper = mount(ReviewHistoryPage, { global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('historial de la revisión', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('resume el periodo: pendientes, resueltos, espera y qué se hace con las propuestas de la IA', async () => {
        const fetchMock = stubApi();

        const wrapper = await mountPage();
        const text = wrapper.text();

        expect(fetchMock.mock.calls.map(([url]) => url)).toContain('/api/v1/reviews/summary?days=30');
        expect(text).toContain('Esperan a una persona');
        expect(text).toContain('30 min');
        expect(text).toContain('Media 45 min');
        expect(wrapper.get('[data-test="proposals"]').text()).toContain('50 %');
        expect(wrapper.get('[data-test="proposals"]').text()).toContain('2 tal cual, 1 corregidas, 1 descartadas');
        expect(text).toContain('Bea');
        expect(text).toContain('1 h');
    });

    it('lista cada decisión con su email, motivo y espera', async () => {
        stubApi();

        const wrapper = await mountPage();
        const text = wrapper.text();

        expect(text).toContain('Aprobada (con cambios)');
        expect(text).toContain('Pedido 45821');
        expect(text).toContain('15 min');
        expect(text).toContain('Cerrado sin responder');
        expect(text).toContain('Factura');
        expect(text).toContain('Resuelto por teléfono.');
        expect(text).toContain('2 decisiones');
    });
});
