<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { NAlert, NButton, NCard, NGrid, NGi, NProgress, NSpace, NSpin, NTag, NText, useMessage } from 'naive-ui';
import { formatLimit, formatPrice, NEAR_LIMIT, openPortal, startCheckout, type LimitKey, type LimitUsage } from '@/api/billing';
import { ApiError } from '@/api/http';
import { useAuthStore } from '@/stores/auth';
import { useBillingStore } from '@/stores/billing';
import { formatDateTime, formatPercent } from '@/utils/format';

const store = useBillingStore();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const message = useMessage();
const loading = ref(true);
const error = ref<string | null>(null);
const redirecting = ref<string | null>(null);
const canManage = computed(() => auth.can('billing.manage'));

/** Va a la página de Stripe (pago o portal); la tarjeta nunca pasa por el panel. */
async function goTo(action: string, getUrl: () => Promise<string>): Promise<void> {
    redirecting.value = action;
    try {
        window.location.assign(await getUrl());
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo abrir el sistema de pagos.');
        redirecting.value = null;
    }
}

onMounted(async () => {
    // Vuelta desde Stripe: el plan se activa cuando llega su aviso (segundos).
    if (route.query.checkout === 'success') {
        message.success('Pago recibido. Tu plan se activará en unos segundos.');
        void router.replace({ query: {} });
        setTimeout(() => void store.refresh(), 5000);
    } else if (route.query.checkout === 'cancel') {
        message.info('No se ha hecho ningún cargo.');
        void router.replace({ query: {} });
    }

    try {
        await store.refresh();
        if (!store.billing) {
            error.value = 'No se pudo cargar el plan.';
        }
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar el plan.';
    } finally {
        loading.value = false;
    }
});

const billing = computed(() => store.billing);

const statusType = computed(() => {
    switch (billing.value?.subscription.status) {
        case 'active': return 'success';
        case 'trialing': return 'info';
        case 'past_due': return 'warning';
        default: return 'error';
    }
});

type BarStatus = 'error' | 'warning' | 'default';

function barStatus(ratio: number | null): BarStatus {
    return ratio !== null && ratio >= 1 ? 'error' : ratio !== null && ratio >= NEAR_LIMIT ? 'warning' : 'default';
}

const limitRows = computed(() => {
    if (!billing.value) {
        return [];
    }

    return (Object.entries(billing.value.usage.limits) as [LimitKey, LimitUsage][]).map(([key, usage]) => ({
        key,
        label: billing.value!.limit_labels[key],
        text: formatLimit(usage),
        percent: usage.ratio === null ? null : Math.min(100, Math.round(usage.ratio * 100)),
        status: barStatus(usage.ratio),
    }));
});

const capacity = computed(() => {
    const ratio = billing.value?.usage.ai_capacity_ratio ?? null;

    return ratio === null ? null : {
        percent: Math.min(100, Math.round(ratio * 100)),
        status: barStatus(ratio),
    };
});

function monthName(period: string): string {
    const [year, month] = period.split('-').map(Number);

    return new Intl.DateTimeFormat('es-ES', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
}
</script>

<template>
    <NSpin :show="loading">
        <NAlert v-if="error" type="error">{{ error }}</NAlert>

        <NSpace v-else-if="billing" vertical :size="16">
            <NCard title="Tu plan">
                <NSpace align="center" :size="12">
                    <NText strong class="plan__name" data-test="plan-name">{{ billing.plan.name }}</NText>
                    <NTag :type="statusType" size="small" data-test="plan-status">{{ billing.subscription.status_label }}</NTag>
                    <NText depth="3">{{ formatPrice(billing.plan) }}</NText>
                </NSpace>
                <NText depth="3" class="plan__line">{{ billing.plan.description }}</NText>
                <NText v-if="billing.subscription.status === 'trialing' && billing.subscription.trial_ends_at" class="plan__line">
                    La prueba termina el {{ formatDateTime(billing.subscription.trial_ends_at) }}.
                </NText>
                <NText v-if="billing.subscription.current_period_ends_at && billing.subscription.status !== 'trialing'" class="plan__line">
                    Periodo actual hasta el {{ formatDateTime(billing.subscription.current_period_ends_at) }}.
                </NText>
                <NAlert v-if="billing.subscription.ai_block_reason" type="warning" class="plan__alert" data-test="ai-blocked">
                    {{ billing.subscription.ai_block_reason }} Mientras tanto los emails se siguen recibiendo y los atiende tu equipo.
                </NAlert>
                <NButton
                    v-if="canManage && billing.billing.has_subscription"
                    class="plan__alert"
                    :loading="redirecting === 'portal'"
                    data-test="portal"
                    @click="goTo('portal', openPortal)"
                >
                    Gestionar suscripción (plan, tarjeta y facturas)
                </NButton>
                <NText v-else-if="!canManage" depth="3" class="plan__line">Solo el propietario de la empresa puede cambiar el plan.</NText>
            </NCard>

            <NCard :title="`Uso de ${monthName(billing.usage.period)}`">
                <NText depth="3" class="plan__line">
                    {{ billing.usage.emails_received.toLocaleString('es-ES') }} emails recibidos este mes.
                    Si se alcanza un límite de IA, los emails se siguen guardando y los atiende una persona.
                </NText>
                <div class="plan__usage">
                    <div v-for="row in limitRows" :key="row.key" class="plan__usage-row" :data-test="`usage-${row.key}`">
                        <div class="plan__usage-head">
                            <NText>{{ row.label }}</NText>
                            <NText depth="3">{{ row.text }}</NText>
                        </div>
                        <NProgress v-if="row.percent !== null" type="line" :percentage="row.percent" :status="row.status" :show-indicator="false" />
                    </div>
                    <div v-if="capacity" class="plan__usage-row" data-test="usage-ai-capacity">
                        <div class="plan__usage-head">
                            <NText>Capacidad de IA del mes</NText>
                            <NText depth="3">{{ formatPercent(capacity.percent / 100) }} usada</NText>
                        </div>
                        <NProgress type="line" :percentage="capacity.percent" :status="capacity.status" :show-indicator="false" />
                    </div>
                </div>
            </NCard>

            <NCard title="Planes">
                <NGrid :cols="'1 s:2 l:4'" responsive="screen" :x-gap="12" :y-gap="12">
                    <NGi v-for="plan in billing.plans" :key="plan.key">
                        <NCard size="small" :class="{ 'plan__option--current': plan.current }" :data-test="`plan-${plan.key}`">
                            <NSpace vertical :size="4">
                                <NSpace align="center" :size="8">
                                    <NText strong>{{ plan.name }}</NText>
                                    <NTag v-if="plan.current" size="small" type="success">Tu plan</NTag>
                                </NSpace>
                                <NText>{{ formatPrice(plan) }}</NText>
                                <NText depth="3">{{ plan.description }}</NText>
                                <ul class="plan__limits">
                                    <li v-for="(value, key) in plan.limits" :key="key">
                                        {{ billing.limit_labels[key] }}: {{ value === null ? 'sin límite' : value.toLocaleString('es-ES') }}
                                    </li>
                                </ul>
                                <NButton
                                    v-if="canManage && plan.purchasable && !plan.current && !billing.billing.has_subscription"
                                    type="primary"
                                    size="small"
                                    :loading="redirecting === plan.key"
                                    :data-test="`buy-${plan.key}`"
                                    @click="goTo(plan.key, () => startCheckout(plan.key))"
                                >
                                    Contratar
                                </NButton>
                                <NText v-else-if="plan.key === 'enterprise' && !plan.current" depth="3">Escríbenos y lo preparamos.</NText>
                            </NSpace>
                        </NCard>
                    </NGi>
                </NGrid>
            </NCard>
        </NSpace>
    </NSpin>
</template>

<style scoped>
.plan__name {
    font-size: 18px;
}

.plan__line {
    display: block;
    margin-top: 8px;
}

.plan__alert {
    margin-top: 12px;
}

.plan__usage {
    display: grid;
    gap: 14px;
    margin-top: 16px;
    max-width: 640px;
}

.plan__usage-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 4px;
}

.plan__limits {
    margin: 4px 0 0;
    padding-left: 18px;
    line-height: 1.6;
}

.plan__option--current {
    border-color: #18a058;
}
</style>
