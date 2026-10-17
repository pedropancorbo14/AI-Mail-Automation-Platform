import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { NMessageProvider } from 'naive-ui';
import ReviewQueuePage from '@/pages/ReviewQueuePage.vue';
import type { ReviewQueueItem } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { formatDuration, formatSince } from '@/utils/format';

const item = (overrides: Partial<ReviewQueueItem> = {}): ReviewQueueItem => ({
    id: 12, subject: '¿Dónde está mi pedido #45821?', from: { email: 'cliente@cliente.test', name: 'Cliente Pérez' },
    status: 'human_review', status_label: 'Revisión humana', assignee: null, intent: 'order_status', intent_confidence: 0.92,
    summary: null, language: 'es', urgency: 'high', sentiment: 'negative', injection_suspected: false, automated_reason: null,
    sent_at: null, received_at: '2026-10-17T08:00:00Z',
    waiting_since: new Date(Date.now() - 2 * 3600_000).toISOString(), waiting_reason: 'Confianza baja (0.60 < 0.75).', has_draft: true,
    ...overrides,
});

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

function stubApi(): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
            : url.includes('/assignee') ? json({ data: {} })
                : json({
                    data: [
                        item(),
                        item({ id: 13, subject: 'Factura duplicada', urgency: 'normal', has_draft: false, assignee: { id: 1, name: 'Ana' } }),
                        item({ id: 14, subject: 'Envío fallido', status: 'failed', assignee: { id: 2, name: 'Bea' } }),
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 25, total: 3, counts: { total: 3, mine: 1, unassigned: 1, failed: 1 } },
                }),
    ));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountPage(permissions: string[], path = '/revision') {
    useAuthStore().user = { id: 1, name: 'Ana', email: 'ana@acme.test', role: 'agent', permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/revision', name: 'review', component: ReviewQueuePage },
            { path: '/emails/:id', name: 'email', component: { template: '<div>detalle</div>' } },
        ],
    });
    await router.push(path);
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(RouterView))), { global: { plugins: [router] } });
    await flushPromises();

    return { wrapper, router };
}

describe('bandeja de revisión', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('lista los casos con su espera, motivo, propuesta y quién los tiene', async () => {
        const fetchMock = stubApi();

        const { wrapper } = await mountPage(['emails.view', 'emails.review']);
        const text = wrapper.text();

        expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/review-queue?page=1&per_page=25');
        expect(text).toContain('Todos (3)');
        expect(text).toContain('Asignados a mí (1)');
        expect(text).toContain('Sin asignar (1)');
        expect(text).toContain('¿Dónde está mi pedido #45821?');
        expect(text).toContain('hace 2 h');
        expect(text).toContain('Urgente');
        expect(text).toContain('Confianza baja (0.60 < 0.75).');
        expect(text).toContain('De la IA');
        expect(text).toContain('Sin propuesta');
        expect(text).toContain('Error');
        expect(text).toContain('Tú');
        expect(text).toContain('Bea');
        expect(wrapper.find('[data-test="assign-12"]').exists()).toBe(true);
    });

    it('lee los filtros de la URL y se los pide a la API', async () => {
        const fetchMock = stubApi();

        await mountPage(['emails.view'], '/revision?scope=mine&intent=complaint&page=2');

        expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/review-queue?assigned=mine&intent=complaint&page=2&per_page=25');
    });

    it('ignora filtros inválidos de la URL', async () => {
        const fetchMock = stubApi();

        await mountPage(['emails.view'], '/revision?scope=todo&intent=inventada&page=-3');

        expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/review-queue?page=1&per_page=25');
    });

    it('asigna un caso a quien lo pulsa', async () => {
        const fetchMock = stubApi();
        const { wrapper } = await mountPage(['emails.view', 'emails.review']);

        await wrapper.find('[data-test="assign-12"]').trigger('click');
        await flushPromises();

        const call = fetchMock.mock.calls.find(([url]) => url === '/api/v1/emails/12/assignee');
        expect(call?.[1].method).toBe('PUT');
        expect(JSON.parse(call?.[1].body)).toEqual({ user_id: 1 });
    });

    it('sin permiso de revisión no ofrece asignarse', async () => {
        stubApi();

        const { wrapper } = await mountPage(['emails.view']);

        expect(wrapper.find('[data-test="assign-12"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Nadie');
    });

    it('escribe las esperas de forma corta', () => {
        expect(formatDuration(45)).toBe('45 s');
        expect(formatDuration(600)).toBe('10 min');
        expect(formatDuration(5400)).toBe('1 h 30 min');
        expect(formatDuration(7200)).toBe('2 h');
        expect(formatDuration(200_000)).toBe('2 d 7 h');
        expect(formatDuration(null)).toBe('—');
        expect(formatSince('2026-10-17T08:00:00Z', Date.parse('2026-10-17T08:05:00Z'))).toBe('hace 5 min');
    });
});
