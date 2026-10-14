import { api } from '@/api/http';
import type { Paginated } from '@/api/types';

export type MailProvider = 'imap' | 'microsoft' | 'google';
export type MailConnectionStatus = 'pending' | 'active' | 'failing' | 'disabled';
export type Encryption = 'ssl' | 'starttls' | 'none';

export interface ServerSettings {
    host: string;
    port: number;
    encryption: Encryption;
    username: string;
}

export interface MailConnection {
    id: number;
    name: string;
    email: string;
    provider: MailProvider;
    status: MailConnectionStatus;
    settings: { imap?: ServerSettings; smtp?: ServerSettings; scopes?: string[] };
    last_tested_at: string | null;
    last_error: string | null;
    created_at: string | null;
}

/** Lo mínimo para filtros y selectores. */
export type MailConnectionSummary = Pick<MailConnection, 'id' | 'name' | 'email' | 'provider' | 'status'>;

export interface ConnectionTestResult {
    ok: boolean;
    incoming_ok: boolean;
    outgoing_ok: boolean;
    incoming_error: string | null;
    outgoing_error: string | null;
}

/** Lo que se envía al crear/editar un buzón IMAP. La contraseña vacía en edición = no cambiarla. */
export interface ImapConnectionInput {
    name: string;
    email: string;
    imap: ServerSettings & { password?: string };
    smtp: ServerSettings & { password?: string };
}

export function listMailConnections(page = 1): Promise<Paginated<MailConnection>> {
    return api.get<Paginated<MailConnection>>('/mail-connections', { page });
}

export async function createImapConnection(input: ImapConnectionInput): Promise<MailConnection> {
    return (await api.post<{ data: MailConnection }>('/mail-connections', { ...input, provider: 'imap' })).data;
}

export async function updateMailConnection(id: number, changes: Partial<ImapConnectionInput> & { enabled?: boolean }): Promise<MailConnection> {
    return (await api.patch<{ data: MailConnection }>(`/mail-connections/${id}`, changes)).data;
}

export function deleteMailConnection(id: number): Promise<void> {
    return api.delete<void>(`/mail-connections/${id}`);
}

export async function testMailConnection(id: number): Promise<{ connection: MailConnection; result: ConnectionTestResult }> {
    const response = await api.post<{ data: MailConnection; result: ConnectionTestResult }>(`/mail-connections/${id}/test`);

    return { connection: response.data, result: response.result };
}

/** Empieza la conexión con Microsoft o Google: devuelve la URL a la que llevar al usuario. */
export async function startOAuth(provider: 'microsoft' | 'google'): Promise<string> {
    return (await api.post<{ authorization_url: string }>(`/mail-connections/oauth/${provider}/start`)).authorization_url;
}

/** Sin contraseñas vacías (en edición, vacía = conservar la guardada). */
export function withoutEmptyPasswords(input: ImapConnectionInput): ImapConnectionInput {
    const clean = (server: ImapConnectionInput['imap']) => {
        const { password, ...rest } = server;

        return password ? { ...rest, password } : rest;
    };

    return { ...input, imap: clean(input.imap), smtp: clean(input.smtp) };
}
