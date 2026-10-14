import { describe, expect, it } from 'vitest';
import { emptyFilters } from '@/api/emails';
import { filtersFromQuery, queryFromFilters } from '@/utils/query';

describe('filtros del listado en la URL', () => {
    it('lee los filtros válidos de la URL', () => {
        expect(filtersFromQuery({ status: 'human_review', intent: 'spam', mailbox: '3', q: 'pedido', from: '2026-10-01', to: '2026-10-05', page: '2' }))
            .toEqual({ status: 'human_review', intent: 'spam', mailbox: 3, q: 'pedido', from: '2026-10-01', to: '2026-10-05', page: 2 });
    });

    it('ignora lo que no es válido (la URL la puede editar cualquiera)', () => {
        expect(filtersFromQuery({ status: 'borrado', intent: '<script>', mailbox: '-1', from: 'ayer', to: '2026-13', page: 'x' }))
            .toEqual(emptyFilters());
    });

    it('usa el primer valor si un parámetro viene repetido', () => {
        expect(filtersFromQuery({ status: ['sent', 'failed'] }).status).toBe('sent');
    });

    it('escribe en la URL solo lo que filtra', () => {
        expect(queryFromFilters({ ...emptyFilters(), status: 'sent', q: 'pedido' })).toEqual({ status: 'sent', q: 'pedido' });
        expect(queryFromFilters({ ...emptyFilters(), page: 3 })).toEqual({ page: '3' });
        expect(queryFromFilters(emptyFilters())).toEqual({});
    });

    it('ida y vuelta sin perder nada', () => {
        const filters = { ...emptyFilters(), intent: 'order_status' as const, mailbox: 2, page: 4 };

        expect(filtersFromQuery(queryFromFilters(filters) as Record<string, string>)).toEqual(filters);
    });
});
