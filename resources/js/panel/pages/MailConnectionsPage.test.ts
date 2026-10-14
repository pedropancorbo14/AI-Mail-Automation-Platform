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

/** API simulada que responde según la URL (la cookie CSRF puede pedirse o no según el estado del cliente). */
function stubApi(answer: (url: string) => Response): void {
    vi.stubGlobal('fetch', vi.fn().mockImplementation((url: string) =>
        Promise.resolve(url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 }) : answer(url))));
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

    /** El cliente escribe su email y sale del campo. */
    async function typeEmail(email: string, detected: object) {
        stubApi(() => respond({ data: detected }));

        const wrapper = mount(ImapConnectionForm);
        const input = wrapper.findAll('input')[1];
        await input.setValue(email);
        await input.trigger('blur');
        await flushPromises();

        return wrapper;
    }

    const notFound = { found: false, source: null, provider_hint: null, imap: null, smtp: null };

    it('rellena los usuarios con el email', async () => {
        const wrapper = await typeEmail('ventas@empresa.test', notFound);

        expect(wrapper.vm.form.imap.username).toBe('ventas@empresa.test');
        expect(wrapper.vm.form.smtp.username).toBe('ventas@empresa.test');
        expect(wrapper.get('[data-test="detect-result"]').text()).toContain('escríbela a mano');
    });

    it('propone servidores, puertos y cifrado a partir del email', async () => {
        const wrapper = await typeEmail('ana@gmx.es', {
            found: true, source: 'ispdb', provider_hint: null,
            imap: { host: 'imap.gmx.com', port: 993, encryption: 'ssl', username: 'ana@gmx.es' },
            smtp: { host: 'mail.gmx.com', port: 587, encryption: 'starttls', username: 'ana@gmx.es' },
        });

        expect(wrapper.vm.form.imap).toMatchObject({ host: 'imap.gmx.com', port: 993, encryption: 'ssl' });
        expect(wrapper.vm.form.smtp).toMatchObject({ host: 'mail.gmx.com', port: 587, encryption: 'starttls' });
        expect(wrapper.get('[data-test="detect-result"]').text()).toContain('base de datos de Thunderbird');
    });

    it('recomienda OAuth si el correo está en Microsoft 365', async () => {
        const wrapper = await typeEmail('ana@empresa.test', { ...notFound, provider_hint: 'microsoft' });

        expect(wrapper.get('[data-test="provider-hint"]').text()).toContain('Conectar Microsoft 365');
    });

    it('muestra los errores de validación de la API bajo cada campo', async () => {
        stubApi(() => respond({ message: 'Los datos proporcionados no son válidos.', errors: { 'imap.host': ['El servidor apunta a una dirección interna no permitida.'] } }, 422));

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
