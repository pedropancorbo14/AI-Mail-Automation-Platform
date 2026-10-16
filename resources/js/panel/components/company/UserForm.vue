<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { NAlert, NButton, NForm, NFormItem, NInput, NSelect, NSpace } from 'naive-ui';
import { ApiError } from '@/api/http';
import { assignableRoles, createUser, updateUser, type CompanyUser, type UserInput } from '@/api/company';
import { roleLabels } from '@/menu';
import { useAuthStore } from '@/stores/auth';

const props = defineProps<{ user?: CompanyUser | null }>();
const emit = defineEmits<{ saved: [user: CompanyUser, created: boolean]; cancel: [] }>();

const auth = useAuthStore();
const editing = computed(() => !!props.user);
const isSelf = computed(() => props.user?.id === auth.user?.id);

const form = reactive<UserInput>({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    role: props.user?.role ?? 'agent',
    password: '',
});

const roles = computed(() => {
    const allowed = assignableRoles(auth.user?.role);
    // Si se edita a alguien con un rol que no se puede asignar (un propietario), se muestra igual.
    const list = props.user && !allowed.includes(props.user.role) ? [props.user.role, ...allowed] : allowed;

    return list.map((role) => ({ value: role, label: roleLabels[role] }));
});

const saving = ref(false);
const error = ref<ApiError | null>(null);
const fieldError = (field: string) => error.value?.fieldError(field);
const status = (field: string) => (fieldError(field) ? 'error' : undefined);

async function submit(): Promise<void> {
    saving.value = true;
    error.value = null;

    try {
        const saved = props.user ? await updateUser(props.user.id, form) : await createUser(form);
        emit('saved', saved, !props.user);
    } catch (e) {
        error.value = e instanceof ApiError ? e : new ApiError(0, 'No se pudo guardar el usuario.');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <NForm :model="form" label-placement="top" @submit.prevent="submit">
        <NAlert v-if="error && error.status !== 422" type="error" class="user-form__error">{{ error.message }}</NAlert>

        <NFormItem label="Nombre" :feedback="fieldError('name')" :validation-status="status('name')">
            <NInput v-model:value="form.name" />
        </NFormItem>
        <NFormItem label="Email (para entrar en el panel)" :feedback="fieldError('email')" :validation-status="status('email')">
            <NInput v-model:value="form.email" :input-props="{ type: 'email', autocomplete: 'off' }" />
        </NFormItem>
        <NFormItem label="Rol" :feedback="isSelf ? 'No puedes cambiar tu propio rol.' : fieldError('role')" :validation-status="status('role')">
            <NSelect v-model:value="form.role" :options="roles" :disabled="isSelf" data-test="role" />
        </NFormItem>
        <NFormItem
            :label="editing ? 'Nueva contraseña (vacía = no cambiarla)' : 'Contraseña inicial (mínimo 10 caracteres)'"
            :feedback="fieldError('password')"
            :validation-status="status('password')"
        >
            <NInput v-model:value="form.password" type="password" show-password-on="click" :input-props="{ autocomplete: 'new-password' }" />
        </NFormItem>

        <NSpace justify="end">
            <NButton @click="emit('cancel')">Cancelar</NButton>
            <NButton type="primary" attr-type="submit" :loading="saving">{{ editing ? 'Guardar cambios' : 'Crear usuario' }}</NButton>
        </NSpace>
    </NForm>
</template>

<style scoped>
.user-form__error {
    margin-bottom: 12px;
}
</style>
