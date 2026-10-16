<script setup lang="ts">
import { computed, h, onMounted, ref, watch } from 'vue';
import { NAlert, NCard, NDataTable, NDatePicker, NSelect, NSpace, NTag, NText, NTooltip, type DataTableColumns } from 'naive-ui';
import { ApiError } from '@/api/http';
import { listAuditLogs, type AuditFilters, type AuditLogEntry } from '@/api/audit';
import { listUsers, type CompanyUser } from '@/api/company';
import type { Paginated } from '@/api/types';
import { actionLabel, actionTone, actorName, categoryLabels, describeChanges, describeMetadata, subjectLabel } from '@/utils/audit';
import { options } from '@/utils/labels';
import { formatDateTime } from '@/utils/format';

const filters = ref<AuditFilters>({ category: null, user: null, from: null, to: null, page: 1 });
const result = ref<Paginated<AuditLogEntry> | null>(null);
const users = ref<CompanyUser[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;

    try {
        result.value = await listAuditLogs(filters.value);
        error.value = null;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar el registro de auditoría.';
    } finally {
        loading.value = false;
    }
}

function update(changes: Partial<AuditFilters>): void {
    filters.value = { ...filters.value, ...changes, page: changes.page ?? 1 };
}

watch(filters, load);

onMounted(async () => {
    await load();
    users.value = (await listUsers().catch(() => null))?.data ?? [];
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
    pageSize: result.value?.meta.per_page ?? 50,
    itemCount: result.value?.meta.total ?? 0,
    prefix: ({ itemCount }: { itemCount: number | undefined }) => `${itemCount ?? 0} acciones`,
}));

const lines = (items: string[]) => items.map((line) => h('div', { class: 'audit__line' }, line));

const columns: DataTableColumns<AuditLogEntry> = [
    { title: 'Cuándo', key: 'created_at', width: 140, render: (row) => formatDateTime(row.created_at) },
    {
        title: 'Quién',
        key: 'actor',
        width: 190,
        render: (row) => h('div', [
            h('div', actorName(row)),
            row.actor?.email && row.actor.name ? h(NText, { depth: 3, class: 'audit__small' }, () => row.actor!.email) : null,
        ]),
    },
    {
        title: 'Acción',
        key: 'action',
        render: (row) => {
            const tone = actionTone(row.action);

            return h('div', [
                tone === 'default'
                    ? h('strong', actionLabel(row.action))
                    : h(NTag, { type: tone, size: 'small', bordered: false }, () => actionLabel(row.action)),
                subjectLabel(row) ? h(NText, { depth: 2 }, () => ` · ${subjectLabel(row)}`) : null,
                ...lines(describeChanges(row)),
                ...lines(describeMetadata(row)),
            ]);
        },
    },
    {
        title: 'Desde',
        key: 'ip',
        width: 140,
        render: (row) => row.ip
            ? h(NTooltip, null, { trigger: () => h('span', row.ip ?? ''), default: () => row.user_agent || 'Navegador desconocido' })
            : h(NText, { depth: 3 }, () => '—'),
    },
];
</script>

<template>
    <NCard title="Auditoría">
        <NText depth="3" class="audit__intro">
            Qué ha hecho cada persona en la empresa: inicios de sesión, usuarios, buzones, documentación y ajustes de la IA.
            Nadie puede modificar ni borrar este registro.
        </NText>

        <NSpace class="audit__filters" :size="12" wrap>
            <NSelect
                :value="filters.category"
                :options="options(categoryLabels)"
                clearable
                placeholder="Tipo de acción"
                class="audit__select"
                @update:value="(category) => update({ category })"
            />
            <NSelect
                :value="filters.user"
                :options="users.map((u) => ({ label: u.name, value: u.id }))"
                clearable
                filterable
                placeholder="Persona"
                class="audit__select"
                @update:value="(user) => update({ user })"
            />
            <NDatePicker :value="dateRange" type="daterange" clearable :actions="['clear']" @update:value="onDateRange" />
        </NSpace>

        <NAlert v-if="error" type="error" class="audit__error">{{ error }}</NAlert>

        <NDataTable
            remote
            :columns="columns"
            :data="result?.data ?? []"
            :loading="loading"
            :pagination="pagination"
            :row-key="(row: AuditLogEntry) => row.id"
            @update:page="(page: number) => update({ page })"
        />
    </NCard>
</template>

<style scoped>
.audit__intro {
    display: block;
    margin-bottom: 16px;
}

.audit__filters,
.audit__error {
    margin-bottom: 16px;
}

.audit__select {
    width: 200px;
}

:deep(.audit__line) {
    color: #666;
    font-size: 12px;
}

:deep(.audit__small) {
    display: block;
    font-size: 12px;
}
</style>
