import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from '@/App.vue';
import { createPanelRouter } from '@/router';

createApp(App)
    .use(createPinia())
    .use(createPanelRouter())
    .mount('#app');
