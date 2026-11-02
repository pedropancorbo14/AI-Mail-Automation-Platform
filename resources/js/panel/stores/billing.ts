import { ref } from 'vue';
import { defineStore } from 'pinia';
import { getBilling, type Billing } from '@/api/billing';

/**
 * Plan y uso de la empresa para el aviso de la cabecera (prueba terminada,
 * pago fallido, límite casi agotado). Se pide al entrar en el panel y al
 * abrir "Plan y uso".
 */
export const useBillingStore = defineStore('billing', () => {
    const billing = ref<Billing | null>(null);

    async function refresh(): Promise<void> {
        try {
            billing.value = await getBilling();
        } catch {
            // El aviso es una comodidad: si falla, no se muestra.
        }
    }

    return { billing, refresh };
});
