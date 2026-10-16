<script setup lang="ts">
import { ref } from 'vue';
import { NButton, NCard, NForm, NFormItem, NInput, NSpace, NText, useMessage } from 'naive-ui';
import { ApiError } from '@/api/http';
import { changePassword, logoutOtherDevices, type PasswordChange } from '@/api/account';
import { roleLabels } from '@/menu';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const message = useMessage();

const empty = (): PasswordChange => ({ current_password: '', password: '', password_confirmation: '' });
const form = ref<PasswordChange>(empty());
const errors = ref<Partial<Record<keyof PasswordChange, string>>>({});
const saving = ref(false);

const othersPassword = ref('');
const othersError = ref<string | null>(null);
const closing = ref(false);

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};

    try {
        message.success(await changePassword(form.value));
        form.value = empty();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            for (const field of ['current_password', 'password'] as const) {
                errors.value[field] = e.fieldError(field) ?? undefined;
            }
        } else {
            message.error(e instanceof ApiError ? e.message : 'No se pudo cambiar la contraseña.');
        }
    } finally {
        saving.value = false;
    }
}

async function closeOthers(): Promise<void> {
    closing.value = true;
    othersError.value = null;

    try {
        message.success(await logoutOtherDevices(othersPassword.value));
        othersPassword.value = '';
    } catch (e) {
        othersError.value = e instanceof ApiError ? (e.fieldError('password') ?? e.message) : 'No se pudo cerrar las demás sesiones.';
    } finally {
        closing.value = false;
    }
}
</script>

<template>
    <NSpace vertical :size="16" class="account">
        <NCard title="Mi cuenta">
            <div class="account__who">
                <strong>{{ auth.user?.name }}</strong>
                <NText depth="3">{{ auth.user?.email }} · {{ auth.user ? roleLabels[auth.user.role] : '' }}</NText>
            </div>
        </NCard>

        <NCard title="Cambiar la contraseña">
            <NForm class="account__form" @submit.prevent="submit">
                <NFormItem
                    label="Contraseña actual"
                    :validation-status="errors.current_password ? 'error' : undefined"
                    :feedback="errors.current_password"
                >
                    <NInput v-model:value="form.current_password" type="password" placeholder="" show-password-on="click" :input-props="{ autocomplete: 'current-password' }" />
                </NFormItem>
                <NFormItem
                    label="Nueva contraseña (mínimo 10 caracteres)"
                    :validation-status="errors.password ? 'error' : undefined"
                    :feedback="errors.password"
                >
                    <NInput v-model:value="form.password" type="password" placeholder="" show-password-on="click" :input-props="{ autocomplete: 'new-password' }" />
                </NFormItem>
                <NFormItem label="Repite la nueva contraseña">
                    <NInput v-model:value="form.password_confirmation" type="password" placeholder="" show-password-on="click" :input-props="{ autocomplete: 'new-password' }" />
                </NFormItem>
                <NText depth="3" class="account__hint">Al cambiarla se cerrará la sesión en los demás dispositivos y se revocarán los tokens de API.</NText>
                <NButton type="primary" attr-type="submit" :loading="saving" :disabled="!form.current_password || !form.password">Cambiar contraseña</NButton>
            </NForm>
        </NCard>

        <NCard title="Cerrar la sesión en los demás dispositivos">
            <NText depth="3" class="account__hint">
                Si has usado el panel en otro equipo o crees que alguien ha entrado con tu cuenta. Esta sesión sigue abierta.
            </NText>
            <NForm class="account__form" @submit.prevent="closeOthers">
                <NFormItem label="Tu contraseña" :validation-status="othersError ? 'error' : undefined" :feedback="othersError ?? undefined">
                    <NInput v-model:value="othersPassword" type="password" placeholder="" show-password-on="click" :input-props="{ autocomplete: 'current-password' }" />
                </NFormItem>
                <NButton attr-type="submit" :loading="closing" :disabled="!othersPassword">Cerrar las demás sesiones</NButton>
            </NForm>
        </NCard>
    </NSpace>
</template>

<style scoped>
.account__who {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.account__form {
    max-width: 420px;
}

.account__hint {
    display: block;
    margin-bottom: 12px;
}
</style>
