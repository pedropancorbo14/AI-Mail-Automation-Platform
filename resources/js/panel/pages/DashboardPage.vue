<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { NAlert, NCard, NEmpty, NGrid, NGridItem, NRadioButton, NRadioGroup, NSkeleton, NSpace, NStatistic, NTag, NText } from 'naive-ui';
import { ApiError } from '@/api/http';
import { getDashboard, intentBars, type Dashboard, type DashboardPeriod } from '@/api/dashboard';
import type { EmailStatus } from '@/api/types';
import { formatDateTime, formatPercent, formatUsd } from '@/utils/format';
import { intentLabels, statusLabels, statusTagTypes } from '@/utils/labels';

const days = ref<DashboardPeriod>(30);
const data = ref<Dashboard | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

watch(days, async (value) => {
    loading.value = true;
    error.value = null;

    try {
        data.value = await getDashboard(value);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar el resumen.';
    } finally {
        loading.value = false;
    }
}, { immediate: true });

const bars = computed(() => intentBars(data.value?.intents ?? []));

// Estados en el orden del recorrido de un email; sin los que están a 0.
const statusOrder: EmailStatus[] = ['received', 'processing', 'ai_review', 'human_review', 'approved', 'sent', 'ignored', 'failed'];
const statuses = computed(() => statusOrder.filter((status) => (data.value?.emails.by_status[status] ?? 0) > 0));
</script>

<template>
    <NSpace vertical :size="16">
        <NSpace justify="space-between" align="center">
            <h2 class="dashboard__title">Resumen</h2>
            <NRadioGroup v-model:value="days" size="small">
                <NRadioButton :value="7">7 días</NRadioButton>
                <NRadioButton :value="30">30 días</NRadioButton>
                <NRadioButton :value="90">90 días</NRadioButton>
            </NRadioGroup>
        </NSpace>

        <NAlert v-if="error" type="error">{{ error }}</NAlert>
        <NSkeleton v-if="loading && !data" text :repeat="6" />

        <template v-if="data">
            <NGrid :cols="24" :x-gap="16" :y-gap="16" item-responsive responsive="screen">
                <NGridItem span="12 m:8 l:4">
                    <NCard size="small" class="tile"><NStatistic label="Emails recibidos" :value="data.emails.received" /></NCard>
                </NGridItem>
                <NGridItem span="12 m:8 l:4">
                    <NCard size="small" class="tile">
                        <NStatistic label="Respondidos solos" :value="data.emails.sent_automatically" />
                        <NText depth="3" class="tile__note">{{ formatPercent(data.emails.automation_rate) }} de los que necesitaban respuesta</NText>
                    </NCard>
                </NGridItem>
                <NGridItem span="12 m:8 l:4">
                    <RouterLink :to="{ name: 'emails', query: { status: 'human_review' } }" class="tile__link">
                        <NCard size="small" class="tile" hoverable>
                            <NStatistic label="Esperan a una persona" :value="data.emails.awaiting_human" />
                            <NText depth="3" class="tile__note">Ahora mismo, de cualquier fecha</NText>
                        </NCard>
                    </RouterLink>
                </NGridItem>
                <NGridItem span="12 m:8 l:4">
                    <RouterLink :to="{ name: 'emails', query: { status: 'failed' } }" class="tile__link">
                        <NCard size="small" class="tile" hoverable>
                            <NStatistic label="Con error" :value="data.emails.failed" />
                            <NText depth="3" class="tile__note">Ahora mismo</NText>
                        </NCard>
                    </RouterLink>
                </NGridItem>
                <NGridItem span="12 m:8 l:4">
                    <NCard size="small" class="tile">
                        <NStatistic label="Coste de IA" :value="formatUsd(data.ai.cost_usd)" />
                        <NText depth="3" class="tile__note">Hoy {{ formatUsd(data.ai.cost_today_usd) }} · mes {{ formatUsd(data.ai.cost_month_usd) }}</NText>
                    </NCard>
                </NGridItem>
                <NGridItem span="12 m:8 l:4">
                    <NCard size="small" class="tile">
                        <NStatistic label="Coste medio por email" :value="formatUsd(data.ai.avg_cost_per_email_usd)" />
                        <NText depth="3" class="tile__note">
                            {{ data.ai.requests }} llamadas{{ data.ai.failed_requests ? ` · ${data.ai.failed_requests} fallidas` : '' }}
                        </NText>
                    </NCard>
                </NGridItem>
            </NGrid>

            <NGrid :cols="24" :x-gap="16" :y-gap="16" item-responsive responsive="screen">
                <NGridItem span="24 l:12">
                    <NCard title="Intenciones más frecuentes" size="small">
                        <NEmpty v-if="!bars.length" description="Sin emails clasificados en el periodo." />
                        <ul v-else class="bars" data-test="intent-bars">
                            <li v-for="bar in bars" :key="bar.intent">
                                <RouterLink :to="{ name: 'emails', query: { intent: bar.intent } }" class="bars__row" :title="`${intentLabels[bar.intent]}: ${bar.count} emails (${formatPercent(bar.share)})`">
                                    <span class="bars__label">{{ intentLabels[bar.intent] }}</span>
                                    <span class="bars__track"><span class="bars__fill" :style="{ width: `${bar.width}%` }" /></span>
                                    <span class="bars__value">{{ bar.count }}</span>
                                </RouterLink>
                            </li>
                        </ul>
                    </NCard>
                </NGridItem>

                <NGridItem span="24 l:12">
                    <NCard title="Emails del periodo por estado" size="small">
                        <NEmpty v-if="!statuses.length" description="Sin emails en el periodo." />
                        <ul v-else class="statuses">
                            <li v-for="status in statuses" :key="status">
                                <RouterLink :to="{ name: 'emails', query: { status } }" class="statuses__row">
                                    <NTag :type="statusTagTypes[status]" size="small">{{ statusLabels[status] }}</NTag>
                                    <span class="statuses__value">{{ data.emails.by_status[status] }}</span>
                                </RouterLink>
                            </li>
                        </ul>
                    </NCard>
                </NGridItem>
            </NGrid>

            <NCard title="Necesitan atención" size="small">
                <NEmpty v-if="!data.needs_attention.length" description="Nada pendiente: todo está respondido o descartado." />
                <ul v-else class="attention">
                    <li v-for="email in data.needs_attention" :key="email.id">
                        <RouterLink :to="{ name: 'email', params: { id: email.id } }" class="attention__row">
                            <NTag :type="statusTagTypes[email.status]" size="small">{{ statusLabels[email.status] }}</NTag>
                            <span class="attention__subject">{{ email.subject || '(sin asunto)' }}</span>
                            <NText depth="3">{{ email.from.name ?? email.from.email }} · {{ formatDateTime(email.received_at) }}</NText>
                        </RouterLink>
                    </li>
                </ul>
            </NCard>
        </template>
    </NSpace>
</template>

<style scoped>
.dashboard__title {
    margin: 0;
    font-size: 20px;
    font-weight: 500;
}

.tile {
    height: 100%;
}

.tile__link {
    text-decoration: none;
}

.tile__note {
    display: block;
    margin-top: 4px;
    font-size: 12px;
}

.bars,
.statuses,
.attention {
    margin: 0;
    padding: 0;
    list-style: none;
}

.bars__row {
    display: grid;
    grid-template-columns: 160px 1fr 40px;
    align-items: center;
    gap: 12px;
    padding: 6px 0;
    color: inherit;
    text-decoration: none;
}

.bars__row:hover .bars__fill {
    opacity: 0.8;
}

.bars__track {
    height: 10px;
}

/* Una sola serie: un solo color; el valor va en texto normal, no en el color de la serie. */
.bars__fill {
    display: block;
    height: 100%;
    min-width: 4px;
    background: #18a058;
    border-radius: 0 4px 4px 0;
}

.bars__value {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.statuses__row,
.attention__row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 6px 0;
    color: inherit;
    text-decoration: none;
}

.statuses__value {
    margin-left: auto;
    font-variant-numeric: tabular-nums;
}

.attention__subject {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-weight: 500;
}

.attention__row:hover .attention__subject {
    text-decoration: underline;
}
</style>
