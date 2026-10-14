import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPanelRouter, installAuthGuard, safeRedirect } from '@/router';

const user = {
    id: 1,
    name: 'Ana',
    email: 'ana@acme.test',
    role: 'admin',
    permissions: ['users.view'],
    tenant: { id: 1, name: 'Acme', slug: 'acme' },
};

/** Simula /auth/me: con sesión (200) o sin ella (401). */
function session(loggedIn: boolean): void {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
        new Response(JSON.stringify(loggedIn ? { data: user } : { message: 'Unauthenticated.' }), { status: loggedIn ? 200 : 401 }),
    ));
}

describe('router del panel', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('manda al login sin sesión, recordando a dónde iba', async () => {
        session(false);
        const router = createPanelRouter(createMemoryHistory());

        await router.push('/emails/12');

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query.redirect).toBe('/emails/12');
        expect(document.title).toBe('Iniciar sesión · AI Mail');
    });

    it('deja entrar con sesión', async () => {
        session(true);
        const router = createPanelRouter(createMemoryHistory());

        await router.push('/');

        expect(router.currentRoute.value.name).toBe('home');
    });

    it('saca del login a quien ya tiene sesión', async () => {
        session(true);
        const router = createPanelRouter(createMemoryHistory());

        await router.push('/login?redirect=/emails');

        expect(router.currentRoute.value.fullPath).toBe('/emails');
    });

    it('manda las rutas desconocidas a la página 404', async () => {
        session(true);
        const router = createPanelRouter(createMemoryHistory());

        await router.push('/no/existe');

        expect(router.currentRoute.value.name).toBe('not-found');
    });

    it('manda a la página 403 las secciones que el rol no permite', async () => {
        session(true);
        const page = { template: '<div />' };
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [
                { path: '/', name: 'home', component: page },
                { path: '/login', name: 'login', component: page, meta: { public: true } },
                { path: '/sin-permiso', name: 'forbidden', component: page },
                { path: '/usuarios', name: 'users', component: page, meta: { permission: 'users.view' } },
                { path: '/ia', name: 'ai', component: page, meta: { permission: 'automation.manage' } },
            ],
        });
        installAuthGuard(router);

        await router.push('/usuarios');
        expect(router.currentRoute.value.name).toBe('users');

        await router.push('/ia');
        expect(router.currentRoute.value.name).toBe('forbidden');
    });

    it('solo redirige a rutas internas del panel tras el login', () => {
        expect(safeRedirect('/emails?status=sent')).toBe('/emails?status=sent');
        expect(safeRedirect('https://malo.test')).toBe('/');
        expect(safeRedirect('//malo.test')).toBe('/');
        expect(safeRedirect('/\\malo.test')).toBe('/');
        expect(safeRedirect(undefined)).toBe('/');
        expect(safeRedirect(['/a'])).toBe('/');
    });
});
