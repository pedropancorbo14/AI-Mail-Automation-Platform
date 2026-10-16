import type { Component } from 'vue';
import {
    BookOutline,
    BusinessOutline,
    FileTrayStackedOutline,
    HomeOutline,
    MailOutline,
    ShieldCheckmarkOutline,
    SparklesOutline,
} from '@vicons/ionicons5';

export interface MenuEntry {
    /** Nombre de la ruta del panel. */
    route: string;
    label: string;
    icon: Component;
    /** Permiso necesario para verla (mismo que exige la API). */
    permission?: string;
}

/** Secciones del menú lateral, en orden. */
export const menuEntries: MenuEntry[] = [
    { route: 'home', label: 'Resumen', icon: HomeOutline },
    { route: 'emails', label: 'Emails', icon: MailOutline, permission: 'emails.view' },
    { route: 'mail-connections', label: 'Buzones', icon: FileTrayStackedOutline, permission: 'mail_connections.view' },
    { route: 'documents', label: 'Documentación', icon: BookOutline, permission: 'documents.view' },
    { route: 'ai-settings', label: 'Ajustes de la IA', icon: SparklesOutline, permission: 'tenant.view' },
    { route: 'company', label: 'Empresa y usuarios', icon: BusinessOutline, permission: 'tenant.view' },
    { route: 'audit', label: 'Auditoría', icon: ShieldCheckmarkOutline, permission: 'audit.view' },
];

/** Solo las secciones que el usuario puede usar. */
export function visibleMenu(entries: MenuEntry[], can: (permission: string) => boolean): MenuEntry[] {
    return entries.filter((entry) => !entry.permission || can(entry.permission));
}

export const roleLabels: Record<'owner' | 'admin' | 'agent', string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    agent: 'Agente',
};
