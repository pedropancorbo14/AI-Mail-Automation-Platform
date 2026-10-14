<script setup lang="ts">
import { computed, h, onMounted, ref } from 'vue';
import {
    NAlert,
    NButton,
    NCard,
    NDataTable,
    NDescriptions,
    NDescriptionsItem,
    NDrawer,
    NDrawerContent,
    NInput,
    NInputGroup,
    NPopconfirm,
    NSpace,
    NTag,
    useMessage,
    type DataTableColumns,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import { deleteUser, getCompany, listUsers, renameCompany, type Company, type CompanyUser } from '@/api/company';
import UserForm from '@/components/company/UserForm.vue';
import { roleLabels } from '@/menu';
import { formatDateTime } from '@/utils/format';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const message = useMessage();

const company = ref<Company | null>(null);
const name = ref('');
const users = ref<CompanyUser[]>([]);
const error = ref<string | null>(null);
const savingName = ref(false);
const drawer = ref<{ open: boolean; user: CompanyUser | null }>({ open: false, user: null });

// Solo un propietario edita o borra a otro propietario (la API lo vuelve a comprobar).
const canTouch = (user: CompanyUser) => auth.user?.role === 'owner' || user.role !== 'owner';

async function saveName(): Promise<void> {
    savingName.value = true;

    try {
        company.value = await renameCompany(name.value.trim());
        if (auth.user) {
            auth.user.tenant.name = company.value.name;
        }
        message.success('Nombre de la empresa guardado.');
    } catch (e) {
        message.error(e instanceof ApiError ? (e.fieldError('name') ?? e.message) : 'No se pudo guardar.');
    } finally {
        savingName.value = false;
    }
}

function onSaved(user: CompanyUser, created: boolean): void {
    const index = users.value.findIndex((u) => u.id === user.id);
    index === -1 ? users.value.push(user) : users.value.splice(index, 1, user);
    drawer.value.open = false;
    message.success(created ? 'Usuario creado: ya puede entrar en el panel.' : 'Usuario guardado.');
}

async function remove(user: CompanyUser): Promise<void> {
    try {
        await deleteUser(user.id);
        users.value = users.value.filter((u) => u.id !== user.id);
        message.success('Usuario eliminado.');
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo eliminar.');
    }
}

onMounted(async () => {
    try {
        company.value = await getCompany();
        name.value = company.value.name;

        if (auth.can('users.view')) {
            users.value = (await listUsers()).data;
        }
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar la empresa.';
    }
});

const columns = computed<DataTableColumns<CompanyUser>>(() => [
    { title: 'Nombre', key: 'name', render: (row) => (row.id === auth.user?.id ? `${row.name} (tú)` : row.name) },
    { title: 'Email', key: 'email' },
    { title: 'Rol', key: 'role', width: 150, render: (row) => h(NTag, { size: 'small', bordered: false }, () => roleLabels[row.role]) },
    { title: 'Alta', key: 'created_at', width: 140, render: (row) => formatDateTime(row.created_at) },
    {
        title: '',
        key: 'actions',
        width: 170,
        render: (row) => h(NSpace, { size: 8, justify: 'end', wrap: false }, () => [
            auth.can('users.update') && canTouch(row)
                ? h(NButton, { size: 'small', onClick: () => (drawer.value = { open: true, user: row }) }, () => 'Editar')
                : null,
            auth.can('users.delete') && canTouch(row) && row.id !== auth.user?.id
                ? h(NPopconfirm, { onPositiveClick: () => remove(row), positiveText: 'Eliminar', negativeText: 'Cancelar' }, {
                      trigger: () => h(NButton, { size: 'small', type: 'error', quaternary: true }, () => 'Eliminar'),
                      default: () => `¿Eliminar a ${row.name}? Ya no podrá entrar en el panel.`,
                  })
                : null,
        ]),
    },
]);
</script>

<template>
    <NSpace vertical :size="16">
        <NAlert v-if="error" type="error">{{ error }}</NAlert>

        <NCard v-if="company" title="Empresa">
            <NDescriptions :column="1" label-placement="left">
                <NDescriptionsItem label="Nombre">
                    <NInputGroup v-if="auth.can('tenant.update')" class="company__name">
                        <NInput v-model:value="name" maxlength="255" />
                        <NButton type="primary" :loading="savingName" :disabled="!name.trim() || name.trim() === company.name" @click="saveName">Guardar</NButton>
                    </NInputGroup>
                    <span v-else>{{ company.name }}</span>
                </NDescriptionsItem>
                <NDescriptionsItem label="Identificador">{{ company.slug }}</NDescriptionsItem>
                <NDescriptionsItem label="Alta">{{ formatDateTime(company.created_at) }}</NDescriptionsItem>
            </NDescriptions>
        </NCard>

        <NCard v-if="auth.can('users.view')" title="Usuarios">
            <template v-if="auth.can('users.create')" #header-extra>
                <NButton type="primary" @click="drawer = { open: true, user: null }">Añadir usuario</NButton>
            </template>
            <NDataTable :columns="columns" :data="users" :row-key="(row: CompanyUser) => row.id" />
        </NCard>

        <NDrawer v-model:show="drawer.open" :width="440" placement="right">
            <NDrawerContent :title="drawer.user ? `Editar a ${drawer.user.name}` : 'Añadir usuario'" closable>
                <UserForm :key="drawer.user?.id ?? 'new'" :user="drawer.user" @saved="onSaved" @cancel="drawer.open = false" />
            </NDrawerContent>
        </NDrawer>
    </NSpace>
</template>

<style scoped>
.company__name {
    max-width: 420px;
}
</style>
