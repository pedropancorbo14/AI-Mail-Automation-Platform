import { ref } from 'vue';
import { defineStore } from 'pinia';
import { api } from '@/api/http';
import type { ReviewQueueCounts } from '@/api/types';

/**
 * Cuántos casos esperan a una persona: el número del menú "Revisión". Se
 * refresca cada minuto mientras el panel está abierto y después de cada
 * decisión.
 */
export const useReviewQueueStore = defineStore('reviewQueue', () => {
    const counts = ref<ReviewQueueCounts | null>(null);
    let timer: ReturnType<typeof setInterval> | undefined;

    async function refresh(): Promise<void> {
        try {
            counts.value = (await api.get<{ meta: { counts: ReviewQueueCounts } }>('/review-queue', { per_page: 1 })).meta.counts;
        } catch {
            // El número del menú es una comodidad: si falla, se deja el anterior.
        }
    }

    function start(): void {
        stop();
        void refresh();
        timer = setInterval(() => void refresh(), 60_000);
    }

    function stop(): void {
        clearInterval(timer);
        timer = undefined;
    }

    return { counts, refresh, start, stop };
});
