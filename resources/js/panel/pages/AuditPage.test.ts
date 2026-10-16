import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AuditPage from '@/pages/AuditPage.vue';
import type { AuditLogEntry } from '@/api/audit';
import { actorName, describeChanges, describeMetadata, subjectLabel } from '@/utils/audit';

const entry = (overrides: Partial<AuditLogEntry> = {}): AuditLogEntry => ({
    id: 1, action: 'user.updated', actor: { id: 1, name: 'Olga', email: 'olga@acme.test' },
    subject: { type: 'User', id: 7, label: 'Lucía' }, changes: null, metadata: null,
    ip: '203.0.113.9', user_agent: 'Firefox', request_id: 'abc12345', created_at: '2026-10-16T10:00:00Z',
    ...overrides,
});

const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200 });

describe('auditoría', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('explica los cambios en español, sin mostrar secretos', () => {
        expect(describeChanges(entry({
            changes: {
                role: { from: 'agent', to: 'admin' },
                password: '[oculto]',
                credentials: '[oculto]',
                auto_send_intents: { from: null, to: ['order_status', 'product_question'] },
                automation_mode: { from: 'draft', to: 'auto' },
            },
        }))).toEqual([
            'Rol: Agente → Administrador',
            'Contraseña: cambiada',
            'Credenciales: cambiadas',
            'Se envían solas: las de la plataforma → Estado de pedido, Pregunta de producto',
            'Modo: Borradores → Automática',
        ]);
    });

    it('describe los datos extra de cada acción', () => {
        expect(describeMetadata(entry({ action: 'auth.login', metadata: { method: 'token', device_name: 'crm' } }))).toEqual(['Con token de API «crm»']);
        expect(describeMetadata(entry({ action: 'auth.login_failed', metadata: { email: 'olga@acme.test' } }))).toEqual(['Con el email olga@acme.test']);
        expect(describeMetadata(entry({ action: 'automation.tool_changed', metadata: { tool: 'get_order', enabled: false } })))
            .toEqual([expect.stringContaining('desactivada')]);
    });

    it('no repite el sujeto cuando ya lo dice la acción', () => {
        expect(subjectLabel(entry())).toBe('Lucía');
        expect(subjectLabel(entry({ action: 'auth.login', subject: { type: 'User', id: 1, label: 'olga@acme.test' } }))).toBeNull();
        expect(subjectLabel(entry({ action: 'automation.tool_changed', subject: { type: null, id: null, label: 'get_order' } }))).toBeNull();
    });

    it('nombra a quién actuó, también si ya no existe o no tenía cuenta', () => {
        expect(actorName(entry())).toBe('Olga');
        expect(actorName(entry({ actor: { id: 3, name: null, email: 'ex@acme.test' } }))).toBe('ex@acme.test (eliminado)');
        expect(actorName(entry({ action: 'auth.login_failed', actor: null }))).toBe('Desconocido');
    });

    it('lista las acciones con quién, qué y desde dónde', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
            url.startsWith('/api/v1/users') ? json({ data: [], meta: {} })
                : json({
                    data: [
                        entry({ changes: { role: { from: 'agent', to: 'admin' } } }),
                        entry({ id: 2, action: 'auth.login_failed', actor: null, subject: null, metadata: { email: 'olga@acme.test' } }),
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
                }),
        ));
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(AuditPage);
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/audit-logs?page=1');
        const text = wrapper.text();
        expect(text).toContain('Modificó un usuario');
        expect(text).toContain('Lucía');
        expect(text).toContain('Rol: Agente → Administrador');
        expect(text).toContain('203.0.113.9');
        expect(text).toContain('Intento de inicio de sesión fallido');
        expect(text).toContain('Desconocido');
        expect(text).toContain('2 acciones');
    });
});
