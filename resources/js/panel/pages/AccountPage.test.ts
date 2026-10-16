import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { NMessageProvider } from 'naive-ui';
import AccountPage from '@/pages/AccountPage.vue';
import { useAuthStore } from '@/stores/auth';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

function stubApi(response: Response): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) =>
        Promise.resolve(url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 }) : response),
    );
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountPage() {
    useAuthStore().user = { id: 1, name: 'Olga', email: 'olga@acme.test', role: 'agent', permissions: [], tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(AccountPage))));
    await flushPromises();

    return wrapper;
}

async function fillPassword(wrapper: Awaited<ReturnType<typeof mountPage>>, values: string[]) {
    const inputs = wrapper.findAll('input[type="password"]');
    for (const [index, value] of values.entries()) {
        await inputs[index].setValue(value);
    }
}

describe('mi cuenta', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('muestra quién es y su rol', async () => {
        stubApi(json({}));

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Olga');
        expect(wrapper.text()).toContain('olga@acme.test · Agente');
    });

    it('cambia la contraseña pidiendo la actual', async () => {
        const fetchMock = stubApi(json({ message: 'Contraseña cambiada.' }));
        const wrapper = await mountPage();

        await fillPassword(wrapper, ['password', 'una-clave-nueva-larga', 'una-clave-nueva-larga']);
        await wrapper.findAll('form')[0].trigger('submit');
        await flushPromises();

        const call = fetchMock.mock.calls.find(([url]) => url === '/api/v1/auth/password');
        expect(call?.[1].method).toBe('PUT');
        expect(JSON.parse(call?.[1].body)).toEqual({
            current_password: 'password', password: 'una-clave-nueva-larga', password_confirmation: 'una-clave-nueva-larga',
        });
        expect((wrapper.findAll('input[type="password"]')[0].element as HTMLInputElement).value).toBe('');
    });

    it('muestra el error bajo la contraseña actual si no es correcta', async () => {
        stubApi(json({ message: 'La contraseña actual no es correcta.', errors: { current_password: ['La contraseña actual no es correcta.'] } }, 422));
        const wrapper = await mountPage();

        await fillPassword(wrapper, ['mala', 'una-clave-nueva-larga', 'una-clave-nueva-larga']);
        await wrapper.findAll('form')[0].trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('La contraseña actual no es correcta.');
    });

    it('cierra las demás sesiones con la contraseña', async () => {
        const fetchMock = stubApi(json({ message: 'Se ha cerrado la sesión en los demás dispositivos.' }));
        const wrapper = await mountPage();

        await wrapper.findAll('input[type="password"]')[3].setValue('password');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();

        const call = fetchMock.mock.calls.find(([url]) => url === '/api/v1/auth/logout-other-devices');
        expect(JSON.parse(call?.[1].body)).toEqual({ password: 'password' });
    });
});
