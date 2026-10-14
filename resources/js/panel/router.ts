import { createRouter, createWebHistory, type Router, type RouteRecordRaw, type RouterHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

export const routes: RouteRecordRaw[] = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/LoginPage.vue'),
        meta: { title: 'Iniciar sesión', public: true },
    },
    {
        path: '/',
        name: 'home',
        component: () => import('@/pages/HomePage.vue'),
        meta: { title: 'Inicio' },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFoundPage.vue'),
        meta: { title: 'Página no encontrada' },
    },
];

/**
 * Solo rutas internas del panel tras el login: un enlace con
 * ?redirect=https://otro.sitio no puede sacar al usuario del panel.
 */
export function safeRedirect(value: unknown): string {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.startsWith('/\\')
        ? value
        : '/';
}

/** Sin sesión, todo (salvo las rutas públicas) lleva al login. */
export function installAuthGuard(router: Router): void {
    router.beforeEach(async (to) => {
        const auth = useAuthStore();

        if (!auth.loaded) {
            await auth.load().catch(() => undefined);
        }

        if (to.meta.public) {
            return auth.isAuthenticated && to.name === 'login' ? safeRedirect(to.query.redirect) : true;
        }

        if (!auth.isAuthenticated) {
            return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } };
        }

        return true;
    });
}

export function createPanelRouter(history: RouterHistory = createWebHistory()): Router {
    const router = createRouter({ history, routes });

    installAuthGuard(router);

    router.afterEach((to) => {
        document.title = to.meta.title ? `${to.meta.title} · AI Mail` : 'AI Mail';
    });

    return router;
}
