import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { NMessageProvider } from 'naive-ui';
import WooCommerceCard from '@/components/orders/WooCommerceCard.vue';
import { saveWooCommerce, type IntegrationConnection } from '@/api/integrations';
import { useAuthStore } from '@/stores/auth';

const shop: IntegrationConnection = {
    type: 'woocommerce', base_url: 'https://tienda.acme.test', has_token: true, consumer_key_hint: '…cdef', orders: true, invoices: false,
    enabled: true, status: 'active', last_tested_at: '2026-10-19T08:00:00Z', last_error: null, updated_at: null,
};

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

function stubApi(current: IntegrationConnection | null): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 }) : json({ data: current, result: { ok: true, error: null, checks: {} } }),
    ));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountCard(permissions: string[]) {
    useAuthStore().user = { id: 1, name: 'Ana', email: 'a@b.test', role: 'admin', permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(WooCommerceCard))));
    await flushPromises();

    return wrapper;
}

describe('tienda WooCommerce', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('muestra la tienda conectada con el final de la clave, nunca el secreto', async () => {
        stubApi(shop);

        const wrapper = await mountCard(['orders.view', 'orders.manage']);

        expect(wrapper.text()).toContain('https://tienda.acme.test');
        expect(wrapper.text()).toContain('Conectada');
        expect(wrapper.text()).toContain('Clave …cdef');
        expect(wrapper.text()).toContain('Probar conexión');
    });

    it('sin permiso de gestión solo se ve', async () => {
        stubApi(shop);

        const wrapper = await mountCard(['orders.view']);

        expect(wrapper.text()).not.toContain('Probar conexión');
        expect(wrapper.text()).not.toContain('Editar');
    });

    it('al editar, las claves vacías no se envían (se conservan)', async () => {
        const fetchMock = stubApi(shop);

        await saveWooCommerce({ base_url: 'https://tienda.acme.test', consumer_key: '', consumer_secret: '', enabled: false });

        const [url, init] = fetchMock.mock.calls.find(([u]) => String(u).startsWith('/api/v1/integrations'))!;
        expect(url).toBe('/api/v1/integrations/woocommerce');
        expect(JSON.parse(String(init.body))).toEqual({ base_url: 'https://tienda.acme.test', enabled: false });
    });
});
