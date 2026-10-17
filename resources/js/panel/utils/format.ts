const dateTime = new Intl.DateTimeFormat('es-ES', { dateStyle: 'short', timeStyle: 'short' });

/** "14/10/26, 16:08" en la zona horaria del navegador; "—" si no hay fecha. */
export function formatDateTime(value: string | null | undefined): string {
    return value ? dateTime.format(new Date(value)) : '—';
}

/** 0.923 → "92 %". */
export function formatPercent(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : `${Math.round(value * 100)} %`;
}

/** Milisegundos como segundos legibles: 6000 → "6 s", 2500 → "2,5 s". */
export function formatSeconds(ms: number | null | undefined): string {
    return ms === null || ms === undefined ? '—' : `${(ms / 1000).toLocaleString('es-ES', { maximumFractionDigits: 1 })} s`;
}

/** 2048 → "2 KB". */
export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toLocaleString('es-ES', { maximumFractionDigits: 1 })} ${units[unit]}`;
}

/** Nombre y email del remitente: "Ana Pérez <ana@cliente.test>". */
export function formatAddress(address: { email: string | null; name: string | null }): string {
    if (address.name && address.email) {
        return `${address.name} <${address.email}>`;
    }

    return address.name ?? address.email ?? '—';
}

/** Segundos como duración corta: 45 → "45 s", 600 → "10 min", 5400 → "1 h 30 min", 200000 → "2 d 7 h". */
export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const s = Math.max(0, Math.round(seconds));
    const days = Math.floor(s / 86_400);
    const hours = Math.floor((s % 86_400) / 3600);
    const minutes = Math.floor((s % 3600) / 60);

    if (days > 0) {
        return hours > 0 ? `${days} d ${hours} h` : `${days} d`;
    }

    if (hours > 0) {
        return minutes > 0 ? `${hours} h ${minutes} min` : `${hours} h`;
    }

    return minutes > 0 ? `${minutes} min` : `${s} s`;
}

/** Tiempo transcurrido desde una fecha: "hace 2 h 5 min". */
export function formatSince(value: string | null | undefined, now: number = Date.now()): string {
    return value ? `hace ${formatDuration((now - Date.parse(value)) / 1000)}` : '—';
}
