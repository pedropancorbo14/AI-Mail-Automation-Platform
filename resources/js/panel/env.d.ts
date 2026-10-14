/// <reference types="vite/client" />

declare module '*.vue' {
    import type { DefineComponent } from 'vue';

    const component: DefineComponent<object, object, unknown>;
    export default component;
}

import 'vue-router';

declare module 'vue-router' {
    interface RouteMeta {
        /** Título de la pestaña del navegador. */
        title?: string;
        /** Accesible sin sesión (login). */
        public?: boolean;
        /** Permiso necesario (el mismo que exige la API). */
        permission?: string;
        /** Sección del menú que se marca (p. ej. el detalle marca su listado). */
        menu?: string;
    }
}
