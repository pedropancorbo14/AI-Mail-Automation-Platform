<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { NAlert, NButton, NCard, NForm, NFormItem, NInput } from 'naive-ui';
import { ApiError } from '@/api/http';
import { safeRedirect } from '@/router';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const form = reactive({ email: '', password: '' });
const loading = ref(false);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        await auth.login(form.email, form.password);
        await router.replace(safeRedirect(route.query.redirect));
    } catch (e) {
        error.value = e instanceof ApiError ? (e.fieldError('email') ?? e.fieldError('password') ?? e.message) : 'Ha ocurrido un error.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="login">
        <NCard title="AI Mail" class="login__card">
            <p class="login__subtitle">Inicia sesión en el panel de tu empresa</p>

            <NAlert v-if="error" type="error" :show-icon="false" class="login__error" data-test="login-error">
                {{ error }}
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
</style>
