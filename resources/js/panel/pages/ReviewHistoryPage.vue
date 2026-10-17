<script setup lang="ts">
import { computed, h, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import {
    NAlert,
    NButton,
    NCard,
    NDataTable,
    NGrid,
    NGridItem,
    NRadioButton,
    NRadioGroup,
    NSelect,
    NSkeleton,
    NSpace,
    NStatistic,
    NText,
    type DataTableColumns,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import { getReviewSummary, listReviews, type HistoryFilters } from '@/api/reviews';
import type { Paginated, ReviewDecision, ReviewHistoryEntry, ReviewSummary } from '@/api/types';
import { formatDateTime, formatDuration, formatPercent } from '@/utils/format';
import { options, reviewDecisionLabels } from '@/utils/labels';

/**
 * Historial de la revisión humana: resumen del periodo (qué se hace con las
 * propuestas de la IA, cuánto esperan los casos, quién decide qué) y la
 * lista de todas las decisiones.
 */
const days = ref<7 | 30 | 90>(30);
const summary = ref<ReviewSummary | null>(null);
const summaryError = ref<string | null>(null);

watch(days, async (value) => {
    summaryError.value = null;

    try {
        summary.value = await getReviewSummary(value);
    } catch (e) {
        summaryError.value = e instanceof ApiError ? e.message : 'No se pudo cargar el resumen.';
    }
}, { immediate: true });

const filters = ref<HistoryFilters>({ decision: null, user: null, page: 1 });
const history = ref<Paginated<ReviewHistoryEntry> | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        history.value = await listReviews(filters.value);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar el historial.';
    } finally {
        loading.value = false;
    }
}

watch(filters, load, { deep: true });
onMounted(load);

const proposals = computed(() => summary.value?.ai_proposals);
const peopleOptions = computed(() => (summary.value?.people ?? []).map((p) => ({ label: p.user.name ?? 'Persona eliminada', value: p.user.id })));

const peopleColumns: DataTableColumns<ReviewSummary['people'][number]> = [
    { title: 'Persona', key: 'user', render: (row) => row.user.name ?? 'Persona eliminada' },
    { title: 'Decisiones', key: 'total', width: 110, align: 'right' },
    { title: 'Aprobadas', key: 'approved', width: 110, align: 'right', render: (row) => row.decisions.approved },
    { title: 'Corregidas', key: 'edited', width: 110, align: 'right' },
    { title: 'Respondidas', key: 'replied', width: 120, align: 'right', render: (row) => row.decisions.replied },
    { title: 'Descartadas', key: 'rejected', width: 120, align: 'right', render: (row) => row.decisions.rejected },
    { title: 'Cerradas', key: 'closed', width: 100, align: 'right', render: (row) => row.decisions.closed },
    { title: 'Espera media', key: 'average_wait_seconds', width: 130, align: 'right', render: (row) => formatDuration(row.average_wait_seconds) },
];

const columns: DataTableColumns<ReviewHistoryEntry> = [
    { title: 'Cuándo', key: 'at', width: 130, render: (row) => formatDateTime(row.at) },
    { title: 'Quién', key: 'user', width: 150, render: (row) => row.user?.name ?? 'Persona eliminada' },
    {
        title: 'Decisión',
        key: 'decision',
        width: 230,
        render: (row) => h('span', [row.decision_label, row.edited ? h(NText, { depth: 3 }, () => ' (con cambios)') : null]),
    },
    {
        title: 'Email',
        key: 'email',
        // Las decisiones se borran con su email (retención): siempre hay email.
        render: (row) =>
            h('div', [
                row.email ? h(RouterLink, { to: { name: 'email', params: { id: row.email.id } } }, () => row.email!.subject || '(sin asunto)') : '—',
                row.reason ? h('div', { class: 'history__reason' }, row.reason) : null,
            ]),
    },
    { title: 'Esperó', key: 'waited_seconds', width: 110, render: (row) => formatDuration(row.waited_seconds) },
];

const pagination = computed(() => ({
    page: filters.value.page,
    pageSize: history.value?.meta.per_page ?? 25,
    itemCount: history.value?.meta.total ?? 0,
    prefix: ({ itemCount }: { itemCount: number | undefined }) => `${itemCount ?? 0} decisiones`,
}));
</script>

<template>
    <NSpace vertical :size="16">
        <NSpace justify="space-between" align="center">
            <NSpace align="center" :size="12">
                <h2 class="history__title">Historial de la revisión</h2>
                <RouterLink :to="{ name: 'review' }"><NButton text type="primary">Volver a la bandeja</NButton></RouterLink>
            </NSpace>
            <NRadioGroup v-model:value="days" size="small">
                <NRadioButton :value="7">7 días</NRadioButton>
                <NRadioButton :value="30">30 días</NRadioButton>
                <NRadioButton :value="90">90 días</NRadioButton>
            </NRadioGroup>
        </NSpace>

        <NAlert v-if="summaryError" type="error">{{ summaryError }}</NAlert>
        <NSkeleton v-if="!summary && !summaryError" text :repeat="4" />

        <template v-if="summary">
            <NGrid :cols="24" :x-gap="16" :y-gap="16" item-responsive responsive="screen">
                <NGridItem span="12 l:6">
                    <RouterLink :to="{ name: 'review' }" class="tile__link">
                        <NCard size="small" class="tile" hoverable>
                            <NStatistic label="Esperan a una persona" :value="summary.pending" />
                            <NText depth="3" class="tile__note">Ahora mismo</NText>
                        </NCard>
                    </RouterLink>
                </NGridItem>
                <NGridItem span="12 l:6">
                    <NCard size="small" class="tile">
                        <NStatistic label="Casos resueltos" :value="summary.resolved" />
                        <NText depth="3" class="tile__note">Respondidos, cerrados o reprocesados en el periodo</NText>
                    </NCard>
                </NGridItem>
                <NGridItem span="12 l:6">
                    <NCard size="small" class="tile">
                        <NStatistic label="Espera mediana" :value="formatDuration(summary.wait_seconds.median)" />
                        <NText depth="3" class="tile__note">Media {{ formatDuration(summary.wait_seconds.average) }}, desde que llega a una persona</NText>
                    </NCard>
                </NGridItem>
                <NGridItem span="12 l:6">
                    <NCard size="small" class="tile" data-test="proposals">
                        <NStatistic label="Propuestas de la IA aprobadas sin cambios" :value="formatPercent(proposals?.unchanged_rate)" />
                        <NText depth="3" class="tile__note">
                            De {{ proposals?.reviewed }} revisadas: {{ proposals?.approved_unchanged }} tal cual,
                            {{ proposals?.approved_edited }} corregidas, {{ proposals?.rejected }} descartadas
                        </NText>
                    </NCard>
                </NGridItem>
            </NGrid>

            <NCard title="Por persona" size="small">
                <NDataTable :columns="peopleColumns" :data="summary.people" :row-key="(row: ReviewSummary['people'][number]) => row.user.id" size="small">
                    <template #empty><NText depth="3">Nadie ha revisado emails en este periodo.</NText></template>
                </NDataTable>
            </NCard>
        </template>

        <NCard title="Todas las decisiones" size="small">
            <NSpace :size="12" class="history__filters">
                <NSelect
                    :value="filters.decision"
                    :options="options(reviewDecisionLabels)"
                    clearable
                    placeholder="Decisión"
                    class="history__select"
                    @update:value="(decision: ReviewDecision | null) => (filters = { ...filters, decision, page: 1 })"
                />
                <NSelect
                    :value="filters.user"
                    :options="peopleOptions"
                    clearable
                    placeholder="Persona"
                    class="history__select"
                    @update:value="(user: number | null) => (filters = { ...filters, user, page: 1 })"
                />
            </NSpace>

            <NAlert v-if="error" type="error" class="history__error">{{ error }}</NAlert>

            <NDataTable
                remote
                :columns="columns"
                :data="history?.data ?? []"
                :loading="loading"
                :pagination="pagination"
                :row-key="(row: ReviewHistoryEntry) => row.id"
                @update:page="(page: number) => (filters = { ...filters, page })"
            />
        </NCard>
    </NSpace>
</template>

<style scoped>
.history__title {
    margin: 0;
    font-size: 20px;
    font-weight: 500;
}

.history__filters {
    margin-bottom: 12px;
}

.history__select {
    width: 220px;
}

.history__error {
    margin-bottom: 12px;
}

.history__reason {
    color: #767c82;
    font-size: 13px;
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
</style>
