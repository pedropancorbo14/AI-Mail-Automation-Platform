<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { NAlert, NButton, NCard, NForm, NFormItem, NInput } from 'naive-ui';
import { ApiError } from '@/api/http';
import { getSignupInfo, resendVerification } from '@/api/signup';
import { safeRedirect } from '@/router';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const form = reactive({ email: '', password: '' });
const loading = ref(false);
const error = ref<string | null>(null);
// Contraseña correcta pero email sin confirmar: se puede pedir otro enlace.
const unverified = ref(false);
const notice = ref<{ type: 'success' | 'error' | 'info'; text: string } | null>(null);
const signupEnabled = ref(false);

onMounted(async () => {
    // Vuelta desde el enlace del email de confirmación.
    if (route.query.verified === '1') {
        notice.value = { type: 'success', text: 'Email confirmado. Ya puedes entrar.' };
    } else if (route.query.verified === 'invalid') {
        notice.value = { type: 'error', text: 'El enlace no es válido o ha caducado. Entra con tu email y contraseña para pedir otro.' };
    }

    try {
        signupEnabled.value = (await getSignupInfo()).enabled;
    } catch {
        signupEnabled.value = false;
    }
});

async function resend(): Promise<void> {
    try {
        notice.value = { type: 'info', text: await resendVerification(form.email) };
        error.value = null;
        unverified.value = false;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Ha ocurrido un error.';
    }
}

async function submit(): Promise<void> {
    loading.value = true;
    error.value = null;
    unverified.value = false;

    try {
        await auth.login(form.email, form.password);
        await router.replace(safeRedirect(route.query.redirect));
    } catch (e) {
        error.value = e instanceof ApiError ? (e.fieldError('email') ?? e.fieldError('password') ?? e.message) : 'Ha ocurrido un error.';
        unverified.value = e instanceof ApiError && e.body.code === 'email_unverified';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="login">
        <NCard title="AI Mail" class="login__card">
            <p class="login__subtitle">Inicia sesión en el panel de tu empresa</p>

            <NAlert v-if="notice" :type="notice.type" :show-icon="false" class="login__error" data-test="login-notice">
                {{ notice.text }}
            </NAlert>
            <NAlert v-if="error" type="error" :show-icon="false" class="login__error" data-test="login-error">
                {{ error }}
                <NButton v-if="unverified" text type="primary" class="login__resend" data-test="resend" @click="resend">Enviarme otro enlace</NButton>
            </NAlert>

            <NForm @submit.prevent="submit">
                <NFormItem label="Email" path="email">
                    <NInput v-model:value="form.email" type="text" placeholder="tu@empresa.com" :input-props="{ autocomplete: 'username', type: 'email' }" />
                </NFormItem>
                <NFormItem label="Contraseña" path="password">
                    <NInput v-model:value="form.password" type="password" show-password-on="click" :input-props="{ autocomplete: 'current-password' }" />
                </NFormItem>
                <NButton type="primary" attr-type="submit" block :loading="loading" :disabled="!form.email || !form.password">
                    Entrar
                </NButton>
            </NForm>

            <p v-if="signupEnabled" class="login__signup" data-test="signup-link">
                ¿Tu empresa aún no tiene cuenta? <RouterLink :to="{ name: 'signup' }">Pruébalo gratis</RouterLink>
            </p>
        </NCard>
    </div>
</template>

<style scoped>
.login {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: #f5f6f8;
}

.login__card {
    width: 100%;
    max-width: 380px;
}

.login__subtitle {
    margin: 0 0 16px;
    color: #666;
}

.login__error {
    margin-bottom: 16px;
}

.login__resend {
    display: block;
    margin-top: 6px;
}

.login__signup {
    margin: 16px 0 0;
    text-align: center;
}
</style>
