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
        // Todo lo que requiere sesión va dentro de la estructura del panel.
        path: '/',
        component: () => import('@/layouts/AppLayout.vue'),
        children: [
            {
                path: '',
                name: 'home',
                component: () => import('@/pages/DashboardPage.vue'),
                meta: { title: 'Resumen' },
            },
            {
                path: 'revision',
                name: 'review',
                component: () => import('@/pages/ReviewQueuePage.vue'),
                meta: { title: 'Bandeja de revisión', permission: 'emails.view' },
            },
            {
                path: 'emails',
                name: 'emails',
                component: () => import('@/pages/EmailsPage.vue'),
                meta: { title: 'Emails', permission: 'emails.view' },
            },
            {
                path: 'emails/:id(\\d+)',
                name: 'email',
                component: () => import('@/pages/EmailPage.vue'),
                meta: { title: 'Email', permission: 'emails.view', menu: 'emails' },
            },
            {
                path: 'buzones',
                name: 'mail-connections',
                component: () => import('@/pages/MailConnectionsPage.vue'),
                meta: { title: 'Buzones de correo', permission: 'mail_connections.view' },
            },
            {
                path: 'documentacion',
                name: 'documents',
                component: () => import('@/pages/DocumentsPage.vue'),
                meta: { title: 'Documentación', permission: 'documents.view' },
            },
            {
                path: 'configuracion/ia',
                name: 'ai-settings',
                component: () => import('@/pages/AiSettingsPage.vue'),
                meta: { title: 'Configuración de la IA', permission: 'tenant.view' },
            },
            {
                path: 'empresa',
                name: 'company',
                component: () => import('@/pages/CompanyPage.vue'),
                meta: { title: 'Empresa y usuarios', permission: 'tenant.view' },
            },
            {
                path: 'cuenta',
                name: 'account',
                component: () => import('@/pages/AccountPage.vue'),
                meta: { title: 'Mi cuenta' },
            },
            {
                path: 'auditoria',
                name: 'audit',
                component: () => import('@/pages/AuditPage.vue'),
                meta: { title: 'Auditoría', permission: 'audit.view' },
            },
            {
                path: 'sin-permiso',
                name: 'forbidden',
                component: () => import('@/pages/ForbiddenPage.vue'),
                meta: { title: 'Sin permiso' },
            },
            {
                path: ':pathMatch(.*)*',
                name: 'not-found',
                component: () => import('@/pages/NotFoundPage.vue'),
                meta: { title: 'Página no encontrada' },
            },
        ],
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

/** Sin sesión, todo (salvo las rutas públicas) lleva al login; sin permiso, a la página 403. */
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

        // Solo para la interfaz: la API vuelve a comprobar el permiso (403).
        if (to.meta.permission && !auth.can(to.meta.permission)) {
            return { name: 'forbidden' };
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
