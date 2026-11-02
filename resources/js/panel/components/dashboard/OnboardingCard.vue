<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { NButton, NCard, NIcon, NProgress, NText } from 'naive-ui';
import { CheckmarkCircle, EllipseOutline } from '@vicons/ionicons5';
import { dismissOnboarding, getOnboarding, type Onboarding } from '@/api/onboarding';
import { useAuthStore } from '@/stores/auth';

/**
 * Primeros pasos de una empresa nueva (Fase 17). Se ve hasta que están todos
 * hechos o alguien que configura la plataforma la oculta.
 */
const auth = useAuthStore();
const onboarding = ref<Onboarding | null>(null);
const canDismiss = computed(() => auth.can('automation.manage'));

onMounted(async () => {
    try {
        onboarding.value = await getOnboarding();
    } catch {
        onboarding.value = null;
    }
});

const visible = computed(() => onboarding.value !== null
    && !onboarding.value.dismissed
    && onboarding.value.completed < onboarding.value.steps.length);

async function dismiss(): Promise<void> {
    onboarding.value = await dismissOnboarding();
}
</script>

<template>
    <NCard v-if="visible && onboarding" title="Primeros pasos" size="small" data-test="onboarding">
        <template #header-extra>
            <NButton v-if="canDismiss" text size="small" data-test="onboarding-dismiss" @click="dismiss">Ocultar</NButton>
        </template>
        <NProgress
            type="line"
            :percentage="Math.round((onboarding.completed / onboarding.steps.length) * 100)"
            :show-indicator="false"
            class="onboarding__progress"
        />
        <NText depth="3">{{ onboarding.completed }} de {{ onboarding.steps.length }} hechos</NText>
        <ol class="onboarding__steps">
            <li v-for="step in onboarding.steps" :key="step.key" :class="{ 'onboarding__step--done': step.done }" :data-test="`step-${step.key}`">
                <NIcon :size="18" :color="step.done ? '#18a058' : undefined" class="onboarding__icon">
                    <CheckmarkCircle v-if="step.done" />
                    <EllipseOutline v-else />
                </NIcon>
                <div>
                    <RouterLink v-if="!step.done" :to="{ name: step.route }"><strong>{{ step.label }}</strong></RouterLink>
                    <strong v-else>{{ step.label }}</strong>
                    <NText depth="3" class="onboarding__description">{{ step.description }}</NText>
                </div>
            </li>
        </ol>
    </NCard>
</template>

<style scoped>
.onboarding__progress {
    margin-bottom: 4px;
    max-width: 320px;
}

.onboarding__steps {
    list-style: none;
    margin: 12px 0 0;
    padding: 0;
    display: grid;
    gap: 10px;
}

.onboarding__steps li {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.onboarding__icon {
    margin-top: 2px;
    flex-shrink: 0;
}

.onboarding__step--done strong {
    color: #888;
    text-decoration: line-through;
}

.onboarding__description {
    display: block;
}
</style>
