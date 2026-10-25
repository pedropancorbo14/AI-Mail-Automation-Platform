import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { NMessageProvider } from 'naive-ui';
import IntegrationsPage from '@/pages/IntegrationsPage.vue';
import { useAuthStore } from '@/stores/auth';

async function mountPage(permissions: string[]) {
    vi.stubGlobal('fetch', vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 }) : new Response(JSON.stringify({ data: null }), { status: 200 }),
    )));
    useAuthStore().user = { id: 1, name: 'Ana', email: 'a@b.test', role: 'agent', permissions, tenant: { id: 1, name: 'Clínica', slug: 'clinica' } };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }, { path: '/pedidos', name: 'orders', component: { template: '<div />' } }] });
    await router.push('/');
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(IntegrationsPage))), { global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('integraciones', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('reúne el sistema propio y la tienda, y explica qué fuente de pedidos gana', async () => {
        const wrapper = await mountPage(['integrations.view', 'orders.view']);

        expect(wrapper.text()).toContain('Tu propio sistema');
        expect(wrapper.text()).toContain('Tienda WooCommerce');
        expect(wrapper.text()).toContain('primero tu propio sistema, después WooCommerce y por último los pedidos del CSV');
        expect(wrapper.find('a[href="/pedidos"]').exists()).toBe(true);
    });

    it('sin acceso a los pedidos no enlaza a ellos', async () => {
        const wrapper = await mountPage(['integrations.view']);

        expect(wrapper.find('a[href="/pedidos"]').exists()).toBe(false);
    });
});
