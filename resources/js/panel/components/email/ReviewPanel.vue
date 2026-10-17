<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    NAlert,
    NButton,
    NCard,
    NInput,
    NModal,
    NPopconfirm,
    NSpace,
    NText,
    useMessage,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import { approveReply, assignEmail, checkReply, closeEmail, decideAction, rejectReply, reprocessEmail, writeReply } from '@/api/reviews';
import type { EmailDetail } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useReviewQueueStore } from '@/stores/reviewQueue';
import { formatAddress } from '@/utils/format';
import { toolLabel } from '@/utils/labels';

/**
 * Lo que puede hacer una persona con un email que espera revisión humana:
 * aprobar la respuesta de la IA (tal cual o editada), descartarla, escribir
 * una propia, cerrar el caso sin responder, reprocesarlo con la IA (con una
 * indicación, si se usa ai-service), aprobar o rechazar la acción que
 * propuso ai-service y asignárselo. La API vuelve a comprobarlo todo; cada acción devuelve el
 * email actualizado.
 */
const props = defineProps<{ email: EmailDetail }>();
const emit = defineEmits<{ updated: [email: EmailDetail] }>();

const auth = useAuthStore();
const queue = useReviewQueueStore();
const message = useMessage();

const MAX_CHARS = 20000;

/** La propuesta de la IA pendiente (la más reciente en borrador). */
const draft = computed(() => props.email.replies.find((reply) => reply.status === 'draft') ?? null);
const text = ref('');
const busy = ref<string | null>(null);
const warnings = ref<string[]>([]);
const checking = ref(false);

// Al cambiar de email o de propuesta, el editor parte del texto de la IA (o vacío).
watch(draft, (reply) => {
    text.value = reply?.body ?? '';
    warnings.value = reply?.checks ?? [];
}, { immediate: true });

const normalize = (value: string) => value.replace(/\r\n/g, '\n').trimEnd();
const edited = computed(() => draft.value !== null && normalize(text.value) !== normalize(draft.value.body));
const empty = computed(() => text.value.trim() === '');
const recipient = computed(() => formatAddress(props.email.reply_to[0] ?? props.email.from));
const assignedToMe = computed(() => props.email.assignee?.id === auth.user?.id);

// Comprobaciones automáticas del texto (enlaces, pedidos o importes que no salen de ningún dato...).
let checkTimer: ReturnType<typeof setTimeout> | undefined;
watch(text, (value) => {
    clearTimeout(checkTimer);

    if (value.trim() === '' || (draft.value && !edited.value)) {
        warnings.value = draft.value && !edited.value ? draft.value.checks : [];

        return;
    }

    checkTimer = setTimeout(async () => {
        checking.value = true;

        try {
            warnings.value = await checkReply(props.email.id, value, draft.value?.id ?? null);
        } catch {
            // Son avisos: si la comprobación falla, se puede enviar igual.
        } finally {
            checking.value = false;
        }
    }, 600);
});

async function run(action: string, call: () => Promise<EmailDetail>, done: string): Promise<boolean> {
    busy.value = action;

    try {
        emit('updated', await call());
        message.success(done);
        void queue.refresh();

        return true;
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo completar la acción.');

        return false;
    } finally {
        busy.value = null;
    }
}

function send(): void {
    if (draft.value) {
        void run('send', () => approveReply(props.email.id, draft.value!.id, edited.value ? text.value : undefined), edited.value ? 'Respuesta corregida y enviada.' : 'Respuesta aprobada y enviada.');
    } else {
        void run('send', () => writeReply(props.email.id, text.value), 'Respuesta enviada.');
    }
}

// Descartar la propuesta y cerrar sin responder piden un motivo opcional;
// reprocesar, una indicación para la IA (solo con ai-service).
type DialogKind = 'reject' | 'close' | 'reprocess';
const dialog = ref<DialogKind | null>(null);
const reason = ref('');
const MAX_INSTRUCTION_CHARS = 2000;

const dialogTexts: Record<DialogKind, { title: string; confirm: string }> = {
    reject: { title: 'Descartar la propuesta de la IA', confirm: 'Descartar' },
    close: { title: 'Cerrar sin responder', confirm: 'Cerrar el caso' },
    reprocess: { title: 'Reprocesar con la IA', confirm: 'Reprocesar' },
};

function openDialog(kind: DialogKind): void {
    reason.value = '';
    dialog.value = kind;
}

async function confirmDialog(): Promise<void> {
    const why = reason.value.trim() || null;
    const actions: Record<DialogKind, () => Promise<boolean>> = {
        reject: () => run('reject', () => rejectReply(props.email.id, draft.value!.id, why), 'Propuesta descartada. Puedes escribir tu respuesta.'),
        close: () => run('close', () => closeEmail(props.email.id, why), 'Caso cerrado sin responder.'),
        reprocess: () => run('reprocess', () => reprocessEmail(props.email.id, props.email.can_instruct ? why : null), 'Enviado a la IA otra vez: en unos segundos tendrás una propuesta nueva.'),
    };

    if (dialog.value && await actions[dialog.value]()) {
        dialog.value = null;
    }
}

// Acción propuesta por ai-service (p. ej. abrir un ticket): la IA espera la decisión.
const action = computed(() => props.email.pending_action);
const actionArguments = computed(() => Object.entries(action.value?.arguments ?? {})
    .map(([key, value]) => ({ key, label: argumentLabels[key] ?? key, value: typeof value === 'string' ? value : JSON.stringify(value) })));
const argumentLabels: Record<string, string> = { subject: 'Asunto', description: 'Descripción', priority: 'Prioridad' };
const decide = (approved: boolean) => run(
    approved ? 'action-approve' : 'action-reject',
    () => decideAction(props.email.id, approved),
    approved ? 'Acción aprobada: la IA la hará y preparará la respuesta.' : 'Acción rechazada: la IA preparará la respuesta sin hacerla.',
);
const assign = (userId: number | null) => run('assign', () => assignEmail(props.email.id, userId), userId ? 'Caso asignado a ti.' : 'Caso liberado.');
</script>

<template>
    <NCard title="Revisión" size="small" data-test="review-panel">
        <template #header-extra>
            <NSpace align="center" :size="8">
                <NText v-if="email.assignee" depth="3" data-test="assignee">
                    {{ assignedToMe ? 'Asignado a ti' : `Asignado a ${email.assignee.name}` }}
                </NText>
                <NButton v-if="!assignedToMe" size="tiny" secondary :loading="busy === 'assign'" data-test="assign-me" @click="assign(auth.user!.id)">
                    {{ email.assignee ? 'Quedármelo' : 'Asignarme' }}
                </NButton>
                <NButton v-else size="tiny" quaternary :loading="busy === 'assign'" data-test="unassign" @click="assign(null)">Liberar</NButton>
            </NSpace>
        </template>

        <NAlert v-if="email.send_attempted" type="warning" title="Ya se intentó enviar una respuesta" class="review__alert" data-test="send-attempted">
            Puede haber llegado al cliente: compruébalo antes de enviar otra. Por eso no se puede reprocesar con la IA.
        </NAlert>

        <NAlert v-if="action" type="info" :title="`La IA propone: ${toolLabel(action.tool)}`" class="review__alert" data-test="pending-action">
            <NText tag="p" class="review__action-summary">{{ action.summary }}</NText>
            <dl class="review__arguments">
                <template v-for="arg in actionArguments" :key="arg.key">
                    <dt>{{ arg.label }}</dt>
                    <dd>{{ arg.value }}</dd>
                </template>
            </dl>
            <NText depth="3" tag="p" class="review__hint">No se hace nada hasta que lo decidas. Después, la IA preparará la respuesta al cliente.</NText>
            <NSpace :size="8">
                <NPopconfirm positive-text="Aprobar" negative-text="Cancelar" @positive-click="decide(true)">
                    <template #trigger>
                        <NButton type="primary" size="small" :loading="busy === 'action-approve'" data-test="action-approve">Aprobar acción</NButton>
                    </template>
                    La IA hará la acción ahora. No se puede deshacer desde aquí.
                </NPopconfirm>
                <NButton secondary size="small" :loading="busy === 'action-reject'" data-test="action-reject" @click="decide(false)">Rechazar acción</NButton>
            </NSpace>
        </NAlert>

        <NText depth="3" tag="p" class="review__hint">
            <template v-if="draft">Revisa la propuesta de la IA: puedes enviarla tal cual, corregirla antes de enviarla o descartarla.</template>
            <template v-else>No hay propuesta de la IA: escribe tú la respuesta.</template>
            Se enviará a <strong>{{ recipient }}</strong> desde {{ email.mailbox?.name ?? 'el buzón en el que llegó' }}, en el mismo hilo.
        </NText>

        <NInput
            v-model:value="text"
            type="textarea"
            :autosize="{ minRows: 8, maxRows: 24 }"
            :maxlength="MAX_CHARS"
            show-count
            placeholder="Escribe la respuesta al cliente…"
            data-test="review-text"
        />

        <NAlert v-if="warnings.length" type="warning" :title="checking ? 'Comprobando…' : 'Revisa antes de enviar'" class="review__alert review__warnings" data-test="review-warnings">
            <ul class="review__list">
                <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
            </ul>
        </NAlert>

        <NSpace justify="space-between" class="review__actions" wrap>
            <NSpace :size="8" wrap>
                <NPopconfirm positive-text="Enviar" negative-text="Cancelar" @positive-click="send">
                    <template #trigger>
                        <NButton type="primary" :disabled="empty" :loading="busy === 'send'" data-test="review-send">
                            {{ draft ? (edited ? 'Enviar con mis cambios' : 'Aprobar y enviar') : 'Enviar respuesta' }}
                        </NButton>
                    </template>
                    Se enviará ahora a {{ recipient }}. No se puede deshacer.
                </NPopconfirm>
                <NButton v-if="draft && edited" quaternary data-test="review-restore" @click="text = draft.body">Volver al texto de la IA</NButton>
                <NButton v-if="draft" secondary :loading="busy === 'reject'" data-test="review-reject" @click="openDialog('reject')">Descartar propuesta</NButton>
            </NSpace>
            <NSpace :size="8" wrap>
                <NButton v-if="!email.send_attempted" secondary :loading="busy === 'reprocess'" data-test="review-reprocess" @click="openDialog('reprocess')">Reprocesar con la IA</NButton>
                <NButton secondary :loading="busy === 'close'" data-test="review-close" @click="openDialog('close')">Cerrar sin responder</NButton>
            </NSpace>
        </NSpace>

        <NModal
            :show="dialog !== null"
            preset="dialog"
            :title="dialog ? dialogTexts[dialog].title : ''"
            :positive-text="dialog ? dialogTexts[dialog].confirm : ''"
            negative-text="Cancelar"
            :loading="busy === 'reject' || busy === 'close' || busy === 'reprocess'"
            @positive-click="() => { void confirmDialog(); return false; }"
            @negative-click="dialog = null"
            @close="dialog = null"
        >
            <template v-if="dialog === 'reprocess'">
                <NText tag="p" depth="3">
                    La IA preparará una propuesta nueva con los datos de ahora; la actual{{ action ? ' y la acción pendiente' : '' }} se descartará.
                </NText>
                <template v-if="email.can_instruct">
                    <NInput
                        v-model:value="reason"
                        type="textarea"
                        :maxlength="MAX_INSTRUCTION_CHARS"
                        show-count
                        placeholder="Indicación para la IA (opcional): p. ej. «Ofrece cambiar la lámpara por otra nueva»"
                        data-test="review-instruction"
                    />
                    <NText tag="p" depth="3" class="review__note">Con una indicación, la respuesta siempre vuelve a revisión: no se envía sola.</NText>
                </template>
            </template>
            <template v-else>
                <NText tag="p" depth="3">
                    {{ dialog === 'reject'
                        ? 'El email seguirá esperando a una persona: después podrás escribir tu respuesta, reprocesarlo o cerrarlo.'
                        : 'No se enviará nada al cliente. Úsalo si no necesita respuesta o ya se atendió por otra vía.' }}
                </NText>
                <NInput v-model:value="reason" type="textarea" :maxlength="1000" placeholder="Motivo (opcional): queda en el historial" data-test="review-reason" />
            </template>
        </NModal>
    </NCard>
</template>

<style scoped>
.review__hint {
    margin: 0 0 12px;
}

.review__alert {
    margin-bottom: 12px;
}

.review__warnings {
    margin-top: 12px;
}

.review__list {
    margin: 0;
    padding-left: 18px;
}

.review__actions {
    margin-top: 12px;
}

.review__action-summary {
    margin: 0 0 8px;
}

.review__arguments {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 12px;
    margin: 0 0 8px;
}

.review__arguments dt {
    font-weight: 500;
}

.review__arguments dd {
    margin: 0;
    white-space: pre-wrap;
}

.review__note {
    margin: 8px 0 0;
}
</style>
