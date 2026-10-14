import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { NMessageProvider } from 'naive-ui';
import CompanyPage from '@/pages/CompanyPage.vue';
import { assignableRoles, updateUser } from '@/api/company';
import type { CurrentUser } from '@/stores/auth';
import { useAuthStore } from '@/stores/auth';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

const users = [
    { id: 1, name: 'Ana', email: 'ana@acme.test', role: 'admin', created_at: null },
    { id: 2, name: 'Olga', email: 'olga@acme.test', role: 'owner', created_at: null },
    { id: 3, name: 'Pablo', email: 'pablo@acme.test', role: 'agent', created_at: null },
];

function stubApi(): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
            : url.startsWith('/api/v1/tenant') ? json({ data: { id: 1, name: 'Acme', slug: 'acme', is_active: true, created_at: null } })
                : json({ data: users, meta: {} }),
    ));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountAs(role: CurrentUser['role'], permissions: string[]) {
    useAuthStore().user = { id: 1, name: 'Ana', email: 'ana@acme.test', role, permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(CompanyPage))));
    await flushPromises();

    return wrapper;
}

const rowOf = (wrapper: Awaited<ReturnType<typeof mountAs>>, name: string) =>
    wrapper.findAll('tbody tr').find((row) => row.text().includes(name))!;

describe('empresa y usuarios', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('un administrador gestiona a los demás, pero no a los propietarios ni se borra a sí mismo', async () => {
        stubApi();

        const wrapper = await mountAs('admin', ['tenant.view', 'users.view', 'users.create', 'users.update', 'users.delete']);

        expect(wrapper.text()).toContain('Ana (tú)');
        expect(wrapper.text()).toContain('Añadir usuario');
        expect(rowOf(wrapper, 'Pablo').text()).toContain('Editar');
        expect(rowOf(wrapper, 'Pablo').text()).toContain('Eliminar');
        expect(rowOf(wrapper, 'Olga').text()).not.toContain('Editar');
        expect(rowOf(wrapper, 'Ana (tú)').text()).not.toContain('Eliminar');
        // Sin tenant.update, el nombre de la empresa no se edita.
        expect(wrapper.find('.company__name').exists()).toBe(false);
    });

    it('un agente solo ve', async () => {
        stubApi();

        const wrapper = await mountAs('agent', ['tenant.view', 'users.view']);

        expect(wrapper.text()).toContain('Olga');
        expect(wrapper.text()).not.toContain('Añadir usuario');
        expect(wrapper.text()).not.toContain('Editar');
    });

    it('solo un propietario puede nombrar propietarios', () => {
        expect(assignableRoles('owner')).toEqual(['owner', 'admin', 'agent']);
        expect(assignableRoles('admin')).toEqual(['admin', 'agent']);
    });

    it('al editar no envía la contraseña vacía', async () => {
        const fetchMock = stubApi();

        await updateUser(3, { name: 'Pablo G.', role: 'admin', password: '' });

        const call = fetchMock.mock.calls.find(([url]) => url === '/api/v1/users/3');
        expect(JSON.parse(call?.[1].body)).toEqual({ name: 'Pablo G.', role: 'admin' });
    });
});
