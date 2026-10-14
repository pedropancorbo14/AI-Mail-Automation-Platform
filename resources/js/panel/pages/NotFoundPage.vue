<script setup lang="ts">
import { NButton, NResult } from 'naive-ui';
</script>

<template>
    <NResult status="404" title="Página no encontrada" description="La dirección no existe o ya no está disponible.">
        <template #footer>
            <RouterLink :to="{ name: 'home' }">
                <NButton>Volver al inicio</NButton>
            </RouterLink>
        </template>
    </NResult>
</template>
