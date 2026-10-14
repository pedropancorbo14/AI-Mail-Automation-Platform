import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { api, ApiError } from '@/api/http';

export interface CurrentUser {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'admin' | 'agent';
    permissions: string[];
    tenant: { id: number; name: string; slug: string };
}

/**
 * Usuario de la sesión. Los permisos solo sirven para mostrar u ocultar
 * cosas en el panel: la autorización real la hace siempre la API.
 */
export const useAuthStore = defineStore('auth', () => {
    const user = ref<CurrentUser | null>(null);
    const loaded = ref(false);

    const isAuthenticated = computed(() => user.value !== null);

    async function load(): Promise<void> {
        try {
            user.value = (await api.get<{ data: CurrentUser }>('/auth/me')).data;
        } catch (error) {
            user.value = null;

            if (!(error instanceof ApiError) || error.status !== 401) {
                throw error;
            }
        } finally {
            loaded.value = true;
        }
    }

    async function login(email: string, password: string): Promise<void> {
        user.value = (await api.post<{ data: CurrentUser }>('/auth/session', { email, password })).data;
        loaded.value = true;
    }

    async function logout(): Promise<void> {
        try {
            await api.post('/auth/logout');
        } finally {
            user.value = null;
        }
    }

    /** La sesión caducó (la API respondió 401). */
    function forget(): void {
        user.value = null;
    }

    function can(permission: string): boolean {
        return user.value?.permissions.includes(permission) ?? false;
    }

    return { user, loaded, isAuthenticated, load, login, logout, forget, can };
});
