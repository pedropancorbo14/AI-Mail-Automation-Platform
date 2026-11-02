import { describe, expect, it } from 'vitest';
import { formatLimit, formatPrice, limitsNearMax } from '@/api/billing';
import { billingFixture } from '@/api/billing.fixture';

describe('plan y uso', () => {
    it('lista los límites casi agotados, del más usado al menos, con la capacidad de IA', () => {
        expect(limitsNearMax(billingFixture())).toEqual([
            { label: 'Buzones', ratio: 1 },
            { label: 'Capacidad de IA del mes', ratio: 0.85 },
        ]);
        expect(limitsNearMax(billingFixture({ ai_capacity_ratio: null, limits: billingFixture().usage.limits }))).toHaveLength(1);
    });

    it('escribe los límites y los precios', () => {
        expect(formatLimit({ used: 1234, limit: 5000, ratio: 0.2 })).toBe('1234 de 5000'.replace('1234', (1234).toLocaleString('es-ES')).replace('5000', (5000).toLocaleString('es-ES')));
        expect(formatLimit({ used: 7, limit: null, ratio: null })).toBe('7 (sin límite)');
        expect(formatPrice(billingFixture().plans[1])).toBe('49 € / mes');
        expect(formatPrice(billingFixture().plans[0])).toBe('Gratis');
        expect(formatPrice(billingFixture().plans[2])).toBe('A medida');
    });
});
