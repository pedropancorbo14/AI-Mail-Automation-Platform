import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { NMessageProvider } from 'naive-ui';
import OrdersPage from '@/pages/OrdersPage.vue';
import { ApiError } from '@/api/http';
import { rowErrors, type CustomerOrder } from '@/api/orders';
import { useAuthStore } from '@/stores/auth';

const order = (overrides: Partial<CustomerOrder> = {}): CustomerOrder => ({
    id: 1, number: '45821', customer_email: 'ana@cliente.test', customer_name: 'Ana Pérez', status: 'shipped',
    placed_at: '2026-09-28T10:12:00Z', total: 89.9, currency: 'EUR', items: [], shipment: { carrier: 'SEUR', tracking_number: 'SEU1' },
    shipping_city: 'Zaragoza', shipping_country: 'ES', updated_at: '2026-10-19T08:00:00Z',
    ...overrides,
});

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

function stubApi(): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
            : url.startsWith('/api/v1/integrations') ? json({ data: null })
            : json({
                data: [order()],
                meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, total_orders: 1, last_imported_at: '2026-10-19T08:00:00Z' },
            }),
    ));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountPage(permissions: string[]) {
    useAuthStore().user = { id: 1, name: 'Ana', email: 'a@b.test', role: 'admin', permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }, { path: '/integraciones', name: 'integrations', component: { template: '<div />' } }] });
    await router.push('/');
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(OrdersPage))), { global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('pedidos importados', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('muestra cada pedido con su estado en español y el envío', async () => {
        stubApi();

        const wrapper = await mountPage(['orders.view', 'orders.manage']);

        expect(wrapper.text()).toContain('45821');
        expect(wrapper.text()).toContain('Ana Pérez');
        expect(wrapper.text()).toContain('Enviado');
        expect(wrapper.text()).toContain('SEUR · SEU1');
        expect(wrapper.text()).toContain('Importar CSV');
        expect(wrapper.find('[data-test="template"]').attributes('href')).toBe('/api/v1/orders/template');
    });

    it('sin permiso de gestión no deja importar, descargar la plantilla ni borrar', async () => {
        stubApi();

        const wrapper = await mountPage(['orders.view']);

        expect(wrapper.text()).not.toContain('Importar CSV');
        expect(wrapper.text()).not.toContain('Borrar todos');
        expect(wrapper.find('[data-test="template"]').exists()).toBe(false);
    });

    it('lee los errores por fila de un CSV rechazado', () => {
        const error = new ApiError(422, '2 filas tienen errores', {}, {
            rows: [{ row: 3, errors: ['falta el estado.'] }],
            total_row_errors: 2,
        });

        expect(rowErrors(error)).toEqual({ rows: [{ row: 3, errors: ['falta el estado.'] }], total: 2 });
        expect(rowErrors(new ApiError(500, 'error'))).toEqual({ rows: [], total: 0 });
    });
});
