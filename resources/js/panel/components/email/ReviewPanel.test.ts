import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { NMessageProvider } from 'naive-ui';
import ReviewPanel from '@/components/email/ReviewPanel.vue';
import ReplyCard from '@/components/email/ReplyCard.vue';
import { emailFixture, replyFixture } from '@/components/email/fixtures';
import type { EmailDetail } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { describeMetadata } from '@/utils/audit';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

function stubApi(response: unknown = { data: emailFixture({ status: 'sent', awaiting_person: false }) }, status = 200): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
            : url.includes('/review-checks') ? json({ data: { warnings: ['La respuesta menciona pedidos que no aparecen en el email ni en los datos consultados: #99999.'] } })
                : url.startsWith('/api/v1/review-queue') ? json({ data: [], meta: { counts: { total: 0, mine: 0, unassigned: 0, failed: 0 } } })
                    : json(response, status),
    ));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function mountPanel(email: EmailDetail) {
    useAuthStore().user = { id: 1, name: 'Ana', email: 'ana@acme.test', role: 'agent', permissions: ['emails.view', 'emails.review'], tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const updated = vi.fn();
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(ReviewPanel, { email, onUpdated: updated }))), { attachTo: document.body });

    return { wrapper, updated };
}

/** Pulsa el botón y confirma el diálogo de confirmación (NPopconfirm se pinta en el body). */
async function clickAndConfirm(wrapper: ReturnType<typeof mount>, selector: string): Promise<void> {
    await wrapper.find(selector).trigger('click');
    await flushPromises();
    const confirm = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Enviar' || b.textContent?.trim() === 'Reprocesar');
    confirm?.click();
    await flushPromises();
}

const call = (fetchMock: ReturnType<typeof vi.fn>, path: string) => fetchMock.mock.calls.find(([url]) => url === path);

describe('revisión humana del email', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('parte del texto de la IA y lo aprueba tal cual', async () => {
        const fetchMock = stubApi();
        const { wrapper, updated } = mountPanel(emailFixture({ replies: [replyFixture()] }));

        expect((wrapper.find('[data-test="review-text"] textarea').element as HTMLTextAreaElement).value).toBe(replyFixture().body);
        expect(wrapper.find('[data-test="review-send"]').text()).toBe('Aprobar y enviar');
        expect(wrapper.text()).toContain('Cliente Pérez <cliente@cliente.test>');

        await clickAndConfirm(wrapper, '[data-test="review-send"]');

        const approve = call(fetchMock, '/api/v1/emails/12/replies/1/approve');
        expect(approve?.[1].method).toBe('POST');
        expect(JSON.parse(approve?.[1].body)).toEqual({});
        expect(updated).toHaveBeenCalledOnce();
    });

    it('envía el texto corregido y avisa de lo que no sale de ningún dato', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const fetchMock = stubApi();
        const { wrapper } = mountPanel(emailFixture({ replies: [replyFixture()] }));

        await wrapper.find('[data-test="review-text"] textarea').setValue('Hola: tu pedido #99999 llega mañana.');
        await vi.advanceTimersByTimeAsync(700);
        await flushPromises();
        vi.useRealTimers();

        expect(wrapper.find('[data-test="review-send"]').text()).toBe('Enviar con mis cambios');
        expect(wrapper.get('[data-test="review-warnings"]').text()).toContain('#99999');
        expect(JSON.parse(call(fetchMock, '/api/v1/emails/12/review-checks')?.[1].body)).toEqual({ body: 'Hola: tu pedido #99999 llega mañana.', reply_id: 1 });

        await clickAndConfirm(wrapper, '[data-test="review-send"]');

        expect(JSON.parse(call(fetchMock, '/api/v1/emails/12/replies/1/approve')?.[1].body)).toEqual({ body: 'Hola: tu pedido #99999 llega mañana.' });
    });

    it('muestra los avisos de la propuesta de la IA', () => {
        stubApi();
        const { wrapper } = mountPanel(emailFixture({ replies: [replyFixture({ checks: ['La respuesta incluye enlaces que no son de la empresa: phishing.test.'] })] }));

        expect(wrapper.get('[data-test="review-warnings"]').text()).toContain('phishing.test');
    });

    it('sin propuesta, la persona escribe y envía su respuesta', async () => {
        const fetchMock = stubApi();
        const { wrapper } = mountPanel(emailFixture({ replies: [replyFixture({ status: 'rejected' })] }));

        expect(wrapper.text()).toContain('No hay propuesta de la IA');
        expect(wrapper.find('[data-test="review-reject"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="review-send"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-test="review-text"] textarea').setValue('Hola, lo miro hoy mismo.');
        await clickAndConfirm(wrapper, '[data-test="review-send"]');

        expect(JSON.parse(call(fetchMock, '/api/v1/emails/12/replies')?.[1].body)).toEqual({ body: 'Hola, lo miro hoy mismo.' });
    });

    it('descarta la propuesta con un motivo', async () => {
        const fetchMock = stubApi();
        const { wrapper } = mountPanel(emailFixture({ replies: [replyFixture()] }));

        await wrapper.find('[data-test="review-reject"]').trigger('click');
        await flushPromises();
        const reason = document.body.querySelector('[data-test="review-reason"] textarea') as HTMLTextAreaElement;
        reason.value = 'El cliente pide la factura.';
        reason.dispatchEvent(new Event('input'));
        Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Descartar')?.click();
        await flushPromises();

        expect(JSON.parse(call(fetchMock, '/api/v1/emails/12/replies/1/reject')?.[1].body)).toEqual({ reason: 'El cliente pide la factura.' });
    });

    it('reprocesa con la IA, pero no si ya se intentó un envío', async () => {
        const fetchMock = stubApi();
        const { wrapper } = mountPanel(emailFixture({ replies: [replyFixture()] }));

        await clickAndConfirm(wrapper, '[data-test="review-reprocess"]');
        expect(call(fetchMock, '/api/v1/emails/12/reprocess')).toBeDefined();

        const { wrapper: failed } = mountPanel(emailFixture({ status: 'failed', send_attempted: true, replies: [replyFixture({ status: 'failed', send_error: '550' })] }));
        expect(failed.find('[data-test="send-attempted"]').exists()).toBe(true);
        expect(failed.find('[data-test="review-reprocess"]').exists()).toBe(false);
    });

    it('reprocesa con una indicación para la IA solo si hay ai-service', async () => {
        const fetchMock = stubApi();
        const { wrapper } = mountPanel(emailFixture({ can_instruct: true, replies: [replyFixture()] }));

        await wrapper.find('[data-test="review-reprocess"]').trigger('click');
        await flushPromises();
        const instruction = document.body.querySelector('[data-test="review-instruction"] textarea') as HTMLTextAreaElement;
        instruction.value = 'Ofrece cambiarla por otra nueva.';
        instruction.dispatchEvent(new Event('input'));
        Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Reprocesar')?.click();
        await flushPromises();

        expect(JSON.parse(call(fetchMock, '/api/v1/emails/12/reprocess')?.[1].body)).toEqual({ instruction: 'Ofrece cambiarla por otra nueva.' });

        document.body.innerHTML = '';
        const { wrapper: laravel } = mountPanel(emailFixture({ can_instruct: false, replies: [replyFixture()] }));
        await laravel.find('[data-test="review-reprocess"]').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-test="review-instruction"]')).toBeNull();
    });

    it('enseña la acción que propone la IA y la aprueba o la rechaza', async () => {
        const fetchMock = stubApi();
        const pending = {
            tool: 'create_support_ticket',
            summary: 'Crear un ticket de soporte «La lámpara no enciende» (prioridad normal) para cliente@cliente.test.',
            arguments: { subject: 'La lámpara no enciende', description: 'No enciende.', priority: 'normal' },
            proposed_at: '2026-10-17T10:00:00Z',
        };
        const { wrapper, updated } = mountPanel(emailFixture({ pending_action: pending }));

        const block = wrapper.get('[data-test="pending-action"]');
        expect(block.text()).toContain('Abrir un ticket de soporte');
        expect(block.text()).toContain('La lámpara no enciende');
        expect(block.text()).toContain('Prioridad');

        await wrapper.find('[data-test="action-approve"]').trigger('click');
        await flushPromises();
        Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Aprobar')?.click();
        await flushPromises();
        expect(call(fetchMock, '/api/v1/emails/12/action/approve')?.[1].method).toBe('POST');
        expect(updated).toHaveBeenCalledOnce();

        await wrapper.find('[data-test="action-reject"]').trigger('click');
        await flushPromises();
        expect(call(fetchMock, '/api/v1/emails/12/action/reject')).toBeDefined();

        expect(describeMetadata({ action: 'email.action_approved', metadata: { tool: 'create_support_ticket' } } as never)).toContain('Abrir un ticket de soporte');
    });

    it('se asigna el caso y lo libera', async () => {
        const fetchMock = stubApi();
        const { wrapper } = mountPanel(emailFixture({ assignee: { id: 2, name: 'Bea' } }));

        expect(wrapper.get('[data-test="assignee"]').text()).toBe('Asignado a Bea');
        await wrapper.find('[data-test="assign-me"]').trigger('click');
        await flushPromises();
        expect(JSON.parse(call(fetchMock, '/api/v1/emails/12/assignee')?.[1].body)).toEqual({ user_id: 1 });

        const { wrapper: mine } = mountPanel(emailFixture({ assignee: { id: 1, name: 'Ana' } }));
        expect(mine.get('[data-test="assignee"]').text()).toBe('Asignado a ti');
        expect(mine.find('[data-test="unassign"]').exists()).toBe(true);
    });

    it('enseña el error de la API si otra persona se adelantó', async () => {
        stubApi({ message: 'Este email ya no está esperando a una persona: recarga la página para ver su estado.' }, 409);
        const { wrapper, updated } = mountPanel(emailFixture({ replies: [replyFixture()] }));

        await clickAndConfirm(wrapper, '[data-test="review-send"]');

        expect(updated).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('ya no está esperando a una persona');
    });
});

describe('respuesta ya revisada', () => {
    it('dice quién la escribió o la corrigió, y guarda el texto original de la IA', () => {
        const wrapper = mount(ReplyCard, {
            props: { reply: replyFixture({ status: 'sent', body: 'Texto de Ana', original_body: 'Texto de la IA', edited_by: { id: 1, name: 'Ana' }, approved_by: { id: 1, name: 'Ana' }, sent_at: '2026-10-17T10:00:00Z', sent_to: 'cliente@cliente.test' }) },
        });

        expect(wrapper.get('[data-test="reply-body"]').text()).toBe('Texto de Ana');
        expect(wrapper.get('[data-test="reply-original"]').text()).toContain('Corregida por Ana');
        expect(wrapper.text()).toContain('(aprobada por Ana)');

        const own = mount(ReplyCard, { props: { reply: replyFixture({ author: { id: 1, name: 'Ana' }, confidence: null, needs_human: false, needs_human_reason: null }) } });
        expect(own.text()).toContain('Respuesta de Ana');
        expect(own.text()).not.toContain('Confianza');
    });

    it('explica quién descartó una propuesta y por qué, y no repite el texto que está en el editor', () => {
        const rejected = mount(ReplyCard, { props: { reply: replyFixture({ status: 'rejected', rejected_by: { id: 1, name: 'Ana' }, rejection_reason: 'Inventa la fecha.' }) } });
        expect(rejected.get('[data-test="reply-rejected"]').text()).toContain('Descartada por Ana');
        expect(rejected.text()).toContain('Inventa la fecha.');

        const inEditor = mount(ReplyCard, { props: { reply: replyFixture(), bodyInEditor: true } });
        expect(inEditor.find('[data-test="reply-body"]').exists()).toBe(false);
    });

    it('describe en la auditoría si se aprobó con cambios y a quién se asignó', () => {
        const entry = { id: 1, actor: null, subject: null, changes: null, ip: null, user_agent: null, request_id: null, created_at: '' };
        expect(describeMetadata({ ...entry, action: 'email.reply_approved', metadata: { edited: true } })).toEqual(['Con cambios en el texto']);
        expect(describeMetadata({ ...entry, action: 'email.assigned', metadata: { to_user: 'Bea' } })).toEqual(['Ahora lo tiene Bea']);
    });
});
