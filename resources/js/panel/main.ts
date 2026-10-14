import { createApp } from 'vue';
import { createPinia } from 'pinia';
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

    if (!current.meta.public) {
        void router.push({ name: 'login', query: { redirect: current.fullPath } });
    }
});

app.use(router).mount('#app');
