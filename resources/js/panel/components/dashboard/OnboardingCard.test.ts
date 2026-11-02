import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import OnboardingCard from '@/components/dashboard/OnboardingCard.vue';
import { useAuthStore } from '@/stores/auth';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

const steps = [
    { key: 'mailbox', label: 'Conecta el buzón', description: 'IMAP u OAuth.', done: true, route: 'mail-connections' },
    { key: 'documents', label: 'Sube tu documentación', description: 'Políticas.', done: false, route: 'documents' },
];

async function mountCard(data: object, permissions: string[]) {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
            : url === '/api/v1/onboarding/dismiss' ? json({ data: { ...data, dismissed: true } })
                : json({ data }),
    ));
    vi.stubGlobal('fetch', fetchMock);
    useAuthStore().user = { id: 1, name: 'Olga', email: 'olga@acme.test', role: 'owner', permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const router = createRouter({ history: createMemoryHistory(), routes: ['mail-connections', 'documents'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })).concat([{ path: '/', name: 'home', component: { template: '<div />' } }]) });
    await router.push('/');
    const wrapper = mount(OnboardingCard, { global: { plugins: [router] } });
    await flushPromises();

    return { wrapper, fetchMock };
}

describe('primeros pasos', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('enseña los pasos con enlace a los que faltan y se puede ocultar', async () => {
        const { wrapper, fetchMock } = await mountCard({ steps, completed: 1, dismissed: false }, ['tenant.view', 'automation.manage']);

        expect(wrapper.text()).toContain('1 de 2 hechos');
        expect(wrapper.find('[data-test="step-documents"] a').attributes('href')).toBe('/documents');
        expect(wrapper.find('[data-test="step-mailbox"] a').exists()).toBe(false);

        await wrapper.find('[data-test="onboarding-dismiss"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([url]) => url === '/api/v1/onboarding/dismiss')).toBe(true);
        expect(wrapper.find('[data-test="onboarding"]').exists()).toBe(false);
    });

    it('no se ve terminada, oculta o sin permiso para ocultarla no da el botón', async () => {
        const done = await mountCard({ steps: steps.map((s) => ({ ...s, done: true })), completed: 2, dismissed: false }, ['tenant.view']);
        expect(done.wrapper.find('[data-test="onboarding"]').exists()).toBe(false);

        const agent = await mountCard({ steps, completed: 1, dismissed: false }, ['tenant.view']);
        expect(agent.wrapper.find('[data-test="onboarding"]').exists()).toBe(true);
        expect(agent.wrapper.find('[data-test="onboarding-dismiss"]').exists()).toBe(false);
    });
});
