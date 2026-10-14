<script setup lang="ts">
import { NConfigProvider, NDialogProvider, NLoadingBarProvider, NMessageProvider, esES, dateEsES } from 'naive-ui';
</script>

<template>
    <NConfigProvider :locale="esES" :date-locale="dateEsES">
        <NLoadingBarProvider>
            <NMessageProvider>
                <NDialogProvider>
                    <RouterView />
                </NDialogProvider>
            </NMessageProvider>
        </NLoadingBarProvider>
    </NConfigProvider>
</template>
