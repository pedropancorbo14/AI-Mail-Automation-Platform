import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AgentTrace from '@/components/email/AgentTrace.vue';
import ReplyCard from '@/components/email/ReplyCard.vue';
import { replyFixture } from '@/components/email/fixtures';

describe('respuesta propuesta', () => {
    it('muestra el texto, cada motivo de revisión por separado, fuentes y acciones', () => {
        const wrapper = mount(ReplyCard, { props: { reply: replyFixture() } });

        expect(wrapper.get('[data-test="reply-body"]').text()).toContain('Tu pedido salió el 29 de septiembre');
        expect(wrapper.findAll('[data-test="reply-reasons"] li').map((li) => li.text())).toEqual([
            'La IA lo pide: el pedido va con retraso.',
            'Confianza baja (0.60 < 0.75).',
            'Requiere acciones de una persona: Abrir incidencia con SEUR.',
        ]);
        expect(wrapper.text()).toContain('60 %');
        expect(wrapper.text()).toContain('Pedido');
        expect(wrapper.text()).toContain('Envíos y entregas, sección «Retrasos»');
        expect(wrapper.text()).toContain('Lleva 13 días de retraso.');
        expect(wrapper.text()).toContain('Borrador');
    });

    it('indica cuándo y a quién se envió sola', () => {
        const wrapper = mount(ReplyCard, {
            props: { reply: replyFixture({ status: 'sent', needs_human: false, needs_human_reason: null, sent_to: 'elena@cliente.test', sent_at: '2026-10-14T10:05:00Z', sent_automatically: true }) },
        });

        expect(wrapper.find('[data-test="reply-reasons"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('a elena@cliente.test (automáticamente)');
        expect(wrapper.text()).toContain('Enviada');
    });

    it('explica cuando no hay propuesta', () => {
        expect(mount(ReplyCard, { props: { reply: null } }).text()).toContain('La IA no ha preparado respuesta');
    });
});

describe('traza del agente', () => {
    it('muestra los pasos y las herramientas que usó la IA', () => {
        const wrapper = mount(AgentTrace, {
            props: {
                runs: [{
                    id: 1, status: 'completed', stop_reason: 'end_turn', stop_reason_label: 'Respuesta final', error: null,
                    iterations: 2, tool_calls_count: 1, duration_ms: 17400,
                    started_at: '2026-10-14T10:00:00Z', finished_at: '2026-10-14T10:00:17Z',
                    steps: [
                        {
                            id: 10, status: 'success', model: 'claude-opus-5-5', latency_ms: 6000, error: null,
                            tool_calls: [{ id: 5, tool: 'get_order', arguments: { order_number: '45821' }, result: { status: 'shipped' }, status: 'success', error: null, duration_ms: 12 }],
                        },
                        { id: 11, status: 'success', model: 'claude-opus-5-5', latency_ms: 9000, error: null, tool_calls: [] },
                    ],
                }],
            },
        });

        expect(wrapper.text()).toContain('Respuesta final');
        expect(wrapper.text()).toContain('2 pasos · 1 herramientas');
        // Costes y tokens son del operador (Grafana): el cliente no los ve.
        expect(wrapper.text()).not.toMatch(/\$|tokens/);
        expect(wrapper.text()).toContain('17,4 s');
        expect(wrapper.get('[data-test="tool-call"]').text()).toContain('Consultar pedido');
        expect(wrapper.get('[data-test="tool-call"]').text()).toContain('{"order_number":"45821"}');
    });

    it('muestra los especialistas de ai-service y quién decidió cada consulta', () => {
        const wrapper = mount(AgentTrace, {
            props: {
                runs: [{
                    id: 2, status: 'completed', stop_reason: 'end_turn', stop_reason_label: 'Respuesta final', error: null,
                    iterations: 1, tool_calls_count: 2, duration_ms: 4200, specialists: ['support', 'orders'],
                    started_at: '2026-10-17T10:00:00Z', finished_at: '2026-10-17T10:00:04Z',
                    steps: [{
                        id: 20, status: 'success', model: 'claude-opus-5-5', latency_ms: 3000, error: null,
                        tool_calls: [
                            { id: 7, tool: 'get_customer_tickets', arguments: { limit: 5 }, result: { tickets: [] }, status: 'success', origin: 'plan', error: null, duration_ms: 40 },
                            { id: 8, tool: 'get_invoice', arguments: { invoice_number: 'FAC-1' }, result: {}, status: 'success', origin: 'model', error: null, duration_ms: 35 },
                        ],
                    }],
                }],
            },
        });

        expect(wrapper.get('[data-test="specialists"]').text()).toBe('Especialistas:Soporte (principal)Pedidos');
        const calls = wrapper.findAll('[data-test="tool-call"]');
        expect(calls[0].find('[data-test="tool-origin"]').exists()).toBe(false); // el plan no se marca
        expect(calls[1].get('[data-test="tool-origin"]').text()).toBe('Pedida por la IA');
        expect(calls[1].text()).toContain('Consultar factura');
    });

    it('nombra cada tipo de fuente citada', () => {
        const reply = replyFixture({ sources: [{ type: 'invoice', reference: 'FAC-1' }, { type: 'ticket', reference: 'TCK-1' }] });

        expect(mount(ReplyCard, { props: { reply } }).text()).toMatch(/Factura\s*FAC-1.*Ticket\s*TCK-1/s);
    });

    it('explica cuando el agente no se ejecutó', () => {
        expect(mount(AgentTrace, { props: { runs: [] } }).text()).toContain('El agente no se ha ejecutado');
    });
});
