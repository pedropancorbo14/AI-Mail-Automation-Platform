<script setup lang="ts">
import { computed, h, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import {
    NAlert,
    NCard,
    NDataTable,
    NDatePicker,
    NEllipsis,
    NInput,
    NSelect,
    NSpace,
    NTag,
    NText,
    type DataTableColumns,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import { listEmails, type EmailFilters } from '@/api/emails';
import { listMailConnections, type MailConnectionSummary } from '@/api/mailConnections';
import type { EmailListItem, Paginated } from '@/api/types';
import { formatDateTime, formatPercent } from '@/utils/format';
import { intentLabels, options, statusLabels, statusTagTypes } from '@/utils/labels';
import { filtersFromQuery, queryFromFilters } from '@/utils/query';

const route = useRoute();
const router = useRouter();

// La URL manda: los filtros se pueden compartir y el botón "atrás" funciona.
const filters = computed(() => filtersFromQuery(route.query));
const search = ref(filters.value.q);

const result = ref<Paginated<EmailListItem> | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const mailboxes = ref<MailConnectionSummary[]>([]);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        result.value = await listEmails(filters.value);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudieron cargar los emails.';
    } finally {
        loading.value = false;
    }
}

/** Cambia un filtro y vuelve a la primera página. */
function update(changes: Partial<EmailFilters>): void {
    void router.push({ query: queryFromFilters({ ...filters.value, page: 1, ...changes }) });
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => update({ q: value.trim() }), 350);
});

watch(() => route.query, load);

onMounted(async () => {
    await load();
    mailboxes.value = (await listMailConnections().catch(() => null))?.data ?? [];
});

const dateRange = computed<[number, number] | null>(() =>
    filters.value.from && filters.value.to ? [Date.parse(filters.value.from), Date.parse(filters.value.to)] : null,
);

function onDateRange(value: [number, number] | null): void {
    const day = (ms: number) => new Date(ms - new Date(ms).getTimezoneOffset() * 60_000).toISOString().slice(0, 10);

    update(value ? { from: day(value[0]), to: day(value[1]) } : { from: null, to: null });
}

const pagination = computed(() => ({
    page: filters.value.page,
    pageSize: result.value?.meta.per_page ?? 25,
    itemCount: result.value?.meta.total ?? 0,
    prefix: ({ itemCount }: { itemCount: number | undefined }) => `${itemCount ?? 0} emails`,
}));

const columns: DataTableColumns<EmailListItem> = [
    {
        title: 'Recibido',
        key: 'received_at',
        width: 130,
        render: (row) => formatDateTime(row.received_at),
    },
    {
        title: 'De',
        key: 'from',
        width: 220,
        ellipsis: { tooltip: true },
        render: (row) => row.from.name ?? row.from.email ?? '—',
    },
    {
        title: 'Asunto',
        key: 'subject',
        render: (row) =>
            h('div', [
                h(RouterLink, { to: { name: 'email', params: { id: row.id } }, class: 'emails__subject' }, () => row.subject || '(sin asunto)'),
                row.summary ? h(NEllipsis, { lineClamp: 1, class: 'emails__summary' }, () => row.summary) : null,
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
                      h(NText, { depth: 3 }, () => formatPercent(row.intent_confidence)),
                  ])
                : h(NText, { depth: 3 }, () => (row.automated_reason ? 'Automático' : '—')),
    },
    {
        title: 'Estado',
        key: 'status',
        width: 150,
        render: (row) => h(NTag, { type: statusTagTypes[row.status], size: 'small' }, () => statusLabels[row.status]),
    },
    {
        title: 'Buzón',
        key: 'mailbox',
        width: 140,
        ellipsis: { tooltip: true },
        render: (row) => row.mailbox?.name ?? '—',
    },
];

function rowProps(row: EmailListItem) {
    return {
        style: 'cursor: pointer',
        onClick: (event: MouseEvent) => {
            // El enlace del asunto ya navega (y permite abrir en otra pestaña).
            if (!(event.target as HTMLElement).closest('a')) {
                void router.push({ name: 'email', params: { id: row.id } });
            }
        },
    };
}
</script>

<template>
    <NCard title="Emails">
        <NSpace class="emails__filters" :size="12" wrap>
            <NInput v-model:value="search" clearable placeholder="Buscar asunto o remitente" class="emails__search" />
            <NSelect
                :value="filters.status"
                :options="options(statusLabels)"
                clearable
                placeholder="Estado"
                class="emails__select"
                @update:value="(status) => update({ status })"
            />
            <NSelect
                :value="filters.intent"
                :options="options(intentLabels)"
                clearable
                filterable
                placeholder="Intención"
                class="emails__select"
                @update:value="(intent) => update({ intent })"
            />
            <NSelect
                v-if="mailboxes.length > 1"
                :value="filters.mailbox"
                :options="mailboxes.map((m) => ({ label: m.name, value: m.id }))"
                clearable
                placeholder="Buzón"
                class="emails__select"
                @update:value="(mailbox) => update({ mailbox })"
            />
            <NDatePicker :value="dateRange" type="daterange" clearable :actions="['clear']" @update:value="onDateRange" />
        </NSpace>

        <NAlert v-if="error" type="error" class="emails__error">{{ error }}</NAlert>

        <NDataTable
            remote
            :columns="columns"
            :data="result?.data ?? []"
            :loading="loading"
            :pagination="pagination"
            :row-key="(row: EmailListItem) => row.id"
            :row-props="rowProps"
            @update:page="(page: number) => router.push({ query: queryFromFilters({ ...filters, page }) })"
        />
    </NCard>
</template>

<style scoped>
.emails__filters {
    margin-bottom: 16px;
}

.emails__search {
    width: 260px;
}

.emails__select {
    width: 190px;
}

.emails__error {
    margin-bottom: 16px;
}

:deep(.emails__subject) {
    color: inherit;
    font-weight: 500;
    text-decoration: none;
}

:deep(.emails__subject:hover) {
    text-decoration: underline;
}

:deep(.emails__summary) {
    color: #888;
    font-size: 12px;
}
</style>
