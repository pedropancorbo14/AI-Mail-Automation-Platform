import { describe, expect, it } from 'vitest';
import { HomeOutline } from '@vicons/ionicons5';
import { menuEntries, visibleMenu, type MenuEntry } from '@/menu';

const entries: MenuEntry[] = [
    { route: 'home', label: 'Inicio', icon: HomeOutline },
    { route: 'users', label: 'Usuarios', icon: HomeOutline, permission: 'users.view' },
    { route: 'ai', label: 'IA', icon: HomeOutline, permission: 'automation.manage' },
];

describe('menú del panel', () => {
    it('muestra solo las secciones que permite el rol, en orden', () => {
        const can = (permission: string) => permission === 'users.view';

        expect(visibleMenu(entries, can).map((entry) => entry.route)).toEqual(['home', 'users']);
    });

    it('muestra siempre las secciones sin permiso', () => {
        expect(visibleMenu(entries, () => false).map((entry) => entry.route)).toEqual(['home']);
    });

    it('pone la bandeja de revisión justo después del resumen, con el número de casos', () => {
        const review = menuEntries[1];

        expect(review.route).toBe('review');
        expect(review.permission).toBe('emails.view');
        expect(review.showsReviewCount).toBe(true);
    });
});
