import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError, resetCsrf, setUnauthenticatedHandler } from '@/api/http';

function json(status: number, body: unknown = {}): Response {
    return new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/** El ApiError con el que falla una petición. */
async function failure(promise: Promise<unknown>): Promise<ApiError> {
    try {
        await promise;
    } catch (e) {
        if (e instanceof ApiError) {
            return e;
        }
    }

    throw new Error('Se esperaba un ApiError');
}

describe('cliente HTTP del panel', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        resetCsrf();
        setUnauthenticatedHandler(null);
        document.cookie = 'XSRF-TOKEN=abc%3D123; path=/';
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('lee de la API con la cookie de sesión y sin parámetros vacíos', async () => {
        fetchMock.mockResolvedValue(json(200, { data: [1] }));

        const result = await api.get<{ data: number[] }>('/emails', { status: 'sent', q: '', page: 2 });

        expect(result.data).toEqual([1]);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/v1/emails?status=sent&page=2');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers.Accept).toBe('application/json');
    });

    it('pide la cookie CSRF antes de modificar y envía el token', async () => {
        fetchMock.mockResolvedValueOnce(json(204)).mockResolvedValueOnce(json(200, { ok: true }));

        await api.post('/auth/session', { email: 'a@b.test' });

        expect(fetchMock.mock.calls[0][0]).toBe('/sanctum/csrf-cookie');
        const [, init] = fetchMock.mock.calls[1];
        expect(init.method).toBe('POST');
        expect(init.headers['X-XSRF-TOKEN']).toBe('abc=123');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(init.body).toBe('{"email":"a@b.test"}');
    });

    it('convierte los errores de validación en ApiError con sus campos', async () => {
        fetchMock.mockResolvedValueOnce(json(204)).mockResolvedValueOnce(
            json(422, { message: 'Datos no válidos.', errors: { email: ['Las credenciales no son correctas.'] } }),
        );

        const error = await failure(api.post('/auth/session', {}));

        expect(error.status).toBe(422);
        expect(error.fieldError('email')).toBe('Las credenciales no son correctas.');
    });

    it('avisa a la app cuando la sesión ha caducado', async () => {
        const handler = vi.fn();
        setUnauthenticatedHandler(handler);
        fetchMock.mockResolvedValue(json(401, {}));

        const error = await failure(api.get('/auth/me'));

        expect(handler).toHaveBeenCalledOnce();
        expect(error.message).toBe('Tu sesión ha caducado. Vuelve a iniciar sesión.');
    });

    it('renueva el token CSRF caducado y reintenta una vez', async () => {
        fetchMock
            .mockResolvedValueOnce(json(204))
            .mockResolvedValueOnce(json(419))
            .mockResolvedValueOnce(json(204))
            .mockResolvedValueOnce(json(200, { ok: true }));

        expect(await api.post('/documents/1/reindex')).toEqual({ ok: true });
        expect(fetchMock).toHaveBeenCalledTimes(4);
    });

    it('explica cuando no hay conexión con el servidor', async () => {
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));

        const error = await failure(api.get('/emails'));

        expect(error.status).toBe(0);
        expect(error.message).toContain('No se pudo conectar');
    });
});
