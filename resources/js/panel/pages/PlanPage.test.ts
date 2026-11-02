import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import PlanPage from '@/pages/PlanPage.vue';
import { billingFixture } from '@/api/billing.fixture';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

async function mountWith(billing: ReturnType<typeof billingFixture>) {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ data: billing })));
    const wrapper = mount(PlanPage);
    await flushPromises();

    return wrapper;
}

describe('página de plan y uso', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('muestra el plan, su estado, el uso del mes y los planes', async () => {
        const wrapper = await mountWith(billingFixture());

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
        const wrapper = await mountWith(billingFixture({}, { status: 'canceled', status_label: 'Cancelada', ai_block_reason: 'La suscripción está cancelada: elige un plan para seguir usando la IA.' }));

        expect(wrapper.find('[data-test="ai-blocked"]').text()).toContain('La suscripción está cancelada');
        expect(wrapper.find('[data-test="ai-blocked"]').text()).toContain('los atiende tu equipo');
    });
});
