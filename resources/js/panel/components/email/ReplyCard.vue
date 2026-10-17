<script setup lang="ts">
import { computed } from 'vue';
import { NAlert, NCard, NCollapse, NCollapseItem, NDescriptions, NDescriptionsItem, NEmpty, NTag, NText } from 'naive-ui';
import type { EmailReply } from '@/api/types';
import { formatDateTime, formatPercent } from '@/utils/format';
import { sourceTypeLabels } from '@/utils/labels';

const props = defineProps<{
    reply: EmailReply | null;
    /** El texto se está revisando en el editor (ReviewPanel): no se repite aquí. */
    bodyInEditor?: boolean;
}>();

const title = computed(() => (props.reply?.author ? `Respuesta de ${props.reply.author.name}` : 'Respuesta propuesta por la IA'));

const statusLabels: Record<EmailReply['status'], string> = {
    draft: 'Borrador',
    approved: 'Aprobada',
    sending: 'Enviándose',
    rejected: 'Descartada',
    sent: 'Enviada',
    failed: 'No enviada',
};

const statusTypes: Record<EmailReply['status'], 'default' | 'info' | 'success' | 'warning' | 'error'> = {
    draft: 'info',
    approved: 'success',
    sending: 'info',
    rejected: 'default',
    sent: 'success',
    failed: 'error',
};

// "La IA lo pide: ... Confianza baja (...). ..." → un motivo por línea.
const reasons = computed(() =>
    (props.reply?.needs_human_reason ?? '')
        .split(/(?<=\.)\s+(?=[A-ZÁÉÍÓÚ])/u)
        .map((reason) => reason.trim())
        .filter(Boolean),
);
</script>

<template>
    <NCard :title="title" size="small">
        <template v-if="reply" #header-extra>
            <NTag :type="statusTypes[reply.status]" size="small">{{ statusLabels[reply.status] }}</NTag>
        </template>

        <NEmpty v-if="!reply" description="La IA no ha preparado respuesta para este email." />

        <template v-else>
            <NAlert v-if="reply.needs_human" type="warning" title="Necesita revisión humana" class="reply__alert" data-test="reply-reasons">
                <ul class="reply__list">
                    <li v-for="reason in reasons" :key="reason">{{ reason }}</li>
                </ul>
            </NAlert>
            <NAlert v-if="reply.send_error" type="error" title="No se pudo enviar" class="reply__alert">{{ reply.send_error }}</NAlert>
            <NAlert v-if="reply.status === 'rejected'" type="default" :show-icon="false" class="reply__alert" data-test="reply-rejected">
                Descartada{{ reply.rejected_by ? ` por ${reply.rejected_by.name}` : '' }}{{ reply.rejected_at ? ` el ${formatDateTime(reply.rejected_at)}` : '' }}{{ reply.rejection_reason ? `: ${reply.rejection_reason}` : '.' }}
            </NAlert>

            <NText v-if="bodyInEditor" depth="3" tag="p" class="reply__in-editor">El texto propuesto está en el editor de la revisión.</NText>
            <pre v-else class="reply__body" data-test="reply-body">{{ reply.body }}</pre>

            <NCollapse v-if="reply.original_body" class="reply__original" data-test="reply-original">
                <NCollapseItem :title="`Corregida por ${reply.edited_by?.name ?? 'una persona'} antes de enviarla: ver el texto original de la IA`" name="original">
                    <pre class="reply__body reply__body--original">{{ reply.original_body }}</pre>
                </NCollapseItem>
            </NCollapse>

            <NDescriptions :column="1" label-placement="left" size="small" class="reply__meta">
                <NDescriptionsItem v-if="!reply.author" label="Confianza">{{ formatPercent(reply.confidence) }}</NDescriptionsItem>
                <NDescriptionsItem v-if="reply.sources.length" label="Fuentes">
                    <div v-for="source in reply.sources" :key="source.type + source.reference">
                        <NTag size="tiny" :bordered="false">{{ sourceTypeLabels[source.type] ?? source.type }}</NTag>
                        {{ source.reference }}
                    </div>
                </NDescriptionsItem>
                <NDescriptionsItem v-if="reply.pending_actions.length" label="Pendiente para una persona">
                    <ul class="reply__list">
                        <li v-for="action in reply.pending_actions" :key="action">{{ action }}</li>
                    </ul>
                </NDescriptionsItem>
                <NDescriptionsItem v-if="reply.internal_note" label="Nota interna">
                    <NText depth="2">{{ reply.internal_note }}</NText>
                </NDescriptionsItem>
                <NDescriptionsItem v-if="reply.sent_at" label="Enviada">
                    {{ formatDateTime(reply.sent_at) }} a {{ reply.sent_to }}
                    {{ reply.sent_automatically ? '(automáticamente)' : reply.author ? '' : reply.approved_by ? `(aprobada por ${reply.approved_by.name})` : '' }}
                </NDescriptionsItem>
            </NDescriptions>
        </template>
    </NCard>
</template>

<style scoped>
.reply__alert {
    margin-bottom: 12px;
}

.reply__list {
    margin: 0;
    padding-left: 18px;
}

.reply__in-editor {
    margin: 0 0 12px;
}

.reply__original {
    margin-bottom: 12px;
}

.reply__body--original {
    border-left-color: #c2c7cc;
}

.reply__body {
    margin: 0 0 12px;
    padding: 12px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-family: inherit;
    font-size: 14px;
    background: #f7f9fc;
    border-left: 3px solid #2080f0;
    border-radius: 4px;
}
</style>
