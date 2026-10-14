import { createRouter, createWebHistory, type RouteRecordRaw, type RouterHistory } from 'vue-router';

export const routes: RouteRecordRaw[] = [
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

export function createPanelRouter(history: RouterHistory = createWebHistory()) {
    const router = createRouter({ history, routes });

    router.afterEach((to) => {
        const title = to.meta.title as string | undefined;
        document.title = title ? `${title} · AI Mail` : 'AI Mail';
    });

    return router;
}
