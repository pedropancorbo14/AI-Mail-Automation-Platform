import type { LocationQuery, LocationQueryRaw } from 'vue-router';
import { emptyFilters, type EmailFilters } from '@/api/emails';
import { intentLabels, statusLabels } from '@/utils/labels';
import type { EmailIntent, EmailStatus } from '@/api/types';

function single(value: LocationQuery[string]): string | null {
    const first = Array.isArray(value) ? value[0] : value;

    return typeof first === 'string' && first !== '' ? first : null;
}

const isDate = (value: string | null): value is string => value !== null && /^\d{4}-\d{2}-\d{2}$/.test(value);

/**
 * Filtros del listado a partir de la URL (?status=sent&page=2). Lo que no
 * es válido se ignora: la URL la puede editar cualquiera.
 */
export function filtersFromQuery(query: LocationQuery): EmailFilters {
    const filters = emptyFilters();
    const status = single(query.status);
    const intent = single(query.intent);
    const mailbox = Number(single(query.mailbox));
    const page = Number(single(query.page));
    const from = single(query.from);
    const to = single(query.to);

    filters.status = status && status in statusLabels ? (status as EmailStatus) : null;
    filters.intent = intent && intent in intentLabels ? (intent as EmailIntent) : null;
    filters.mailbox = Number.isInteger(mailbox) && mailbox > 0 ? mailbox : null;
    filters.q = (single(query.q) ?? '').slice(0, 200);
    filters.from = isDate(from) ? from : null;
    filters.to = isDate(to) ? to : null;
    filters.page = Number.isInteger(page) && page > 1 ? page : 1;

    return filters;
}

/** URL a partir de los filtros, sin los vacíos ni la página 1. */
export function queryFromFilters(filters: EmailFilters): LocationQueryRaw {
    const query: LocationQueryRaw = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value !== null && value !== '' && !(key === 'page' && value === 1)) {
            query[key] = String(value);
        }
    }

    return query;
}
