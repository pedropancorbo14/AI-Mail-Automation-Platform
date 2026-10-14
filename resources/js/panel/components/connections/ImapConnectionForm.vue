<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { NAlert, NButton, NDivider, NForm, NFormItem, NGrid, NGridItem, NInput, NInputNumber, NSelect, NSpace } from 'naive-ui';
import { ApiError } from '@/api/http';
import {
    createImapConnection,
    updateMailConnection,
    withoutEmptyPasswords,
    type Encryption,
    type ImapConnectionInput,
    type MailConnection,
} from '@/api/mailConnections';

const props = defineProps<{ connection?: MailConnection | null }>();
const emit = defineEmits<{ saved: [connection: MailConnection, created: boolean]; cancel: [] }>();

const editing = computed(() => !!props.connection);

const server = (settings?: { host: string; port: number; encryption: Encryption; username: string }, port = 993) => ({
    host: settings?.host ?? '',
    port: settings?.port ?? port,
    encryption: settings?.encryption ?? ('ssl' as Encryption),
    username: settings?.username ?? '',
    password: '',
});

const form = reactive<ImapConnectionInput>({
    name: props.connection?.name ?? '',
    email: props.connection?.email ?? '',
    imap: server(props.connection?.settings.imap, 993),
    smtp: server(props.connection?.settings.smtp, 465),
});

const saving = ref(false);
const error = ref<ApiError | null>(null);

const encryptions = [
    { label: 'SSL/TLS', value: 'ssl' },
    { label: 'STARTTLS', value: 'starttls' },
    { label: 'Sin cifrar (solo pruebas)', value: 'none' },
];

// El usuario suele ser el propio email: se rellena si aún está vacío.
function onEmailBlur(): void {
    for (const key of ['imap', 'smtp'] as const) {
        if (!form[key].username) {
            form[key].username = form.email;
        }
    }
}

const fieldError = (field: string) => error.value?.fieldError(field);
const status = (field: string) => (fieldError(field) ? 'error' : undefined);

async function submit(): Promise<void> {
    saving.value = true;
    error.value = null;

    try {
        const saved = props.connection
            ? await updateMailConnection(props.connection.id, withoutEmptyPasswords(form))
            : await createImapConnection(form);
        emit('saved', saved, !props.connection);
    } catch (e) {
        error.value = e instanceof ApiError ? e : new ApiError(0, 'No se pudo guardar el buzón.');
    } finally {
        saving.value = false;
    }
}

defineExpose({ form });
</script>

<template>
    <NForm :model="form" label-placement="top" @submit.prevent="submit">
        <NAlert v-if="error && error.status !== 422" type="error" class="form__error">{{ error.message }}</NAlert>

        <NGrid :cols="2" :x-gap="12">
            <NGridItem>
                <NFormItem label="Nombre" :feedback="fieldError('name')" :validation-status="status('name')">
                    <NInput v-model:value="form.name" placeholder="Soporte" />
                </NFormItem>
            </NGridItem>
            <NGridItem>
                <NFormItem label="Dirección de email" :feedback="fieldError('email')" :validation-status="status('email')">
                    <NInput v-model:value="form.email" placeholder="soporte@empresa.com" :disabled="editing" @blur="onEmailBlur" />
                </NFormItem>
            </NGridItem>
        </NGrid>

        <slot name="detect" :form="form" />

        <template v-for="key in (['imap', 'smtp'] as const)" :key="key">
            <NDivider title-placement="left">{{ key === 'imap' ? 'Recepción (IMAP)' : 'Envío (SMTP)' }}</NDivider>
            <NGrid :cols="6" :x-gap="12">
                <NGridItem :span="3">
                    <NFormItem label="Servidor" :feedback="fieldError(`${key}.host`)" :validation-status="status(`${key}.host`)">
                        <NInput v-model:value="form[key].host" :placeholder="key === 'imap' ? 'imap.empresa.com' : 'smtp.empresa.com'" :data-test="`${key}-host`" />
                    </NFormItem>
                </NGridItem>
                <NGridItem :span="1">
                    <NFormItem label="Puerto" :feedback="fieldError(`${key}.port`)" :validation-status="status(`${key}.port`)">
                        <NInputNumber v-model:value="form[key].port" :min="1" :max="65535" :show-button="false" />
                    </NFormItem>
                </NGridItem>
                <NGridItem :span="2">
                    <NFormItem label="Cifrado" :feedback="fieldError(`${key}.encryption`)" :validation-status="status(`${key}.encryption`)">
                        <NSelect v-model:value="form[key].encryption" :options="encryptions" />
                    </NFormItem>
                </NGridItem>
                <NGridItem :span="3">
                    <NFormItem label="Usuario" :feedback="fieldError(`${key}.username`)" :validation-status="status(`${key}.username`)">
                        <NInput v-model:value="form[key].username" :input-props="{ autocomplete: 'off' }" />
                    </NFormItem>
                </NGridItem>
                <NGridItem :span="3">
                    <NFormItem label="Contraseña" :feedback="fieldError(`${key}.password`)" :validation-status="status(`${key}.password`)">
                        <NInput
                            v-model:value="form[key].password"
                            type="password"
                            show-password-on="click"
                            :placeholder="editing ? 'Sin cambios' : ''"
                            :input-props="{ autocomplete: 'new-password' }"
                        />
                    </NFormItem>
                </NGridItem>
            </NGrid>
        </template>

        <NSpace justify="end">
            <NButton @click="emit('cancel')">Cancelar</NButton>
            <NButton type="primary" attr-type="submit" :loading="saving">{{ editing ? 'Guardar cambios' : 'Añadir y probar' }}</NButton>
        </NSpace>
    </NForm>
</template>

<style scoped>
.form__error {
    margin-bottom: 12px;
}
</style>
