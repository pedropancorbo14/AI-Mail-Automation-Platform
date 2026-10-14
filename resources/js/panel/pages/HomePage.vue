<script setup lang="ts">
import { useRouter } from 'vue-router';
import { NButton, NCard, NResult } from 'naive-ui';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

async function logout(): Promise<void> {
    await auth.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <NCard>
        <NResult status="info" :title="`Hola, ${auth.user?.name}`" :description="`Panel de ${auth.user?.tenant.name} (en construcción, Fase 10).`">
            <template #footer>
                <NButton @click="logout">Cerrar sesión</NButton>
            </template>
        </NResult>
    </NCard>
</template>
