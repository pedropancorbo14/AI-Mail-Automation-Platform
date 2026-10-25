<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import {
    NAlert,
    NButton,
    NCard,
    NCheckbox,
    NForm,
    NFormItem,
    NInput,
    NPopconfirm,
    NSpace,
    NSwitch,
    NTag,
    NText,
    useMessage,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import {
    deleteStandardApi,
    getStandardApi,
    saveStandardApi,
    testStandardApi,
    type IntegrationConnection,
    type StandardApiForm,
} from '@/api/integrations';
import { formatDateTime } from '@/utils/format';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const message = useMessage();
const canManage = computed(() => auth.can('integrations.manage'));

const connection = ref<IntegrationConnection | null>(null);
const editing = ref(false);
const busy = ref(false);
const errors = ref<Record<string, string | undefined>>({});

/** "Pedidos, Facturas y Citas": las partes de la API que tiene su sistema. */
function parts(c: IntegrationConnection): string {
    const names = [c.orders && 'Pedidos', c.invoices && 'Facturas', c.appointments && 'Citas'].filter((n): n is string => Boolean(n));
    return names.length > 1 ? `${names.slice(0, -1).join(', ')} y ${names.at(-1)}` : (names[0] ?? '');
}
const form = reactive<StandardApiForm>({ base_url: '', token: '', orders: true, invoices: false, appointments: false, enabled: true });

const statusInfo = {
    pending: { label: 'Sin probar', type: 'default' },
    active: { label: 'Conectado', type: 'success' },
    failing: { label: 'Falla', type: 'error' },
} as const;

function edit(): void {
    Object.assign(form, connection.value
        ? {
            base_url: connection.value.base_url,
            token: '',
            orders: connection.value.orders,
            invoices: connection.value.invoices,
            appointments: connection.value.appointments,
            enabled: connection.value.enabled,
        }
        : { base_url: '', token: '', orders: true, invoices: false, appointments: false, enabled: true });
    errors.value = {};
    editing.value = true;
}

function report(ok: boolean, error: string | null): void {
    ok ? message.success('Conexión correcta: la IA ya consulta tu sistema.') : message.error(error ?? 'La conexión falló.');
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};

    try {
        const { data, result } = await saveStandardApi(form);
        connection.value = data;
        editing.value = false;
        report(result.ok, result.error);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = { base_url: e.fieldError('base_url'), token: e.fieldError('token') };
        } else {
            message.error(e instanceof ApiError ? e.message : 'No se pudo guardar.');
        }
    } finally {
        busy.value = false;
    }
}

async function test(): Promise<void> {
    busy.value = true;

    try {
        const { data, result } = await testStandardApi();
        connection.value = data;
        report(result.ok, result.error);
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo probar.');
    } finally {
        busy.value = false;
    }
}

async function remove(): Promise<void> {
    try {
        await deleteStandardApi();
        connection.value = null;
        message.success('Sistema desconectado: la IA vuelve a usar los pedidos importados.');
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo desconectar.');
    }
}

onMounted(async () => {
    try {
        connection.value = await getStandardApi();
    } catch {
        connection.value = null;
    }
});
</script>

<template>
    <NCard title="Tu propio sistema (ERP o programa a medida)">
        <NText depth="3" class="api__hint">
            Si tu empresa tiene un programa propio con los pedidos o las facturas, tu informático puede publicar la
            <strong>API estándar</strong> de la plataforma (cuatro consultas de solo lectura, siempre del cliente que escribe)
            y la IA los consultará directamente. Si la conectas, se usa en lugar de los pedidos importados.
        </NText>

        <template v-if="!editing">
            <NSpace v-if="connection" vertical :size="8" data-test="connection">
                <NSpace align="center">
                    <NTag :type="statusInfo[connection.status].type" size="small">{{ statusInfo[connection.status].label }}</NTag>
                    <NTag v-if="!connection.enabled" size="small">Desactivada</NTag>
                    <strong>{{ connection.base_url }}</strong>
                </NSpace>
                <NText depth="3">
                    {{ parts(connection) }}
                    <template v-if="connection.last_tested_at"> · probada {{ formatDateTime(connection.last_tested_at) }}</template>
                </NText>
                <NAlert v-if="connection.status === 'failing' && connection.last_error" type="error" :show-icon="false">
                    {{ connection.last_error }}
                </NAlert>
            </NSpace>
            <NText v-else depth="3">Sin conectar.</NText>

            <NSpace v-if="canManage" class="api__actions">
                <NButton :type="connection ? 'default' : 'primary'" @click="edit">{{ connection ? 'Editar' : 'Conectar mi sistema' }}</NButton>
                <NButton v-if="connection" :loading="busy" data-test="test" @click="test">Probar conexión</NButton>
                <NPopconfirm v-if="connection" positive-text="Desconectar" negative-text="Cancelar" @positive-click="remove">
                    <template #trigger>
                        <NButton type="error" quaternary>Desconectar</NButton>
                    </template>
                    ¿Desconectar tu sistema? La IA dejará de consultarlo.
                </NPopconfirm>
            </NSpace>
        </template>

        <NForm v-else label-placement="top" class="api__form" @submit.prevent="save">
            <NFormItem label="Dirección de la API" :feedback="errors.base_url" :validation-status="errors.base_url ? 'error' : undefined">
                <NInput v-model:value="form.base_url" placeholder="https://erp.tuempresa.com/aimail" data-test="base-url" />
            </NFormItem>
            <NFormItem label="Clave" :feedback="errors.token ?? (connection?.has_token ? 'Déjala vacía para conservar la guardada.' : undefined)" :validation-status="errors.token ? 'error' : undefined">
                <NInput v-model:value="form.token" type="password" show-password-on="click" :placeholder="connection?.has_token ? '••••••••' : 'La que genera tu sistema'" data-test="token" />
            </NFormItem>
            <NFormItem label="Qué tiene tu sistema">
                <NSpace>
                    <NCheckbox v-model:checked="form.orders">Pedidos</NCheckbox>
                    <NCheckbox v-model:checked="form.invoices">Facturas</NCheckbox>
                    <NCheckbox v-model:checked="form.appointments" data-test="appointments">Citas</NCheckbox>
                </NSpace>
            </NFormItem>
            <NFormItem label="Activada">
                <NSwitch v-model:value="form.enabled" />
            </NFormItem>
            <NSpace>
                <NButton type="primary" attr-type="submit" :loading="busy" :disabled="!form.orders && !form.invoices && !form.appointments" data-test="save">Guardar y probar</NButton>
                <NButton @click="editing = false">Cancelar</NButton>
            </NSpace>
        </NForm>
    </NCard>
</template>

<style scoped>
.api__hint {
    display: block;
    margin-bottom: 16px;
}

.api__actions {
    margin-top: 12px;
}

.api__form {
    max-width: 560px;
}
</style>
