import type { Component } from 'vue';
import { HomeOutline, MailOutline } from '@vicons/ionicons5';

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
];

/** Solo las secciones que el usuario puede usar. */
export function visibleMenu(entries: MenuEntry[], can: (permission: string) => boolean): MenuEntry[] {
    return entries.filter((entry) => !entry.permission || can(entry.permission));
}

export const roleLabels: Record<string, string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    agent: 'Agente',
};
