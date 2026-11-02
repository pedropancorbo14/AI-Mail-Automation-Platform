<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { NAlert, NButton, NCard, NCheckbox, NForm, NFormItem, NInput, NResult, NSpin } from 'naive-ui';
import { ApiError } from '@/api/http';
import { getSignupInfo, signup, type SignupInfo } from '@/api/signup';

const info = ref<SignupInfo | null>(null);
const loading = ref(true);
const sending = ref(false);
const sent = ref<string | null>(null);
const error = ref<string | null>(null);
const errors = ref<Record<string, string | undefined>>({});
const form = reactive({ company: '', name: '', email: '', password: '', password_confirmation: '', accept_terms: false, website: '' });

onMounted(async () => {
    try {
        info.value = await getSignupInfo();
    } catch {
        info.value = { enabled: false, trial_days: 0 };
    } finally {
        loading.value = false;
    }
});

async function submit(): Promise<void> {
    sending.value = true;
    error.value = null;
    errors.value = {};

    try {
        sent.value = await signup({ ...form });
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = Object.fromEntries(['company', 'name', 'email', 'password', 'accept_terms'].map((f) => [f, e.fieldError(f) ?? undefined]));
        }
        error.value = e instanceof ApiError
            ? (e.status === 429 ? 'Demasiados intentos. Prueba dentro de un rato.' : e.message)
            : 'Ha ocurrido un error.';
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <div class="signup">
        <NCard title="Crea la cuenta de tu empresa" class="signup__card">
            <NSpin :show="loading">
                <NResult v-if="sent" status="success" title="Revisa tu email" :description="sent" data-test="signup-sent">
                    <template #footer>
                        <RouterLink :to="{ name: 'login' }">Ir a iniciar sesión</RouterLink>
                    </template>
                </NResult>

                <NResult v-else-if="info && !info.enabled" status="info" title="Alta no disponible" description="Ahora mismo las altas las hacemos nosotros. Escríbenos y te preparamos la cuenta." data-test="signup-disabled">
                    <template #footer>
                        <RouterLink :to="{ name: 'login' }">Ya tengo cuenta</RouterLink>
                    </template>
                </NResult>

                <template v-else-if="info">
                    <p class="signup__subtitle">{{ info.trial_days }} días de prueba gratis, sin tarjeta.</p>

                    <NAlert v-if="error" type="error" :show-icon="false" class="signup__error" data-test="signup-error">{{ error }}</NAlert>

                    <NForm @submit.prevent="submit">
                        <NFormItem label="Empresa" :feedback="errors.company" :validation-status="errors.company ? 'error' : undefined">
                            <NInput v-model:value="form.company" placeholder="Nombre de tu empresa" />
                        </NFormItem>
                        <NFormItem label="Tu nombre" :feedback="errors.name" :validation-status="errors.name ? 'error' : undefined">
                            <NInput v-model:value="form.name" :input-props="{ autocomplete: 'name' }" />
                        </NFormItem>
                        <NFormItem label="Email" :feedback="errors.email" :validation-status="errors.email ? 'error' : undefined">
                            <NInput v-model:value="form.email" placeholder="tu@empresa.com" :input-props="{ autocomplete: 'email', type: 'email' }" />
                        </NFormItem>
                        <NFormItem label="Contraseña" :feedback="errors.password ?? 'Al menos 10 caracteres.'" :validation-status="errors.password ? 'error' : undefined">
                            <NInput v-model:value="form.password" type="password" show-password-on="click" :input-props="{ autocomplete: 'new-password' }" />
                        </NFormItem>
                        <NFormItem label="Repite la contraseña">
                            <NInput v-model:value="form.password_confirmation" type="password" :input-props="{ autocomplete: 'new-password' }" />
                        </NFormItem>
                        <!-- Trampa para robots: oculta para las personas. -->
                        <input v-model="form.website" class="signup__trap" tabindex="-1" autocomplete="off" aria-hidden="true" name="website" />
                        <NFormItem :feedback="errors.accept_terms" :validation-status="errors.accept_terms ? 'error' : undefined" :show-label="false">
                            <NCheckbox v-model:checked="form.accept_terms">Acepto las condiciones del servicio y la política de privacidad</NCheckbox>
                        </NFormItem>
                        <NButton type="primary" attr-type="submit" block :loading="sending" :disabled="!form.company || !form.email || !form.password || !form.accept_terms" data-test="signup-submit">
                            Crear cuenta
                        </NButton>
                    </NForm>

                    <p class="signup__footer">¿Ya tienes cuenta? <RouterLink :to="{ name: 'login' }">Inicia sesión</RouterLink></p>
                </template>
            </NSpin>
        </NCard>
    </div>
</template>

<style scoped>
.signup {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: #f5f6f8;
}

.signup__card {
    width: 100%;
    max-width: 440px;
}

.signup__subtitle {
    margin: 0 0 16px;
    color: #666;
}

.signup__error {
    margin-bottom: 16px;
}

.signup__footer {
    margin: 16px 0 0;
    text-align: center;
}

.signup__trap {
    position: absolute;
    left: -10000px;
    width: 1px;
    height: 1px;
    opacity: 0;
}
</style>
