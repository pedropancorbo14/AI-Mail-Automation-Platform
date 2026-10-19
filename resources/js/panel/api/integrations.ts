import { api } from '@/api/http';

export type IntegrationStatus = 'pending' | 'active' | 'failing';
export type IntegrationKind = 'standard-api' | 'woocommerce';

/** Sistema conectado por la empresa (su ERP o su tienda). Sin credenciales. */
export interface IntegrationConnection {
    type: 'standard_api' | 'woocommerce';
    base_url: string;
    has_token: boolean;
    /** WooCommerce: final de la consumer key, para reconocerla. */
    consumer_key_hint: string | null;
    orders: boolean;
    invoices: boolean;
    enabled: boolean;
    status: IntegrationStatus;
    last_tested_at: string | null;
    last_error: string | null;
    updated_at: string | null;
}

export interface ConnectionTest {
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

export interface WooCommerceForm {
    base_url: string;
    /** Vacías al editar = conservar las guardadas. */
    consumer_key: string;
    consumer_secret: string;
    enabled: boolean;
}

export interface Saved {
    data: IntegrationConnection;
    result: ConnectionTest;
}

/** Sin los campos de credenciales vacíos (= conservar las guardadas). */
function withoutEmpty<T extends object>(form: T, keys: (keyof T)[]): Partial<T> {
    const body: Partial<T> = { ...form };
    for (const key of keys) {
        if (!body[key]) {
            delete body[key];
        }
    }

    return body;
}

export async function getIntegration(kind: IntegrationKind): Promise<IntegrationConnection | null> {
    return (await api.get<{ data: IntegrationConnection | null }>(`/integrations/${kind}`)).data;
}

export function testIntegration(kind: IntegrationKind): Promise<Saved> {
    return api.post<Saved>(`/integrations/${kind}/test`);
}

export function deleteIntegration(kind: IntegrationKind): Promise<void> {
    return api.delete<void>(`/integrations/${kind}`);
}

export const getStandardApi = () => getIntegration('standard-api');
export const testStandardApi = () => testIntegration('standard-api');
export const deleteStandardApi = () => deleteIntegration('standard-api');

export function saveStandardApi(form: StandardApiForm): Promise<Saved> {
    return api.put<Saved>('/integrations/standard-api', withoutEmpty(form, ['token']));
}

export function saveWooCommerce(form: WooCommerceForm): Promise<Saved> {
    return api.put<Saved>('/integrations/woocommerce', withoutEmpty(form, ['consumer_key', 'consumer_secret']));
}
