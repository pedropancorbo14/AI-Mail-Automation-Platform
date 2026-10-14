import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { NDialogProvider, NMessageProvider } from 'naive-ui';
import MailConnectionsPage from '@/pages/MailConnectionsPage.vue';
import ImapConnectionForm from '@/components/connections/ImapConnectionForm.vue';
import { withoutEmptyPasswords, type MailConnection } from '@/api/mailConnections';
import { useAuthStore } from '@/stores/auth';

const connection: MailConnection = {
    id: 1,
    name: 'Soporte',
    email: 'soporte@demo.test',
    provider: 'imap',
    status: 'failing',
    settings: {
        imap: { host: 'imap.demo.test', port: 993, encryption: 'ssl', username: 'soporte@demo.test' },
        smtp: { host: 'smtp.demo.test', port: 465, encryption: 'ssl', username: 'soporte@demo.test' },
    },
    last_tested_at: '2026-10-14T10:00:00Z',
    last_error: 'El servidor rechazó el usuario o la contraseña.',
    created_at: '2026-10-01T10:00:00Z',
};

function respond(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status });
}

function loginAs(permissions: string[]): void {
    useAuthStore().user = { id: 1, name: 'Ana', email: 'ana@acme.test', role: 'admin', permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' } };
}

async function mountPage() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    await router.push('/');
    const Wrapped = defineComponent(() => () => h(NMessageProvider, () => h(NDialogProvider, () => h(MailConnectionsPage))));
    const wrapper = mount(Wrapped, { global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('buzones de correo', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.stubGlobal('fetch', vi.fn().mockImplementation(() => Promise.resolve(respond({ data: [connection], meta: { total: 1 } }))));
    });

    afterEach(() => vi.unstubAllGlobals());

    it('muestra cada buzón con su estado y el error si falla', async () => {
        loginAs(['mail_connections.view', 'mail_connections.manage']);

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Soporte');
        expect(wrapper.text()).toContain('IMAP/SMTP');
        expect(wrapper.text()).toContain('Falla');
        expect(wrapper.text()).toContain('El servidor rechazó el usuario o la contraseña.');
        expect(wrapper.text()).toContain('Añadir buzón IMAP');
        expect(wrapper.text()).toContain('Probar');
    });

    it('sin permiso de gestión solo deja mirar', async () => {
        loginAs(['mail_connections.view']);

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Soporte');
        expect(wrapper.text()).not.toContain('Añadir buzón IMAP');
        expect(wrapper.text()).not.toContain('Probar');
        expect(wrapper.text()).not.toContain('Eliminar');
    });
});

describe('formulario de buzón IMAP', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('rellena los usuarios con el email', async () => {
        const wrapper = mount(ImapConnectionForm);
        const email = wrapper.findAll('input')[1];

        await email.setValue('ventas@empresa.test');
        await email.trigger('blur');

        expect(wrapper.vm.form.imap.username).toBe('ventas@empresa.test');
        expect(wrapper.vm.form.smtp.username).toBe('ventas@empresa.test');
    });

    it('muestra los errores de validación de la API bajo cada campo', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(respond({ message: 'Los datos proporcionados no son válidos.', errors: { 'imap.host': ['El servidor apunta a una dirección interna no permitida.'] } }, 422)));

        const wrapper = mount(ImapConnectionForm);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('El servidor apunta a una dirección interna no permitida.');
    });

    it('al editar no envía las contraseñas vacías (se conservan las guardadas)', () => {
        const input = withoutEmptyPasswords({
            name: 'Soporte',
            email: 'soporte@demo.test',
            imap: { host: 'imap.demo.test', port: 993, encryption: 'ssl', username: 'u', password: '' },
            smtp: { host: 'smtp.demo.test', port: 465, encryption: 'ssl', username: 'u', password: 'nueva' },
        });

        expect(input.imap).not.toHaveProperty('password');
        expect(input.smtp.password).toBe('nueva');
    });
});
