<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import {
    NAlert,
    NButton,
    NCard,
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
    deleteIntegration,
    getIntegration,
    saveWooCommerce,
    testIntegration,
    type IntegrationConnection,
    type WooCommerceForm,
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
const form = reactive<WooCommerceForm>({ base_url: '', consumer_key: '', consumer_secret: '', enabled: true });

const statusInfo = {
    pending: { label: 'Sin probar', type: 'default' },
    active: { label: 'Conectada', type: 'success' },
    failing: { label: 'Falla', type: 'error' },
} as const;

function edit(): void {
    Object.assign(form, { base_url: connection.value?.base_url ?? '', consumer_key: '', consumer_secret: '', enabled: connection.value?.enabled ?? true });
    errors.value = {};
    editing.value = true;
}

function report(ok: boolean, error: string | null): void {
    ok ? message.success('Tienda conectada: la IA ya consulta sus pedidos.') : message.error(error ?? 'La conexión falló.');
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};

    try {
        const { data, result } = await saveWooCommerce(form);
        connection.value = data;
        editing.value = false;
        report(result.ok, result.error);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = {
                base_url: e.fieldError('base_url'),
                consumer_key: e.fieldError('consumer_key'),
                consumer_secret: e.fieldError('consumer_secret'),
            };
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
        const { data, result } = await testIntegration('woocommerce');
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
        await deleteIntegration('woocommerce');
        connection.value = null;
        message.success('Tienda desconectada.');
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo desconectar.');
    }
}

onMounted(async () => {
    try {
        connection.value = await getIntegration('woocommerce');
    } catch {
        connection.value = null;
    }
});
</script>

<template>
    <NCard title="Tienda WooCommerce">
        <NText depth="3" class="woo__hint">
            Si vendes con WooCommerce, crea unas claves de API de <strong>solo lectura</strong> en
            WooCommerce → Ajustes → Avanzado → API REST y pégalas aquí: la IA consultará los pedidos de quien escribe
            (estado, productos y, con el plugin Shipment Tracking, el seguimiento). Si también conectas tu propio sistema,
            se usa ese.
        </NText>

        <template v-if="!editing">
            <NSpace v-if="connection" vertical :size="8" data-test="woo-connection">
                <NSpace align="center">
                    <NTag :type="statusInfo[connection.status].type" size="small">{{ statusInfo[connection.status].label }}</NTag>
                    <NTag v-if="!connection.enabled" size="small">Desactivada</NTag>
                    <strong>{{ connection.base_url }}</strong>
                </NSpace>
                <NText depth="3">
                    Clave {{ connection.consumer_key_hint }}
                    <template v-if="connection.last_tested_at"> · probada {{ formatDateTime(connection.last_tested_at) }}</template>
                </NText>
                <NAlert v-if="connection.status === 'failing' && connection.last_error" type="error" :show-icon="false">
                    {{ connection.last_error }}
                </NAlert>
            </NSpace>
            <NText v-else depth="3">Sin conectar.</NText>

            <NSpace v-if="canManage" class="woo__actions">
                <NButton :type="connection ? 'default' : 'primary'" @click="edit">{{ connection ? 'Editar' : 'Conectar mi tienda' }}</NButton>
                <NButton v-if="connection" :loading="busy" @click="test">Probar conexión</NButton>
                <NPopconfirm v-if="connection" positive-text="Desconectar" negative-text="Cancelar" @positive-click="remove">
                    <template #trigger>
                        <NButton type="error" quaternary>Desconectar</NButton>
                    </template>
                    ¿Desconectar la tienda? La IA dejará de consultar sus pedidos.
                </NPopconfirm>
            </NSpace>
        </template>

        <NForm v-else label-placement="top" class="woo__form" @submit.prevent="save">
            <NFormItem label="Dirección de la tienda" :feedback="errors.base_url" :validation-status="errors.base_url ? 'error' : undefined">
                <NInput v-model:value="form.base_url" placeholder="https://www.tutienda.com" data-test="woo-url" />
            </NFormItem>
            <NFormItem label="Consumer key" :feedback="errors.consumer_key ?? (connection ? 'Déjalas vacías para conservar las guardadas.' : undefined)" :validation-status="errors.consumer_key ? 'error' : undefined">
                <NInput v-model:value="form.consumer_key" placeholder="ck_…" data-test="woo-key" />
            </NFormItem>
            <NFormItem label="Consumer secret" :feedback="errors.consumer_secret" :validation-status="errors.consumer_secret ? 'error' : undefined">
                <NInput v-model:value="form.consumer_secret" type="password" show-password-on="click" placeholder="cs_…" data-test="woo-secret" />
            </NFormItem>
            <NFormItem label="Activada">
                <NSwitch v-model:value="form.enabled" />
            </NFormItem>
            <NSpace>
                <NButton type="primary" attr-type="submit" :loading="busy">Guardar y probar</NButton>
                <NButton @click="editing = false">Cancelar</NButton>
            </NSpace>
        </NForm>
    </NCard>
</template>

<style scoped>
.woo__hint {
    display: block;
    margin-bottom: 16px;
}

.woo__actions {
    margin-top: 12px;
}

.woo__form {
    max-width: 560px;
}
</style>
