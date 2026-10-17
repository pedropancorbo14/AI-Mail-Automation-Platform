<script setup lang="ts">
import { computed, h, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter, type LocationQuery } from 'vue-router';
import {
    NAlert,
    NButton,
    NCard,
    NDataTable,
    NEllipsis,
    NSelect,
    NSpace,
    NTab,
    NTabs,
    NTag,
    NText,
    useMessage,
    type DataTableColumns,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import { assignEmail, listReviewQueue, type QueueFilters, type QueueScope, type ReviewQueue } from '@/api/reviews';
import type { EmailIntent, ReviewQueueItem } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useReviewQueueStore } from '@/stores/reviewQueue';
import { formatDateTime, formatPercent, formatSince } from '@/utils/format';
import { intentLabels, options, statusLabels, statusTagTypes } from '@/utils/labels';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const queueStore = useReviewQueueStore();
const message = useMessage();

const scopes: { value: QueueScope; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'mine', label: 'Asignados a mí' },
    { value: 'unassigned', label: 'Sin asignar' },
];

/** Filtros desde la URL (?scope=mine&intent=complaint&page=2); lo que no es válido se ignora. */
function filtersFromQuery(query: LocationQuery): QueueFilters {
    const value = (key: string) => (typeof query[key] === 'string' ? (query[key] as string) : '');
    const scope = value('scope');
    const intent = value('intent');
    const page = Number(value('page'));

    return {
        scope: scopes.some((s) => s.value === scope) ? (scope as QueueScope) : 'all',
        intent: intent in intentLabels ? (intent as EmailIntent) : null,
        page: Number.isInteger(page) && page > 1 ? page : 1,
    };
}

const filters = computed(() => filtersFromQuery(route.query));
const result = ref<ReviewQueue | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const assigning = ref<number | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        result.value = await listReviewQueue(filters.value);
        queueStore.counts = result.value.meta.counts;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar la bandeja de revisión.';
    } finally {
        loading.value = false;
    }
}

function update(changes: Partial<QueueFilters>): void {
    const next = { ...filters.value, page: 1, ...changes };

    void router.push({
        query: {
            ...(next.scope !== 'all' ? { scope: next.scope } : {}),
            ...(next.intent ? { intent: next.intent } : {}),
            ...(next.page > 1 ? { page: String(next.page) } : {}),
        },
    });
}

watch(() => route.query, load);
onMounted(load);

async function assignToMe(row: ReviewQueueItem): Promise<void> {
    assigning.value = row.id;

    try {
        await assignEmail(row.id, auth.user!.id);
        message.success('Caso asignado a ti.');
        await load();
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo asignar el caso.');
    } finally {
        assigning.value = null;
    }
}

const counts = computed(() => result.value?.meta.counts ?? queueStore.counts);
const countOf = (scope: QueueScope) => (scope === 'all' ? counts.value?.total : counts.value?.[scope]) ?? 0;

const pagination = computed(() => ({
    page: filters.value.page,
    pageSize: result.value?.meta.per_page ?? 25,
    itemCount: result.value?.meta.total ?? 0,
    prefix: ({ itemCount }: { itemCount: number | undefined }) => `${itemCount ?? 0} casos`,
}));

const columns = computed<DataTableColumns<ReviewQueueItem>>(() => [
    {
        title: 'Esperando',
        key: 'waiting_since',
        width: 150,
        render: (row) =>
            h('div', [
                h('div', { title: formatDateTime(row.waiting_since) }, formatSince(row.waiting_since)),
                row.urgency === 'high' ? h(NTag, { size: 'tiny', type: 'warning', bordered: false }, () => 'Urgente') : null,
            ]),
    },
    {
        title: 'Asunto',
        key: 'subject',
        render: (row) =>
            h('div', [
                h(RouterLink, { to: { name: 'email', params: { id: row.id } }, class: 'queue__subject' }, () => row.subject || '(sin asunto)'),
                h(NText, { depth: 3, class: 'queue__from' }, () => ` · ${row.from.name ?? row.from.email ?? '—'}`),
                row.waiting_reason ? h(NEllipsis, { lineClamp: 2, class: 'queue__reason' }, () => row.waiting_reason) : null,
            ]),
    },
    {
        title: 'Intención',
        key: 'intent',
        width: 190,
        render: (row) =>
            row.intent
                ? h(NSpace, { size: 4, align: 'center', wrap: false }, () => [
                      h(NTag, { size: 'small', bordered: false }, () => intentLabels[row.intent!]),
                      h(NText, { depth: 3, style: 'white-space: nowrap' }, () => formatPercent(row.intent_confidence)),
                  ])
                : h(NText, { depth: 3 }, () => '—'),
    },
    {
        title: 'Propuesta',
        key: 'has_draft',
        width: 120,
        render: (row) =>
            row.status === 'failed'
                ? h(NTag, { type: statusTagTypes.failed, size: 'small' }, () => statusLabels.failed)
                : row.has_draft
                  ? h(NText, null, () => 'De la IA')
                  : h(NText, { depth: 3 }, () => 'Sin propuesta'),
    },
    {
        title: 'Asignado',
        key: 'assignee',
        width: 150,
        render: (row) =>
            row.assignee
                ? h(NText, null, () => (row.assignee!.id === auth.user?.id ? 'Tú' : row.assignee!.name))
                : auth.can('emails.review')
                  ? h(
                        NButton,
                        { size: 'tiny', secondary: true, loading: assigning.value === row.id, 'data-test': `assign-${row.id}`, onClick: () => assignToMe(row) },
                        () => 'Asignarme',
                    )
                  : h(NText, { depth: 3 }, () => 'Nadie'),
    },
]);

function rowProps(row: ReviewQueueItem) {
    return {
        style: 'cursor: pointer',
        onClick: (event: MouseEvent) => {
            if (!(event.target as HTMLElement).closest('a, button')) {
                void router.push({ name: 'email', params: { id: row.id } });
            }
        },
    };
}
</script>

<template>
    <NCard title="Bandeja de revisión">
        <template #header-extra>
            <RouterLink :to="{ name: 'review-history' }"><NButton text type="primary" data-test="history-link">Historial de decisiones</NButton></RouterLink>
        </template>

        <NText depth="3" tag="p" class="queue__intro">
            Emails que la IA no ha podido enviar sola o que fallaron. Los urgentes y los que más llevan esperando, primero.
        </NText>

        <NSpace justify="space-between" align="center" class="queue__filters" wrap>
            <NTabs type="segment" :value="filters.scope" size="small" class="queue__tabs" @update:value="(scope: QueueScope) => update({ scope })">
                <NTab v-for="scope in scopes" :key="scope.value" :name="scope.value">
                    {{ scope.label }} ({{ countOf(scope.value) }})
                </NTab>
            </NTabs>
            <NSelect
                :value="filters.intent"
                :options="options(intentLabels)"
                clearable
                filterable
                placeholder="Intención"
                class="queue__select"
                @update:value="(intent) => update({ intent })"
            />
        </NSpace>

        <NAlert v-if="error" type="error" class="queue__error">{{ error }}</NAlert>

        <NDataTable
            remote
            :columns="columns"
            :data="result?.data ?? []"
            :loading="loading"
            :pagination="pagination"
            :row-key="(row: ReviewQueueItem) => row.id"
            :row-props="rowProps"
            @update:page="(page: number) => update({ page })"
        >
            <template #empty>
                <NText depth="3">No hay nada esperando a una persona.</NText>
            </template>
        </NDataTable>
    </NCard>
</template>

<style scoped>
.queue__intro {
    margin: 0 0 16px;
}

.queue__filters {
    margin-bottom: 16px;
}

.queue__tabs {
    width: 460px;
    max-width: 100%;
}

.queue__select {
    width: 220px;
}

.queue__error {
    margin-bottom: 16px;
}

.queue__subject {
    font-weight: 500;
}

.queue__reason {
    margin-top: 2px;
    font-size: 13px;
    color: #767c82;
}
</style>
