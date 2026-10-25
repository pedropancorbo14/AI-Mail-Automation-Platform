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
    /** Guardar en «Enviados» lo que la plataforma envía por SMTP (solo IMAP). */
    save_sent_copy: boolean;
    last_tested_at: string | null;
    last_error: string | null;
    /** Correos que la sincronización apartó porque fallan siempre al guardarlos. */
    skipped_messages?: number;
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
    save_sent_copy?: boolean;
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

/** Carpeta del buzón y si se sincroniza. */
export interface MailFolderInfo {
    id: string;
    name: string;
    role: 'inbox' | 'sent' | 'drafts' | 'trash' | 'junk' | 'archive' | null;
    /** La bandeja de entrada siempre se sincroniza; enviados, borradores, papelera y spam nunca. */
    selectable: boolean;
    synced: boolean;
    last_success_at: string | null;
    last_error: string | null;
}

export async function listMailFolders(id: number): Promise<MailFolderInfo[]> {
    return (await api.get<{ data: MailFolderInfo[] }>(`/mail-connections/${id}/folders`)).data;
}

/** Carpetas a sincronizar además de la bandeja de entrada. */
export async function updateMailFolders(id: number, folders: string[]): Promise<MailFolderInfo[]> {
    return (await api.put<{ data: MailFolderInfo[] }>(`/mail-connections/${id}/folders`, { folders })).data;
}

export interface AutodetectResult {
    found: boolean;
    /** ispdb (Thunderbird) | domain (autoconfig del dominio) | srv (DNS) | guess (nombres habituales) */
    source: 'ispdb' | 'domain' | 'srv' | 'guess' | null;
    /** El correo del dominio está en Microsoft 365 o Google: mejor conectar con OAuth. */
    provider_hint: 'microsoft' | 'google' | null;
    imap: ServerSettings | null;
    smtp: ServerSettings | null;
}

export async function autodetectSettings(email: string): Promise<AutodetectResult> {
    return (await api.post<{ data: AutodetectResult }>('/mail-connections/autodetect', { email })).data;
}
