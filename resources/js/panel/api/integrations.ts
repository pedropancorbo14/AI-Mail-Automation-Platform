import { api } from '@/api/http';

export type IntegrationStatus = 'pending' | 'active' | 'failing';

/** Conexión con el sistema propio de la empresa (API estándar). Sin la clave. */
export interface StandardApiConnection {
    type: 'standard_api';
    base_url: string;
    has_token: boolean;
    orders: boolean;
    invoices: boolean;
    enabled: boolean;
    status: IntegrationStatus;
    last_tested_at: string | null;
    last_error: string | null;
    updated_at: string | null;
}

export interface StandardApiTest {
    ok: boolean;
    error: string | null;
    checks: Record<string, string | null>;
}

export interface StandardApiForm {
    base_url: string;
    /** Vacía al editar = conservar la guardada. */
    token: string;
    orders: boolean;
    invoices: boolean;
    enabled: boolean;
}

interface Saved {
    data: StandardApiConnection;
    result: StandardApiTest;
}

export async function getStandardApi(): Promise<StandardApiConnection | null> {
    return (await api.get<{ data: StandardApiConnection | null }>('/integrations/standard-api')).data;
}

export function saveStandardApi(form: StandardApiForm): Promise<Saved> {
    const { token, ...rest } = form;

    return api.put<Saved>('/integrations/standard-api', token ? form : rest);
}

export function testStandardApi(): Promise<Saved> {
    return api.post<Saved>('/integrations/standard-api/test');
}

export function deleteStandardApi(): Promise<void> {
    return api.delete<void>('/integrations/standard-api');
}
