import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent, h } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { NMessageProvider } from 'naive-ui';
import PlanPage from '@/pages/PlanPage.vue';
import { billingFixture } from '@/api/billing.fixture';
import { useAuthStore } from '@/stores/auth';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

async function mountWith(billing: ReturnType<typeof billingFixture>, permissions: string[] = ['tenant.view', 'billing.manage']) {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
            : url === '/api/v1/billing/checkout' ? json({ url: 'https://checkout.stripe.com/c/pay/cs_1' })
                : json({ data: billing }),
    ));
    vi.stubGlobal('fetch', fetchMock);
    useAuthStore().user = { id: 1, name: 'Olga', email: 'olga@acme.test', role: 'owner', permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/plan', name: 'plan', component: { template: '<div />' } }] });
    await router.push('/plan');
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(PlanPage))), { global: { plugins: [router] } });
    await flushPromises();

    return { wrapper, fetchMock };
}

describe('página de plan y uso', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('muestra el plan, su estado, el uso del mes y los planes', async () => {
        const { wrapper } = await mountWith(billingFixture());

        expect(wrapper.find('[data-test="plan-name"]').text()).toBe('Prueba');
        expect(wrapper.find('[data-test="plan-status"]').text()).toBe('En prueba');
        expect(wrapper.text()).toContain('La prueba termina el');
        expect(wrapper.find('[data-test="usage-mailboxes"]').text()).toContain('1 de 1');
        expect(wrapper.find('[data-test="usage-ai-capacity"]').text()).toContain('85 % usada');
        expect(wrapper.find('[data-test="plan-starter"]').text()).toContain('49 € / mes');
        expect(wrapper.find('[data-test="plan-trial"]').text()).toContain('Tu plan');
        expect(wrapper.find('[data-test="plan-enterprise"]').text()).toContain('Buzones: sin límite');
        // Sin dinero de la IA: solo porcentajes.
        expect(wrapper.text()).not.toContain('$');
        expect(wrapper.find('[data-test="ai-blocked"]').exists()).toBe(false);
    });

    it('explica por qué la IA está parada', async () => {
        const { wrapper } = await mountWith(billingFixture({}, { status: 'canceled', status_label: 'Cancelada', ai_block_reason: 'La suscripción está cancelada: elige un plan para seguir usando la IA.' }));

        expect(wrapper.find('[data-test="ai-blocked"]').text()).toContain('La suscripción está cancelada');
        expect(wrapper.find('[data-test="ai-blocked"]').text()).toContain('los atiende tu equipo');
    });

    it('el propietario contrata un plan en la página de pago de Stripe', async () => {
        const assign = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign });
        const { wrapper, fetchMock } = await mountWith(billingFixture());

        expect(wrapper.find('[data-test="buy-trial"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="buy-enterprise"]').exists()).toBe(false);
        await wrapper.find('[data-test="buy-starter"] button, button[data-test="buy-starter"]').trigger('click');
        await flushPromises();

        const call = fetchMock.mock.calls.find(([url]) => url === '/api/v1/billing/checkout');
        expect(JSON.parse(call![1].body)).toEqual({ plan: 'starter' });
        expect(assign).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/cs_1');
    });

    it('quien ya paga va al portal y quien no es propietario no puede contratar', async () => {
        const paying = billingFixture();
        paying.billing.has_subscription = true;
        const { wrapper } = await mountWith(paying);
        expect(wrapper.find('[data-test="portal"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="buy-starter"]').exists()).toBe(false);

        const { wrapper: admin } = await mountWith(billingFixture(), ['tenant.view']);
        expect(admin.find('[data-test="buy-starter"]').exists()).toBe(false);
        expect(admin.text()).toContain('Solo el propietario');
    });
});
