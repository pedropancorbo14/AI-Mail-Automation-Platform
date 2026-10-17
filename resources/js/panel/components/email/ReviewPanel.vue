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
import { approveReply, assignEmail, checkReply, closeEmail, rejectReply, reprocessEmail, writeReply } from '@/api/reviews';
import type { EmailDetail } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useReviewQueueStore } from '@/stores/reviewQueue';
import { formatAddress } from '@/utils/format';

/**
 * Lo que puede hacer una persona con un email que espera revisión humana:
 * aprobar la respuesta de la IA (tal cual o editada), descartarla, escribir
 * una propia, cerrar el caso sin responder, reprocesarlo con la IA y
 * asignárselo. La API vuelve a comprobarlo todo; cada acción devuelve el
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

// Descartar la propuesta y cerrar sin responder piden un motivo opcional.
const dialog = ref<'reject' | 'close' | null>(null);
const reason = ref('');

function openDialog(kind: 'reject' | 'close'): void {
    reason.value = '';
    dialog.value = kind;
}

async function confirmDialog(): Promise<void> {
    const why = reason.value.trim() || null;
    const ok = dialog.value === 'reject'
        ? await run('reject', () => rejectReply(props.email.id, draft.value!.id, why), 'Propuesta descartada. Puedes escribir tu respuesta.')
        : await run('close', () => closeEmail(props.email.id, why), 'Caso cerrado sin responder.');

    if (ok) {
        dialog.value = null;
    }
}

const reprocess = () => run('reprocess', () => reprocessEmail(props.email.id), 'Enviado a la IA otra vez: en unos segundos tendrás una propuesta nueva.');
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
                <NPopconfirm v-if="!email.send_attempted" positive-text="Reprocesar" negative-text="Cancelar" @positive-click="reprocess">
                    <template #trigger>
                        <NButton secondary :loading="busy === 'reprocess'" data-test="review-reprocess">Reprocesar con la IA</NButton>
                    </template>
                    La IA preparará una propuesta nueva; la actual se descartará.
                </NPopconfirm>
                <NButton secondary :loading="busy === 'close'" data-test="review-close" @click="openDialog('close')">Cerrar sin responder</NButton>
            </NSpace>
        </NSpace>

        <NModal
            :show="dialog !== null"
            preset="dialog"
            :title="dialog === 'reject' ? 'Descartar la propuesta de la IA' : 'Cerrar sin responder'"
            :positive-text="dialog === 'reject' ? 'Descartar' : 'Cerrar el caso'"
            negative-text="Cancelar"
            :loading="busy === 'reject' || busy === 'close'"
            @positive-click="() => { void confirmDialog(); return false; }"
            @negative-click="dialog = null"
            @close="dialog = null"
        >
            <NText tag="p" depth="3">
                {{ dialog === 'reject'
                    ? 'El email seguirá esperando a una persona: después podrás escribir tu respuesta, reprocesarlo o cerrarlo.'
                    : 'No se enviará nada al cliente. Úsalo si no necesita respuesta o ya se atendió por otra vía.' }}
            </NText>
            <NInput v-model:value="reason" type="textarea" :maxlength="1000" placeholder="Motivo (opcional): queda en el historial" data-test="review-reason" />
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
</style>
