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
                    iterations: 2, tool_calls_count: 1, input_tokens: 2700, output_tokens: 300, cost_usd: 0.0478, duration_ms: 17400,
                    started_at: '2026-10-14T10:00:00Z', finished_at: '2026-10-14T10:00:17Z',
                    steps: [
                        {
                            id: 10, status: 'success', model: 'claude-opus-5-5', input_tokens: 1500, output_tokens: 100, cost_usd: 0.008, latency_ms: 6000, error: null,
                            tool_calls: [{ id: 5, tool: 'get_order', arguments: { order_number: '45821' }, result: { status: 'shipped' }, status: 'success', error: null, duration_ms: 12 }],
                        },
                        { id: 11, status: 'success', model: 'claude-opus-5-5', input_tokens: 1200, output_tokens: 200, cost_usd: 0.0398, latency_ms: 9000, error: null, tool_calls: [] },
                    ],
                }],
            },
        });

        expect(wrapper.text()).toContain('Respuesta final');
        expect(wrapper.text()).toContain('2 pasos · 1 herramientas');
        expect(wrapper.text()).toContain('0,0478 $');
        expect(wrapper.text()).toContain('17,4 s');
        expect(wrapper.get('[data-test="tool-call"]').text()).toContain('Consultar pedido');
        expect(wrapper.get('[data-test="tool-call"]').text()).toContain('{"order_number":"45821"}');
    });

    it('explica cuando el agente no se ejecutó', () => {
        expect(mount(AgentTrace, { props: { runs: [] } }).text()).toContain('El agente no se ha ejecutado');
    });
});
