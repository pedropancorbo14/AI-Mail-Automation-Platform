import { describe, expect, it } from 'vitest';
import { intentBars } from '@/api/dashboard';

describe('ranking de intenciones del dashboard', () => {
    it('mide cada barra respecto a la mayor y calcula su parte del total', () => {
        expect(intentBars([
            { intent: 'order_status', count: 8 },
            { intent: 'complaint', count: 2 },
        ])).toEqual([
            { intent: 'order_status', count: 8, width: 100, share: 0.8 },
            { intent: 'complaint', count: 2, width: 25, share: 0.2 },
        ]);
    });

    it('no falla sin datos', () => {
        expect(intentBars([])).toEqual([]);
    });
});
