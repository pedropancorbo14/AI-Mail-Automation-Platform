import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { NMessageProvider } from 'naive-ui';
import StandardApiCard from '@/components/orders/StandardApiCard.vue';
import { saveStandardApi, type StandardApiConnection } from '@/api/integrations';
import { useAuthStore } from '@/stores/auth';

const connection = (overrides: Partial<StandardApiConnection> = {}): StandardApiConnection => ({
    type: 'standard_api', base_url: 'https://erp.acme.test/aimail', has_token: true, orders: true, invoices: true, enabled: true,
    status: 'failing', last_tested_at: '2026-10-19T08:00:00Z', last_error: 'El sistema de pedidos rechazó la clave.', updated_at: null,
    ...overrides,
});

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

function stubApi(current: StandardApiConnection | null): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 }) : json({ data: current, result: { ok: true, error: null, checks: {} } }),
    ));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountCard(permissions: string[]) {
    useAuthStore().user = { id: 1, name: 'Ana', email: 'a@b.test', role: 'admin', permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(StandardApiCard))));
    await flushPromises();

    return wrapper;
}

describe('sistema propio de la empresa', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('muestra la conexión, lo que tiene y por qué falla', async () => {
        stubApi(connection());

        const wrapper = await mountCard(['orders.view', 'orders.manage']);

        expect(wrapper.text()).toContain('https://erp.acme.test/aimail');
        expect(wrapper.text()).toContain('Falla');
        expect(wrapper.text()).toContain('Pedidos y Facturas');
        expect(wrapper.text()).toContain('rechazó la clave');
        expect(wrapper.text()).toContain('Probar conexión');
    });

    it('sin permiso de gestión solo se ve', async () => {
        stubApi(connection({ status: 'active', last_error: null }));

        const wrapper = await mountCard(['orders.view']);

        expect(wrapper.text()).toContain('Conectado');
        expect(wrapper.text()).not.toContain('Probar conexión');
        expect(wrapper.text()).not.toContain('Editar');
    });

    it('sin conexión ofrece conectarla', async () => {
        stubApi(null);

        const wrapper = await mountCard(['orders.view', 'orders.manage']);

        expect(wrapper.text()).toContain('Sin conectar.');
        expect(wrapper.text()).toContain('Conectar mi sistema');
    });

    it('al editar, una clave vacía no se envía (se conserva la guardada)', async () => {
        const fetchMock = stubApi(connection());

        await saveStandardApi({ base_url: 'https://erp.acme.test/aimail', token: '', orders: true, invoices: false, enabled: true });

        const [, init] = fetchMock.mock.calls.find(([url]) => String(url).startsWith('/api/v1/integrations'))!;
        expect(JSON.parse(String(init.body))).toEqual({ base_url: 'https://erp.acme.test/aimail', orders: true, invoices: false, enabled: true });
    });
});
