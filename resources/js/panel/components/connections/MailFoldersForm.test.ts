import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { NMessageProvider } from 'naive-ui';
import MailFoldersForm from '@/components/connections/MailFoldersForm.vue';
import type { MailConnection, MailFolderInfo } from '@/api/mailConnections';

const connection = { id: 3, name: 'Soporte', email: 'soporte@demo.test', provider: 'imap', status: 'active' } as MailConnection;

const folders: MailFolderInfo[] = [
    { id: 'INBOX', name: 'Bandeja de entrada', role: 'inbox', selectable: false, synced: true, last_success_at: '2026-10-25T10:00:00Z', last_error: null },
    { id: 'Pedidos web', name: 'Pedidos web', role: null, selectable: true, synced: false, last_success_at: null, last_error: null },
    { id: 'Sent', name: 'Enviados', role: 'sent', selectable: false, synced: false, last_success_at: null, last_error: null },
];

function respond(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status });
}

async function mountForm(canManage = true, put?: (body: unknown) => Response) {
    const fetch = vi.fn().mockImplementation((url: string, init?: RequestInit) => {
        if (url === '/sanctum/csrf-cookie') {
            return Promise.resolve(new Response(null, { status: 204 }));
        }

        if (init?.method === 'PUT') {
            return Promise.resolve(put ? put(JSON.parse(String(init.body))) : respond({ data: folders }));
        }

        return Promise.resolve(respond({ data: folders }));
    });
    vi.stubGlobal('fetch', fetch);
    const Wrapped = defineComponent(() => () => h(NMessageProvider, () => h(MailFoldersForm, { connection, canManage })));
    const wrapper = mount(Wrapped);
    await flushPromises();

    return { wrapper, fetch };
}

describe('carpetas de un buzón', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('muestra las carpetas: la bandeja siempre, enviados nunca', async () => {
        const { wrapper } = await mountForm();
        const boxes = wrapper.findAll('.n-checkbox');

        expect(wrapper.text()).toContain('Bandeja de entrada');
        expect(wrapper.text()).toContain('Siempre se revisa.');
        expect(wrapper.text()).toContain('Nunca: son las respuestas de la propia empresa.');
        expect(boxes[0].classes()).toContain('n-checkbox--disabled');
        expect(boxes[1].classes()).not.toContain('n-checkbox--disabled');
        expect(boxes[2].classes()).toContain('n-checkbox--disabled');
    });

    it('guarda las carpetas elegidas', async () => {
        const sent: unknown[] = [];
        const { wrapper } = await mountForm(true, (body) => {
            sent.push(body);

            return respond({ data: folders });
        });

        await wrapper.findAll('.n-checkbox')[1].trigger('click');
        await wrapper.findAll('button').find((b) => b.text() === 'Guardar carpetas')!.trigger('click');
        await flushPromises();

        expect(sent).toEqual([{ folders: ['Pedidos web'] }]);
    });

    it('muestra el error de la API', async () => {
        const { wrapper } = await mountForm(true, () => respond({ message: 'Datos no válidos.', errors: { folders: ['Como máximo se sincronizan 1 carpetas por buzón.'] } }, 422));

        await wrapper.findAll('.n-checkbox')[1].trigger('click');
        await wrapper.findAll('button').find((b) => b.text() === 'Guardar carpetas')!.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Como máximo se sincronizan 1 carpetas por buzón.');
    });

    it('sin permiso solo deja mirar', async () => {
        const { wrapper } = await mountForm(false);

        expect(wrapper.findAll('.n-checkbox')[1].classes()).toContain('n-checkbox--disabled');
        expect(wrapper.text()).not.toContain('Guardar carpetas');
    });
});
