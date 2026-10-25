<script setup lang="ts">
import { computed, h, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    NAlert,
    NButton,
    NCard,
    NDataTable,
    NDrawer,
    NDrawerContent,
    NPopconfirm,
    NSpace,
    NSwitch,
    NTag,
    NText,
    useDialog,
    useMessage,
    type DataTableColumns,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import {
    deleteMailConnection,
    listMailConnections,
    startOAuth,
    testMailConnection,
    updateMailConnection,
    type ConnectionTestResult,
    type MailConnection,
    type MailConnectionStatus,
} from '@/api/mailConnections';
import ImapConnectionForm from '@/components/connections/ImapConnectionForm.vue';
import { formatDateTime } from '@/utils/format';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const message = useMessage();
const dialog = useDialog();

const connections = ref<MailConnection[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const testing = ref<number | null>(null);
const drawer = ref<{ open: boolean; connection: MailConnection | null }>({ open: false, connection: null });

const canManage = computed(() => auth.can('mail_connections.manage'));

const providerLabels = { imap: 'IMAP/SMTP', microsoft: 'Microsoft 365', google: 'Gmail' } as const;
const statusInfo: Record<MailConnectionStatus, { label: string; type: 'success' | 'warning' | 'error' | 'default' }> = {
    active: { label: 'Activo', type: 'success' },
    pending: { label: 'Sin probar', type: 'warning' },
    failing: { label: 'Falla', type: 'error' },
    disabled: { label: 'Desactivado', type: 'default' },
};

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        connections.value = (await listMailConnections()).data;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudieron cargar los buzones.';
    } finally {
        loading.value = false;
    }
}

/** Aviso de correos apartados: siguen en el buzón, pero la plataforma no los importó. */
function skippedText(count: number): string {
    return count === 1
        ? '1 correo no se pudo importar (sigue en el buzón): avisa al soporte de la plataforma.'
        : `${count} correos no se pudieron importar (siguen en el buzón): avisa al soporte de la plataforma.`;
}

function replace(connection: MailConnection): void {
    const index = connections.value.findIndex((c) => c.id === connection.id);
    index === -1 ? connections.value.unshift(connection) : connections.value.splice(index, 1, connection);
}

function showTestResult(connection: MailConnection, result: ConnectionTestResult): void {
    const line = (label: string, ok: boolean, detail: string | null) => `${ok ? '✓' : '✗'} ${label}${ok ? '' : `: ${detail ?? 'error'}`}`;

    (result.ok ? dialog.success : dialog.error)({
        title: result.ok ? `${connection.name}: conexión correcta` : `${connection.name}: hay un problema`,
        content: () => h('div', { style: 'white-space: pre-line' }, [
            line('Recepción (leer correos)', result.incoming_ok, result.incoming_error),
            '\n',
            line('Envío (responder)', result.outgoing_ok, result.outgoing_error),
            result.ok ? '\n\nLos correos nuevos se importarán en menos de un minuto.' : '',
        ]),
        positiveText: 'Entendido',
    });
}

async function test(connection: MailConnection): Promise<void> {
    testing.value = connection.id;

    try {
        const { connection: updated, result } = await testMailConnection(connection.id);
        replace(updated);
        showTestResult(updated, result);
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo probar la conexión.');
    } finally {
        testing.value = null;
    }
}

async function toggle(connection: MailConnection, enabled: boolean): Promise<void> {
    try {
        replace(await updateMailConnection(connection.id, { enabled }));
        message.success(enabled ? 'Buzón activado: pruébalo para empezar a recibir.' : 'Buzón desactivado: deja de sincronizarse.');
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo cambiar.');
    }
}

async function remove(connection: MailConnection): Promise<void> {
    try {
        await deleteMailConnection(connection.id);
        connections.value = connections.value.filter((c) => c.id !== connection.id);
        message.success('Buzón eliminado.');
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo eliminar.');
    }
}

async function connectOAuth(provider: 'microsoft' | 'google'): Promise<void> {
    try {
        window.location.href = await startOAuth(provider);
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo iniciar la conexión.');
    }
}

async function onSaved(connection: MailConnection, created: boolean): Promise<void> {
    replace(connection);
    drawer.value.open = false;

    // Un buzón sin probar no se sincroniza: se prueba nada más crearlo o cambiarlo.
    if (created || connection.status === 'pending') {
        await test(connection);
    } else {
        message.success('Cambios guardados.');
    }
}

onMounted(async () => {
    await load();

    // Vuelta desde Microsoft/Google (MAIL_CONNECTIONS_OAUTH_REDIRECT_TO apunta aquí).
    if (route.query.status === 'connected') {
        message.success('Cuenta conectada. Pruébala para empezar a recibir correos.');
    } else if (route.query.status === 'error') {
        message.error(String(route.query.message ?? 'No se pudo conectar la cuenta.'));
    }

    if (route.query.status) {
        void router.replace({ query: {} });
    }
});

const columns = computed<DataTableColumns<MailConnection>>(() => [
    {
        title: 'Buzón',
        key: 'name',
        render: (row) => h('div', [h('strong', row.name), h(NText, { depth: 3, style: 'display:block' }, () => row.email)]),
    },
    { title: 'Tipo', key: 'provider', width: 140, render: (row) => providerLabels[row.provider] },
    {
        title: 'Estado',
        key: 'status',
        render: (row) => h('div', [
            h(NTag, { type: statusInfo[row.status].type, size: 'small' }, () => statusInfo[row.status].label),
            row.last_error && row.status !== 'active' ? h(NText, { type: 'error', style: 'display:block;font-size:12px;margin-top:4px' }, () => row.last_error) : null,
            row.skipped_messages ? h(NText, { type: 'warning', style: 'display:block;font-size:12px;margin-top:4px' }, () => skippedText(row.skipped_messages ?? 0)) : null,
        ]),
    },
    { title: 'Última prueba', key: 'last_tested_at', width: 140, render: (row) => formatDateTime(row.last_tested_at) },
    {
        title: '',
        key: 'actions',
        width: 330,
        render: (row) => !canManage.value ? null : h(NSpace, { size: 8, justify: 'end', align: 'center', wrap: false }, () => [
            h(NButton, { size: 'small', loading: testing.value === row.id, onClick: () => test(row) }, () => 'Probar'),
            row.provider === 'imap' ? h(NButton, { size: 'small', onClick: () => (drawer.value = { open: true, connection: row }) }, () => 'Editar') : null,
            h(NSwitch, { size: 'small', value: row.status !== 'disabled', 'onUpdate:value': (enabled: boolean) => toggle(row, enabled) }),
            h(NPopconfirm, { onPositiveClick: () => remove(row), positiveText: 'Eliminar', negativeText: 'Cancelar' }, {
                trigger: () => h(NButton, { size: 'small', type: 'error', quaternary: true }, () => 'Eliminar'),
                default: () => '¿Eliminar este buzón? Si ya tiene correos guardados, desactívalo en su lugar.',
            }),
        ]),
    },
]);
</script>

<template>
    <NCard title="Buzones de correo">
        <template v-if="canManage" #header-extra>
            <NSpace>
                <NButton type="primary" @click="drawer = { open: true, connection: null }">Añadir buzón IMAP</NButton>
                <NButton @click="connectOAuth('microsoft')">Conectar Microsoft 365</NButton>
                <NButton @click="connectOAuth('google')">Conectar Gmail</NButton>
            </NSpace>
        </template>

        <NText depth="3" class="connections__hint">
            Los buzones activos se revisan cada minuto. Las contraseñas y tokens se guardan cifrados y nunca se vuelven a mostrar.
        </NText>
        <NAlert v-if="error" type="error" class="connections__error">{{ error }}</NAlert>

        <NDataTable :columns="columns" :data="connections" :loading="loading" :row-key="(row: MailConnection) => row.id" />

        <NDrawer v-model:show="drawer.open" :width="620" placement="right">
            <NDrawerContent :title="drawer.connection ? `Editar ${drawer.connection.name}` : 'Añadir buzón IMAP'" closable>
                <ImapConnectionForm
                    :key="drawer.connection?.id ?? 'new'"
                    :connection="drawer.connection"
                    @saved="onSaved"
                    @cancel="drawer.open = false"
                />
            </NDrawerContent>
        </NDrawer>
    </NCard>
</template>

<style scoped>
.connections__hint {
    display: block;
    margin-bottom: 12px;
}

.connections__error {
    margin-bottom: 12px;
}
</style>
