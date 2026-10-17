<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    NAlert,
    NButton,
    NCard,
    NCollapse,
    NCollapseItem,
    NDescriptions,
    NDescriptionsItem,
    NEllipsis,
    NGrid,
    NGridItem,
    NIcon,
    NPageHeader,
    NPopconfirm,
    NResult,
    NSkeleton,
    NSpace,
    NTag,
    NText,
    NTimeline,
    NTimelineItem,
    useMessage,
} from 'naive-ui';
import { ArrowBackOutline } from '@vicons/ionicons5';
import { ApiError } from '@/api/http';
import { deleteEmail, getEmail } from '@/api/emails';
import { useAuthStore } from '@/stores/auth';
import type { EmailDetail } from '@/api/types';
import AgentTrace from '@/components/email/AgentTrace.vue';
import EmailBody from '@/components/email/EmailBody.vue';
import ReplyCard from '@/components/email/ReplyCard.vue';
import ReviewPanel from '@/components/email/ReviewPanel.vue';
import { formatAddress, formatDateTime, formatDuration, formatPercent } from '@/utils/format';
import { intentLabels, sentimentLabels, statusLabels, statusTagTypes, urgencyLabels } from '@/utils/labels';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const message = useMessage();
const deleting = ref(false);

const email = ref<EmailDetail | null>(null);
const error = ref<ApiError | null>(null);
const loading = ref(true);

async function load(id: number): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        email.value = await getEmail(id);
        document.title = `${email.value.subject || '(sin asunto)'} · AI Mail`;
    } catch (e) {
        error.value = e instanceof ApiError ? e : new ApiError(0, 'No se pudo cargar el email.');
    } finally {
        loading.value = false;
    }
}

watch(() => Number(route.params.id), load, { immediate: true });

async function remove(): Promise<void> {
    deleting.value = true;

    try {
        await deleteEmail(email.value!.id);
        message.success('Email borrado.');
        await router.push({ name: 'emails' });
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo borrar el email.');
    } finally {
        deleting.value = false;
    }
}

// La respuesta más reciente (el agente puede haberse ejecutado varias veces, o una persona escribió la suya).
const reply = computed(() => email.value?.replies[0] ?? null);
const olderReplies = computed(() => email.value?.replies.slice(1) ?? []);

// Revisión humana: el email espera a una persona y el usuario puede decidir.
const reviewing = computed(() => !!email.value?.awaiting_person && auth.can('emails.review'));

function onReviewed(updated: EmailDetail): void {
    email.value = updated;
}
const addresses = (list: { email: string | null; name: string | null }[]) => list.map(formatAddress).join(', ');
</script>

<template>
    <NSkeleton v-if="loading && !email" text :repeat="8" />

    <NResult v-else-if="error" :status="error.status === 404 ? '404' : 'error'" :title="error.status === 404 ? 'Email no encontrado' : 'Error'" :description="error.message">
        <template #footer>
            <RouterLink :to="{ name: 'emails' }"><NButton>Volver a los emails</NButton></RouterLink>
        </template>
    </NResult>

    <div v-else-if="email" class="email">
        <NPageHeader class="email__header">
            <template #back>
                <RouterLink :to="{ name: 'emails' }" class="email__back" aria-label="Volver a los emails">
                    <NIcon size="20"><ArrowBackOutline /></NIcon>
                </RouterLink>
            </template>
            <template #title>{{ email.subject || '(sin asunto)' }}</template>
            <template #extra>
                <NSpace size="small">
                    <NTag v-if="email.intent" :bordered="false">{{ intentLabels[email.intent] }} · {{ formatPercent(email.intent_confidence) }}</NTag>
                    <NTag :type="statusTagTypes[email.status]" data-test="email-status">{{ statusLabels[email.status] }}</NTag>
                    <NPopconfirm v-if="auth.can('emails.delete')" positive-text="Borrar" negative-text="Cancelar" @positive-click="remove">
                        <template #trigger>
                            <NButton size="small" type="error" secondary :loading="deleting" data-test="email-delete">Borrar</NButton>
                        </template>
                        Se borrará el email con sus adjuntos, la respuesta propuesta y su historial. No se puede deshacer.
                    </NPopconfirm>
                </NSpace>
            </template>
            <NDescriptions :column="2" label-placement="left" size="small">
                <NDescriptionsItem label="De">{{ formatAddress(email.from) }}</NDescriptionsItem>
                <NDescriptionsItem label="Recibido">{{ formatDateTime(email.received_at) }}</NDescriptionsItem>
                <NDescriptionsItem label="Para">{{ addresses(email.to) || '—' }}</NDescriptionsItem>
                <NDescriptionsItem label="Buzón">{{ email.mailbox?.name ?? '—' }}</NDescriptionsItem>
                <NDescriptionsItem v-if="email.cc.length" label="Cc">{{ addresses(email.cc) }}</NDescriptionsItem>
                <NDescriptionsItem v-if="email.reply_to.length" label="Responder a">{{ addresses(email.reply_to) }}</NDescriptionsItem>
            </NDescriptions>
        </NPageHeader>

        <NAlert v-if="email.injection_suspected" type="error" title="Posible intento de manipular a la IA" class="email__alert">
            El email parece contener instrucciones dirigidas a la IA. No se ha ejecutado el agente: revísalo con cuidado.
        </NAlert>
        <NAlert v-if="email.automated_reason" type="info" title="Correo automático" class="email__alert">
            {{ email.automated_reason }} La plataforma no responde a estos correos.
        </NAlert>

        <NGrid :cols="24" :x-gap="16" :y-gap="16" item-responsive responsive="screen">
            <NGridItem span="24 l:14">
                <NSpace vertical :size="16">
                    <NCard title="Mensaje" size="small">
                        <EmailBody :email="email" />
                    </NCard>
                    <ReplyCard :reply="reply" :body-in-editor="reviewing && reply?.status === 'draft'" />
                    <ReviewPanel v-if="reviewing" :email="email" @updated="onReviewed" />
                    <NCollapse v-if="olderReplies.length" data-test="older-replies">
                        <NCollapseItem :title="`Respuestas anteriores (${olderReplies.length})`" name="older">
                            <NSpace vertical :size="12">
                                <ReplyCard v-for="older in olderReplies" :key="older.id" :reply="older" />
                            </NSpace>
                        </NCollapseItem>
                    </NCollapse>
                </NSpace>
            </NGridItem>

            <NGridItem span="24 l:10">
                <NSpace vertical :size="16">
                    <NCard title="Clasificación de la IA" size="small">
                        <NText v-if="!email.classification" depth="3">Sin clasificar.</NText>
                        <template v-else>
                            <p v-if="email.summary" class="email__summary">{{ email.summary }}</p>
                            <NDescriptions :column="2" label-placement="top" size="small">
                                <NDescriptionsItem label="Intención">{{ email.intent ? intentLabels[email.intent] : '—' }}</NDescriptionsItem>
                                <NDescriptionsItem label="Confianza">{{ formatPercent(email.intent_confidence) }}</NDescriptionsItem>
                                <NDescriptionsItem label="Urgencia">{{ urgencyLabels[email.urgency ?? ''] ?? '—' }}</NDescriptionsItem>
                                <NDescriptionsItem label="Tono">{{ sentimentLabels[email.sentiment ?? ''] ?? '—' }}</NDescriptionsItem>
                                <NDescriptionsItem label="Idioma">{{ email.language ?? '—' }}</NDescriptionsItem>
                                <NDescriptionsItem label="Pedidos">{{ email.classification.entities.order_numbers?.join(', ') || '—' }}</NDescriptionsItem>
                            </NDescriptions>
                            <NAlert v-if="email.classification.needs_human" type="warning" :show-icon="false" class="email__alert">
                                {{ email.classification.needs_human_reason }}
                            </NAlert>
                        </template>
                    </NCard>

                    <NCard title="Historial" size="small">
                        <NText v-if="!email.status_history.length" depth="3">Todavía no se ha procesado.</NText>
                        <NTimeline v-else>
                            <NTimelineItem
                                v-for="(change, index) in email.status_history"
                                :key="index"
                                :type="statusTagTypes[change.to] === 'default' ? 'default' : statusTagTypes[change.to]"
                                :title="change.to_label"
                                :time="`${formatDateTime(change.at)} · ${change.user?.name ?? 'Plataforma'}`"
                            >
                                <!-- Los motivos pueden ser largos: 3 líneas y clic para verlos enteros. -->
                                <NEllipsis v-if="change.reason" :line-clamp="3" expand-trigger="click" :tooltip="false">
                                    {{ change.reason }}
                                </NEllipsis>
                            </NTimelineItem>
                        </NTimeline>

                        <template v-if="email.reviews.length">
                            <NText strong tag="div" class="email__reviews-title">Decisiones de las personas</NText>
                            <ul class="email__reviews" data-test="reviews">
                                <li v-for="review in email.reviews" :key="review.id">
                                    <NText>{{ review.decision_label }}{{ review.edited ? ' (con cambios)' : '' }}</NText>
                                    <NText depth="3"> · {{ review.user?.name ?? 'Persona eliminada' }} · {{ formatDateTime(review.at) }}</NText>
                                    <NText v-if="review.waited_seconds !== null" depth="3"> · esperó {{ formatDuration(review.waited_seconds) }}</NText>
                                    <div v-if="review.reason" class="email__review-reason">{{ review.reason }}</div>
                                </li>
                            </ul>
                        </template>
                    </NCard>

                    <NCard title="Lo que hizo la IA" size="small">
                        <AgentTrace :runs="email.agent_runs" />
                    </NCard>
                </NSpace>
            </NGridItem>
        </NGrid>
    </div>
</template>

<style scoped>
.email__header {
    margin-bottom: 16px;
    padding: 16px;
    background: #fff;
    border-radius: 4px;
}

.email__back {
    display: flex;
    color: inherit;
}

.email__alert {
    margin-bottom: 16px;
}

.email__reviews-title {
    margin-top: 12px;
}

.email__reviews {
    margin: 4px 0 0;
    padding-left: 18px;
}

.email__review-reason {
    color: #767c82;
    font-size: 13px;
}

.email__summary {
    margin: 0 0 12px;
}
</style>
