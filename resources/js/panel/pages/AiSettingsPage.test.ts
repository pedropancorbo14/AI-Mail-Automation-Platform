import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { NDialogProvider, NMessageProvider } from 'naive-ui';
import AiSettingsPage from '@/pages/AiSettingsPage.vue';
import type { AutomationSettings } from '@/api/automation';
import { useAuthStore } from '@/stores/auth';

const settings: AutomationSettings = {
    mode: 'draft',
    modes: [
        { value: 'off', label: 'Desactivada', description: 'Sin IA.' },
        { value: 'draft', label: 'Borradores', description: 'Una persona revisa siempre.' },
        { value: 'auto', label: 'Automática', description: 'Se envían solas las seguras.' },
    ],
    auto_send_intents: ['order_status'],
    auto_send_intents_custom: false,
    intents: [
        { value: 'order_status', allows_auto_send: true },
        { value: 'complaint', allows_auto_send: false },
    ],
    tools: [
        { tool: 'search_company_documents', enabled: true, available: true, source: 'default' },
        { tool: 'get_order', enabled: false, available: true, source: 'default' },
        { tool: 'get_invoice', enabled: false, available: false, source: 'default' },
        { tool: 'get_customer_tickets', enabled: true, available: null, source: 'company' },
    ],
    platform: { ai_configured: true, auto_send_enabled: true, model: 'claude-opus-5-5', min_confidence: 0.75, max_auto_replies_per_address: 3 },
};

// La página usa diálogos y mensajes de Naive UI: necesita sus proveedores.
const Wrapped = defineComponent(() => () => h(NMessageProvider, () => h(NDialogProvider, () => h(AiSettingsPage))));

function loginAs(permissions: string[]): void {
    useAuthStore().user = {
        id: 1, name: 'Ana', email: 'ana@acme.test', role: 'admin', permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' },
    };
}

describe('configuración de la IA', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        setActivePinia(createPinia());
        fetchMock = vi.fn().mockImplementation(() => Promise.resolve(new Response(JSON.stringify({ data: settings }), { status: 200 })));
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    it('deja ver la configuración pero no cambiarla sin el permiso', async () => {
        loginAs(['tenant.view']);

        const wrapper = mount(Wrapped);
        await flushPromises();

        expect(wrapper.text()).toContain('Solo los propietarios y administradores pueden cambiar');
        expect(wrapper.text()).toContain('Borradores');
        expect(wrapper.get('[data-test="tool-get_order"]').classes()).toContain('n-switch--disabled');
        expect(wrapper.text()).not.toContain('Guardar intenciones');
    });

    it('activa una herramienta para la empresa', async () => {
        loginAs(['tenant.view', 'automation.manage']);

        const wrapper = mount(Wrapped);
        await flushPromises();
        await wrapper.get('[data-test="tool-get_order"]').trigger('click');
        await flushPromises();

        const call = fetchMock.mock.calls.find(([url]) => url === '/api/v1/automation/tools/get_order');
        expect(call?.[1].method).toBe('PATCH');
        expect(call?.[1].body).toBe('{"enabled":true}');
    });

    it('marca las intenciones que nunca se responden solas', async () => {
        loginAs(['tenant.view', 'automation.manage']);

        const wrapper = mount(Wrapped);
        await flushPromises();

        expect(wrapper.text()).toMatch(/Queja\s+· siempre una persona/);
    });

    it('distingue herramientas sin datos de disponibilidad desconocida (ai-service no responde)', async () => {
        loginAs(['tenant.view', 'automation.manage']);

        const wrapper = mount(Wrapped);
        await flushPromises();

        expect(wrapper.get('[data-test="tool-get_invoice"]').classes()).toContain('n-switch--disabled');
        expect(wrapper.text()).toContain('sin datos configurados');
        // Desconocida: se puede activar y se ve activada.
        const tickets = wrapper.get('[data-test="tool-get_customer_tickets"]');
        expect(tickets.classes()).not.toContain('n-switch--disabled');
        expect(tickets.classes()).toContain('n-switch--active');
        expect(wrapper.text()).toContain('disponibilidad desconocida');
    });
});
