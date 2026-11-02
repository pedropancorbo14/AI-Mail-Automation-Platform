import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import SignupPage from '@/pages/SignupPage.vue';
import LoginPage from '@/pages/LoginPage.vue';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

function stubApi(handlers: Record<string, () => Response>): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 }) : (handlers[url] ?? (() => json({}, 404)))(),
    ));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountPage(component: object, path = '/') {
    const router = createRouter({ history: createMemoryHistory(), routes: [
        { path: '/', component: { template: '<div />' } },
        { path: '/login', name: 'login', component: { template: '<div />' } },
        { path: '/registro', name: 'signup', component: { template: '<div />' } },
    ] });
    await router.push(path);
    const wrapper = mount(component, { global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('alta desde la web', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('crea la cuenta y dice que revise el email', async () => {
        const fetchMock = stubApi({
            '/api/v1/signup': () => json({ data: { enabled: true, trial_days: 14 } }),
        });
        const wrapper = await mountPage(SignupPage);
        expect(wrapper.text()).toContain('14 días de prueba gratis');

        fetchMock.mockImplementation((url: string, init?: RequestInit) => Promise.resolve(
            url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
                : init?.method === 'POST' ? json({ message: 'Si los datos son correctos, te hemos enviado un email.' }, 202)
                    : json({ data: { enabled: true, trial_days: 14 } }),
        ));
        const inputs = wrapper.findAll('input');
        await inputs[0].setValue('Ferretería López');
        await inputs[1].setValue('Lucía');
        await inputs[2].setValue('lucia@ferreteria.example');
        await inputs[3].setValue('una-contraseña-larga');
        await inputs[4].setValue('una-contraseña-larga');
        await wrapper.find('input[type="checkbox"]').exists()
            ? wrapper.find('input[type="checkbox"]').setValue(true)
            : wrapper.find('.n-checkbox').trigger('click');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(post![1].body)).toMatchObject({ company: 'Ferretería López', email: 'lucia@ferreteria.example', accept_terms: true, website: '' });
        expect(wrapper.find('[data-test="signup-sent"]').text()).toContain('te hemos enviado un email');
    });

    it('explica que no hay alta si está apagada', async () => {
        stubApi({ '/api/v1/signup': () => json({ data: { enabled: false, trial_days: 14 } }) });
        const wrapper = await mountPage(SignupPage);

        expect(wrapper.find('[data-test="signup-disabled"]').exists()).toBe(true);
        expect(wrapper.find('form').exists()).toBe(false);
    });
});

describe('entrada con el email sin confirmar', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('avisa del email confirmado y enseña el enlace de alta', async () => {
        stubApi({ '/api/v1/signup': () => json({ data: { enabled: true, trial_days: 14 } }) });
        const wrapper = await mountPage(LoginPage, '/login?verified=1');

        expect(wrapper.find('[data-test="login-notice"]').text()).toContain('Email confirmado');
        expect(wrapper.find('[data-test="signup-link"]').exists()).toBe(true);
    });

    it('ofrece otro enlace si el email no está confirmado', async () => {
        const fetchMock = stubApi({
            '/api/v1/signup': () => json({ data: { enabled: false, trial_days: 14 } }),
            '/api/v1/auth/session': () => json({ message: 'Confirma tu email antes de entrar.', code: 'email_unverified' }, 403),
            '/api/v1/auth/verify-email/resend': () => json({ message: 'Te hemos enviado otro enlace.' }, 202),
        });
        const wrapper = await mountPage(LoginPage, '/login');
        expect(wrapper.find('[data-test="signup-link"]').exists()).toBe(false);

        const inputs = wrapper.findAll('input');
        await inputs[0].setValue('lucia@ferreteria.example');
        await inputs[1].setValue('una-contraseña-larga');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-test="login-error"]').text()).toContain('Confirma tu email');
        await wrapper.find('[data-test="resend"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([url]) => url === '/api/v1/auth/verify-email/resend')).toBe(true);
        expect(wrapper.find('[data-test="login-notice"]').text()).toContain('otro enlace');
    });
});
