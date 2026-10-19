<script setup lang="ts">
import { computed, h, onMounted, ref } from 'vue';
import {
    NAlert,
    NButton,
    NCard,
    NDataTable,
    NInput,
    NPopconfirm,
    NSpace,
    NTag,
    NText,
    NUpload,
    useMessage,
    type DataTableColumns,
    type UploadCustomRequestOptions,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import {
    TEMPLATE_URL,
    deleteAllOrders,
    importOrders,
    listOrders,
    orderStatusLabels,
    rowErrors,
    type CustomerOrder,
    type RowError,
} from '@/api/orders';
import StandardApiCard from '@/components/orders/StandardApiCard.vue';
import WooCommerceCard from '@/components/orders/WooCommerceCard.vue';
import { formatDateTime } from '@/utils/format';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const message = useMessage();
const canManage = computed(() => auth.can('orders.manage'));

const orders = ref<CustomerOrder[]>([]);
const total = ref(0);
const totalOrders = ref(0);
const lastImportedAt = ref<string | null>(null);
const page = ref(1);
const q = ref('');
const loading = ref(false);
const error = ref<string | null>(null);

// Resultado de la última importación rechazada: qué falla en cada fila.
const importError = ref<string | null>(null);
const failedRows = ref<RowError[]>([]);
const failedTotal = ref(0);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

async function load(): Promise<void> {
    loading.value = true;

    try {
        const result = await listOrders(page.value, q.value.trim());
        orders.value = result.data;
        total.value = result.meta.total;
        totalOrders.value = result.meta.total_orders;
        lastImportedAt.value = result.meta.last_imported_at;
        error.value = null;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudieron cargar los pedidos.';
    } finally {
        loading.value = false;
    }
}

function search(): void {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        page.value = 1;
        load();
    }, 350);
}

async function upload({ file, onFinish, onError }: UploadCustomRequestOptions): Promise<void> {
    importError.value = null;
    failedRows.value = [];

    try {
        const result = await importOrders(file.file as File);
        message.success(`Importados ${result.rows} pedidos: ${result.created} nuevos y ${result.updated} actualizados.`);
        onFinish();
        page.value = 1;
        await load();
    } catch (e) {
        const { rows, total: count } = rowErrors(e);
        importError.value = e instanceof ApiError ? e.message : 'No se pudo importar el fichero.';
        failedRows.value = rows;
        failedTotal.value = count;
        onError();
    }
}

async function removeAll(): Promise<void> {
    try {
        const deleted = await deleteAllOrders();
        message.success(`${deleted} pedidos borrados: la IA ya no los consultará.`);
        page.value = 1;
        await load();
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudieron borrar.');
    }
}

onMounted(load);

const columns: DataTableColumns<CustomerOrder> = [
    { title: 'Pedido', key: 'number', width: 120, render: (row) => h('strong', row.number) },
    {
        title: 'Cliente',
        key: 'customer_email',
        render: (row) => h('div', [
            h('span', row.customer_name ?? row.customer_email),
            row.customer_name ? h(NText, { depth: 3, style: 'display:block;font-size:12px' }, () => row.customer_email) : null,
        ]),
    },
    { title: 'Estado', key: 'status', width: 150, render: (row) => h(NTag, { size: 'small' }, () => orderStatusLabels[row.status]) },
    { title: 'Fecha', key: 'placed_at', width: 140, render: (row) => formatDateTime(row.placed_at) },
    {
        title: 'Total',
        key: 'total',
        width: 110,
        render: (row) => row.total === null ? '—' : `${row.total.toLocaleString('es-ES', { minimumFractionDigits: 2 })} ${row.currency ?? ''}`,
    },
    {
        title: 'Envío',
        key: 'shipment',
        render: (row) => row.shipment ? [row.shipment.carrier, row.shipment.tracking_number].filter(Boolean).join(' · ') : '—',
    },
];

const pagination = computed(() => ({
    page: page.value,
    pageSize: 25,
    itemCount: total.value,
    onUpdatePage: (value: number) => {
        page.value = value;
        load();
    },
}));
</script>

<template>
    <NSpace vertical :size="16">
        <NCard title="Pedidos importados">
            <NText depth="3" class="orders__hint">
                Si tu empresa no usa una tienda online conectada, sube aquí tus pedidos en un CSV (por ejemplo, exportado desde Excel).
                La IA los consultará para responder a los clientes, siempre solo los pedidos de quien escribe.
                Volver a subir el fichero actualiza los pedidos por su número.
            </NText>

            <NSpace v-if="canManage" align="center" class="orders__upload">
                <NUpload :custom-request="upload" accept=".csv,text/csv" :show-file-list="false" :multiple="false">
                    <NButton type="primary">Importar CSV</NButton>
                </NUpload>
                <a :href="TEMPLATE_URL" download data-test="template">Descargar la plantilla</a>
                <NPopconfirm v-if="totalOrders > 0" positive-text="Borrar todos" negative-text="Cancelar" @positive-click="removeAll">
                    <template #trigger>
                        <NButton type="error" quaternary>Borrar todos</NButton>
                    </template>
                    ¿Borrar los {{ totalOrders }} pedidos importados? La IA dejará de consultarlos.
                </NPopconfirm>
            </NSpace>

            <NAlert v-if="importError" type="error" :title="importError" class="orders__alert" data-test="import-error">
                <ul v-if="failedRows.length" class="orders__rows">
                    <li v-for="row in failedRows" :key="row.row">
                        <strong>Fila {{ row.row }}:</strong> {{ row.errors.join(' ') }}
                    </li>
                </ul>
                <NText v-if="failedTotal > failedRows.length" depth="3">
                    … y {{ failedTotal - failedRows.length }} filas más con errores.
                </NText>
            </NAlert>

            <NText v-if="lastImportedAt" depth="3" class="orders__summary">
                {{ totalOrders }} pedidos · última importación {{ formatDateTime(lastImportedAt) }}
            </NText>

            <NInput v-model:value="q" placeholder="Buscar por número, email o nombre" clearable class="orders__search" @update:value="search" />
            <NAlert v-if="error" type="error" class="orders__alert">{{ error }}</NAlert>
            <NDataTable
                remote
                :columns="columns"
                :data="orders"
                :loading="loading"
                :pagination="pagination"
                :scroll-x="860"
                :row-key="(row: CustomerOrder) => row.id"
            />
        </NCard>

        <WooCommerceCard />
        <StandardApiCard />
    </NSpace>
</template>

<style scoped>
.orders__hint {
    display: block;
    margin-bottom: 16px;
}

.orders__upload,
.orders__alert,
.orders__summary,
.orders__search {
    margin-bottom: 12px;
}

.orders__summary {
    display: block;
}

.orders__rows {
    margin: 8px 0 0;
    padding-left: 18px;
}
</style>
