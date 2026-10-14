<script setup lang="ts">
import { NCard, NResult } from 'naive-ui';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
</script>

<template>
    <NCard>
        <NResult status="info" :title="`Hola, ${auth.user?.name}`" :description="`Panel de ${auth.user?.tenant.name} (en construcción, Fase 10).`" />
    </NCard>
</template>
