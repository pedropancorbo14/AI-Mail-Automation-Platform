<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { NCard, NSpace, NText } from 'naive-ui';
import StandardApiCard from '@/components/integrations/StandardApiCard.vue';
import WooCommerceCard from '@/components/integrations/WooCommerceCard.vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const canSeeOrders = computed(() => auth.can('orders.view'));
</script>

<template>
    <NSpace vertical :size="16">
        <NCard title="Integraciones">
            <NText depth="3" class="integrations__intro">
                Aquí conectas los sistemas de tu empresa para que la IA pueda consultar datos reales al responder: pedidos, facturas o citas.
                La IA solo lee (nunca cambia nada en tus sistemas) y siempre solo los datos del cliente que escribe.
                Sin ninguna integración la plataforma funciona igual: la IA responde con tu documentación y lo que dependa de datos lo revisa una persona.
            </NText>
            <ul class="integrations__list">
                <li><strong>Tu propio sistema</strong> (ERP, agenda, programa de gestión): pedidos, facturas y citas mediante la API estándar de la plataforma.</li>
                <li><strong>Tienda WooCommerce</strong>: pedidos de tu tienda online.</li>
                <li>
                    <strong>Pedidos por CSV</strong>: si no tienes nada de lo anterior,
                    <RouterLink v-if="canSeeOrders" :to="{ name: 'orders' }">sube tus pedidos en un fichero</RouterLink>
                    <template v-else>sube tus pedidos en un fichero (sección Pedidos)</template>.
                </li>
            </ul>
            <NText depth="3" class="integrations__intro">
                Si hay varias fuentes de pedidos, la IA usa una sola: primero tu propio sistema, después WooCommerce y por último los pedidos del CSV.
            </NText>
        </NCard>

        <StandardApiCard />
        <WooCommerceCard />
    </NSpace>
</template>

<style scoped>
.integrations__intro {
    display: block;
    margin-bottom: 8px;
}

.integrations__list {
    margin: 8px 0 12px;
    padding-left: 20px;
    line-height: 1.6;
}
</style>
