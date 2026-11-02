import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { START_LOCATION } from 'vue-router';
import App from '@/App.vue';
import { setUnauthenticatedHandler } from '@/api/http';
import { createPanelRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';

const app = createApp(App).use(createPinia());
const router = createPanelRouter();

// Sesión caducada mientras se usa el panel: al login, volviendo después a donde estaba.
setUnauthenticatedHandler(() => {
    useAuthStore().forget();

    const current = router.currentRoute.value;

    // Al abrir el panel, la primera comprobación de sesión (/auth/me) da 401
    // antes de que termine la primera navegación: de eso se ocupa el guard
    // del router. Si no, abrir /registro o /login?verified=1 acababa en
    // /login?redirect=/ y se perdía la página.
    if (current === START_LOCATION) {
        return;
    }

    if (!current.meta.public) {
        void router.push({ name: 'login', query: { redirect: current.fullPath } });
    }
});

app.use(router).mount('#app');
