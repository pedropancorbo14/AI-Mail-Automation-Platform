import { api } from '@/api/http';
import type { Paginated } from '@/api/types';

export type RoleName = 'owner' | 'admin' | 'agent';

export interface Company {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    /** Días que se conservan los emails; null = los de la plataforma. */
    email_retention_days: number | null;
    retention: { email_days: number; platform_email_days: number; min_email_days: number; max_email_days: number };
    created_at: string | null;
}

export interface CompanyUser {
    id: number;
    name: string;
    email: string;
    role: RoleName;
    created_at: string | null;
}

export interface UserInput {
    name: string;
    email: string;
    role: RoleName;
    /** Al editar, vacía = no cambiarla. */
    password?: string;
}

export async function getCompany(): Promise<Company> {
    return (await api.get<{ data: Company }>('/tenant')).data;
}

export async function renameCompany(name: string): Promise<Company> {
    return (await api.patch<{ data: Company }>('/tenant', { name })).data;
}

/** null = volver a los días de la plataforma. */
export async function setEmailRetention(days: number | null): Promise<Company> {
    return (await api.patch<{ data: Company }>('/tenant', { email_retention_days: days })).data;
}

export function listUsers(page = 1): Promise<Paginated<CompanyUser>> {
    return api.get<Paginated<CompanyUser>>('/users', { page });
}

export async function createUser(input: UserInput): Promise<CompanyUser> {
    return (await api.post<{ data: CompanyUser }>('/users', input)).data;
}

export async function updateUser(id: number, input: Partial<UserInput>): Promise<CompanyUser> {
    const { password, ...rest } = input;

    return (await api.patch<{ data: CompanyUser }>(`/users/${id}`, password ? { ...rest, password } : rest)).data;
}

export function deleteUser(id: number): Promise<void> {
    return api.delete<void>(`/users/${id}`);
}

/**
 * Roles que puede asignar quien está usando el panel: solo un propietario
 * crea o promociona propietarios (la API lo vuelve a comprobar).
 */
export function assignableRoles(actorRole: RoleName | undefined): RoleName[] {
    return actorRole === 'owner' ? ['owner', 'admin', 'agent'] : ['admin', 'agent'];
}
