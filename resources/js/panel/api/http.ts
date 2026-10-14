/**
 * Cliente HTTP del panel para la API /api/v1. Autenticación por cookie de
 * sesión de Sanctum (mismo origen): nunca se guarda un token en el
 * navegador. Las peticiones que modifican algo llevan la cabecera
 * X-XSRF-TOKEN (protección CSRF de Laravel).
 */

export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: FieldErrors = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** Primer error de validación (422) de un campo. */
    fieldError(field: string): string | undefined {
        return this.errors[field]?.[0];
    }
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
export type QueryParams = Record<string, string | number | boolean | null | undefined>;

let csrfReady = false;
let onUnauthenticated: (() => void) | null = null;

/** Qué hacer cuando la API responde 401 (sesión caducada): lo decide la app. */
export function setUnauthenticatedHandler(handler: (() => void) | null): void {
    onUnauthenticated = handler;
}

/** Solo para tests. */
export function resetCsrf(): void {
    csrfReady = false;
}

async function ensureCsrfCookie(): Promise<void> {
    if (!csrfReady) {
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        csrfReady = true;
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

function url(path: string, params?: QueryParams): string {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params ?? {})) {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, String(value));
        }
    }

    const qs = query.toString();

    return `/api/v1${path}${qs ? `?${qs}` : ''}`;
}

const defaultMessages: Record<number, string> = {
    401: 'Tu sesión ha caducado. Vuelve a iniciar sesión.',
    403: 'No tienes permiso para hacer esto.',
    404: 'No se ha encontrado lo que buscas.',
    429: 'Demasiadas peticiones. Espera un momento.',
};

export async function request<T>(method: Method, path: string, options: { body?: unknown; params?: QueryParams } = {}, retried = false): Promise<T> {
    if (method !== 'GET') {
        await ensureCsrfCookie();
    }

    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    let body: BodyInit | undefined;

    if (options.body instanceof FormData) {
        body = options.body;
    } else if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(options.body);
    }

    let response: Response;

    try {
        response = await fetch(url(path, options.params), { method, headers, body, credentials: 'same-origin' });
    } catch {
        throw new ApiError(0, 'No se pudo conectar con el servidor. Comprueba tu conexión.');
    }

    // 419 = token CSRF caducado: se pide uno nuevo y se reintenta una vez.
    if (response.status === 419 && !retried) {
        csrfReady = false;

        return request<T>(method, path, options, true);
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        if (response.status === 401) {
            onUnauthenticated?.();
        }

        throw new ApiError(
            response.status,
            data.message ?? defaultMessages[response.status] ?? 'Ha ocurrido un error. Inténtalo de nuevo.',
            data.errors ?? {},
        );
    }

    return data as T;
}

export const api = {
    get: <T>(path: string, params?: QueryParams) => request<T>('GET', path, { params }),
    post: <T>(path: string, body?: unknown) => request<T>('POST', path, { body }),
    patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, { body }),
    put: <T>(path: string, body?: unknown) => request<T>('PUT', path, { body }),
    delete: <T>(path: string) => request<T>('DELETE', path),
};
