import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { createPanelRouter } from '@/router';

describe('router del panel', () => {
    it('resuelve la página de inicio', () => {
        expect(createPanelRouter(createMemoryHistory()).resolve('/').name).toBe('home');
    });

    it('manda las rutas desconocidas a la página 404', () => {
        expect(createPanelRouter(createMemoryHistory()).resolve('/no/existe').name).toBe('not-found');
    });

    it('pone el título de la página', async () => {
        const router = createPanelRouter(createMemoryHistory());

        await router.push('/no/existe');

        expect(document.title).toBe('Página no encontrada · AI Mail');
    });
});
