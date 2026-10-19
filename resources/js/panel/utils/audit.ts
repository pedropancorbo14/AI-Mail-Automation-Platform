import type { AuditCategory, AuditLogEntry } from '@/api/audit';
import type { EmailIntent } from '@/api/types';
import { roleLabels } from '@/menu';
import { intentLabels, toolLabel } from '@/utils/labels';

export const HIDDEN = '[oculto]';

export const categoryLabels: Record<AuditCategory, string> = {
    auth: 'Inicios de sesión',
    user: 'Usuarios',
    tenant: 'Empresa',
    mail_connection: 'Buzones',
    automation: 'Ajustes de la IA',
    document: 'Documentación',
    email: 'Emails',
    retention: 'Retención de datos',
    orders: 'Pedidos',
    integration: 'Sistema conectado',
};

const actionLabels: Record<string, string> = {
    'auth.login': 'Inició sesión',
    'auth.logout': 'Cerró sesión',
    'auth.login_failed': 'Intento de inicio de sesión fallido',
    'auth.login_blocked': 'Inicio de sesión bloqueado (empresa desactivada)',
    'auth.password_changed': 'Cambió su contraseña',
    'auth.logout_other_devices': 'Cerró la sesión en sus demás dispositivos',
    'user.created': 'Creó un usuario',
    'user.updated': 'Modificó un usuario',
    'user.deleted': 'Eliminó un usuario',
    'tenant.updated': 'Modificó los datos de la empresa',
    'mail_connection.created': 'Añadió un buzón',
    'mail_connection.updated': 'Modificó un buzón',
    'mail_connection.tested': 'Probó un buzón',
    'mail_connection.deleted': 'Eliminó un buzón',
    'mail_connection.oauth_connected': 'Conectó un buzón con Microsoft o Google',
    'automation.updated': 'Cambió el modo de automatización',
    'automation.tool_changed': 'Cambió una herramienta de la IA',
    'document.uploaded': 'Subió un documento',
    'document.reindexed': 'Reindexó un documento',
    'document.deleted': 'Eliminó un documento',
    'email.deleted': 'Borró un email',
    'email.reply_approved': 'Aprobó y envió la respuesta de la IA',
    'email.reply_rejected': 'Descartó la propuesta de la IA',
    'email.replied': 'Respondió un email con su propio texto',
    'email.closed': 'Cerró un email sin responder',
    'email.reprocessed': 'Reprocesó un email con la IA',
    'email.action_approved': 'Aprobó una acción propuesta por la IA',
    'email.action_rejected': 'Rechazó una acción propuesta por la IA',
    'email.assigned': 'Cambió a quién está asignado un email',
    'retention.emails_purged': 'Borrado automático de emails antiguos',
    'retention.address_forgotten': 'Borró todos los emails de una persona (derecho de supresión)',
    'orders.imported': 'Importó pedidos desde un CSV',
    'orders.deleted': 'Borró todos los pedidos importados',
    'integration.created': 'Conectó el sistema de la empresa (API estándar)',
    'integration.updated': 'Cambió la conexión con el sistema de la empresa',
    'integration.tested': 'Probó la conexión con el sistema de la empresa',
    'integration.deleted': 'Desconectó el sistema de la empresa',
};

/** Acciones desconocidas (de una versión más nueva) se muestran tal cual. */
export const actionLabel = (action: string): string => actionLabels[action] ?? action;

/** Las acciones que conviene mirar dos veces (fallos, borrados). */
export function actionTone(action: string): 'error' | 'warning' | 'default' {
    if (action === 'auth.login_failed' || action === 'auth.login_blocked') {
        return 'error';
    }

    return action.endsWith('.deleted') ? 'warning' : 'default';
}

const fieldLabels: Record<string, string> = {
    name: 'Nombre',
    email: 'Email',
    role: 'Rol',
    password: 'Contraseña',
    status: 'Estado',
    credentials: 'Credenciales',
    automation_mode: 'Modo',
    auto_send_intents: 'Se envían solas',
    email_retention_days: 'Conservar los emails (días)',
    base_url: 'Dirección',
    token: 'Clave',
    orders: 'Pedidos',
    invoices: 'Facturas',
    enabled: 'Activada',
};

const valueLabels: Record<string, Record<string, string>> = {
    role: roleLabels,
    status: { pending: 'Sin probar', active: 'Activo', failing: 'Falla', disabled: 'Desactivado' },
    automation_mode: { off: 'Desactivada', draft: 'Borradores', auto: 'Automática' },
};

function value(field: string, raw: unknown): string {
    if (raw === null || raw === undefined) {
        return field === 'auto_send_intents' ? 'las de la plataforma' : '—';
    }

    if (Array.isArray(raw)) {
        return raw.map((item) => (field === 'auto_send_intents' ? intentLabels[item as EmailIntent] ?? String(item) : String(item))).join(', ') || '—';
    }

    if (typeof raw === 'boolean') {
        return raw ? 'sí' : 'no';
    }

    return valueLabels[field]?.[String(raw)] ?? String(raw);
}

/** Una línea por campo cambiado: "Rol: Agente → Administrador", "Contraseña: cambiada". */
export function describeChanges(entry: AuditLogEntry): string[] {
    return Object.entries(entry.changes ?? {}).map(([field, change]) => {
        const label = fieldLabels[field] ?? field;

        if (change === HIDDEN || typeof change !== 'object' || change === null) {
            return `${label}: ${field === 'credentials' ? 'cambiadas' : 'cambiada'}`;
        }

        return `${label}: ${value(field, change.from)} → ${value(field, change.to)}`;
    });
}

/** Datos extra que ayudan a entender la acción. */
export function describeMetadata(entry: AuditLogEntry): string[] {
    const meta = entry.metadata ?? {};
    const lines: string[] = [];

    if (entry.action === 'auth.login' || entry.action === 'auth.logout') {
        lines.push(meta.method === 'session' ? 'Desde el panel' : `Con token de API${meta.device_name ? ` «${meta.device_name}»` : ''}`);
    } else if (entry.action === 'auth.login_failed' && meta.email) {
        lines.push(`Con el email ${meta.email}`);
    } else if (entry.action === 'mail_connection.tested') {
        lines.push(`Recepción ${meta.incoming_ok ? 'correcta' : 'con error'}, envío ${meta.outgoing_ok ? 'correcto' : 'con error'}`);
    } else if ((entry.action === 'email.action_approved' || entry.action === 'email.action_rejected') && typeof meta.tool === 'string') {
        lines.push(toolLabel(meta.tool));
    } else if (entry.action === 'email.reprocessed' && meta.with_instruction) {
        lines.push('Con una indicación para la IA');
    } else if (entry.action === 'automation.tool_changed' && typeof meta.tool === 'string') {
        lines.push(`${toolLabel(meta.tool)}: ${meta.enabled ? 'activada' : 'desactivada'}`);
    } else if (entry.action === 'mail_connection.oauth_connected' && meta.reconnected) {
        lines.push('Reconexión de un buzón existente');
    } else if ((entry.action === 'auth.password_changed' || entry.action === 'auth.logout_other_devices') && Number(meta.revoked_count) > 0) {
        lines.push(`Tokens de API revocados: ${meta.revoked_count}`);
    } else if (entry.action.startsWith('retention.') && meta.emails !== undefined) {
        lines.push(`Emails borrados: ${meta.emails}${meta.days ? ` (más de ${meta.days} días)` : ''}`);
        if (Number(meta.orders) > 0) {
            lines.push(`Pedidos importados borrados: ${meta.orders}`);
        }
    } else if (entry.action === 'orders.imported') {
        lines.push(`${meta.filename ?? 'CSV'}: ${meta.created} nuevos, ${meta.updated} actualizados`);
    } else if (entry.action === 'integration.tested') {
        lines.push(meta.ok ? 'Conexión correcta' : 'La conexión falló');
    } else if (entry.action === 'orders.deleted') {
        lines.push(`Pedidos borrados: ${meta.orders}`);
    } else if (entry.action === 'email.reply_approved') {
        lines.push(meta.edited ? 'Con cambios en el texto' : 'Tal cual la propuso la IA');
    } else if (entry.action === 'email.assigned') {
        lines.push(meta.to_user ? `Ahora lo tiene ${meta.to_user}` : 'Liberado: sin asignar');
    } else if (entry.action === 'user.created' && typeof meta.role === 'string') {
        lines.push(`Rol: ${roleLabels[meta.role as keyof typeof roleLabels] ?? meta.role}`);
    }

    return lines;
}

/**
 * Sobre qué, si aporta algo: en las sesiones es la propia cuenta y en las
 * herramientas ya lo dice el detalle ("Consultar pedido: activada").
 */
export function subjectLabel(entry: AuditLogEntry): string | null {
    if (entry.action.startsWith('auth.') || entry.action === 'automation.tool_changed') {
        return null;
    }

    return entry.subject?.label ?? null;
}

/** Quién: nombre actual, o el email que tenía si ya no existe; sin actor = intento sin cuenta. */
export function actorName(entry: AuditLogEntry): string {
    if (!entry.actor) {
        return entry.action === 'auth.login_failed' ? 'Desconocido' : 'La plataforma';
    }

    return entry.actor.name ?? `${entry.actor.email ?? 'Usuario'} (eliminado)`;
}
