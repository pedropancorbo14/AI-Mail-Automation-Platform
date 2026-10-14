<script setup lang="ts">
import { NButton, NResult } from 'naive-ui';
</script>

<template>
    <NResult status="403" title="Sin permiso" description="Tu rol no permite ver esta sección. Si la necesitas, pídeselo a un administrador de tu empresa.">
        <template #footer>
            <RouterLink :to="{ name: 'home' }">
                <NButton>Volver al inicio</NButton>
            </RouterLink>
        </template>
    </NResult>
</template>
