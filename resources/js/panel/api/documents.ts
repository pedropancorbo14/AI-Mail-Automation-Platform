import { api } from '@/api/http';
import type { Paginated } from '@/api/types';

export type DocumentStatus = 'pending' | 'processing' | 'ready' | 'failed';

export interface KnowledgeDocument {
    id: number;
    title: string;
    original_filename: string;
    mime_type: string;
    size: number;
    status: DocumentStatus;
    error: string | null;
    chunks_count: number;
    embedding_model: string | null;
    uploaded_by: number | null;
    processed_at: string | null;
    created_at: string | null;
}

export interface SearchResult {
    source: string;
    document_id: number;
    document_title: string;
    content: string;
    metadata: Record<string, unknown>;
    similarity: number;
}

/** Formatos que sabe leer la plataforma (lo comprueba también el servidor). */
export const ACCEPTED_FILES = '.pdf,.txt,.md,.markdown,.html,.htm';

export function listDocuments(page = 1): Promise<Paginated<KnowledgeDocument>> {
    return api.get<Paginated<KnowledgeDocument>>('/documents', { page });
}

export async function uploadDocument(file: File, title?: string): Promise<KnowledgeDocument> {
    const body = new FormData();
    body.append('file', file);

    if (title) {
        body.append('title', title);
    }

    return (await api.post<{ data: KnowledgeDocument }>('/documents', body)).data;
}

export async function reindexDocument(id: number): Promise<KnowledgeDocument> {
    return (await api.post<{ data: KnowledgeDocument }>(`/documents/${id}/reindex`)).data;
}

export function deleteDocument(id: number): Promise<void> {
    return api.delete<void>(`/documents/${id}`);
}

export async function searchKnowledge(query: string, limit = 5): Promise<SearchResult[]> {
    return (await api.post<{ data: SearchResult[] }>('/knowledge-base/search', { query, limit })).data;
}

/** Si hay documentos indexándose (la lista se refresca sola mientras tanto). */
export const isIndexing = (documents: KnowledgeDocument[]): boolean =>
    documents.some((document) => document.status === 'pending' || document.status === 'processing');

const types: Record<string, string> = {
    'application/pdf': 'PDF',
    'text/markdown': 'Markdown',
    'text/html': 'HTML',
    'text/plain': 'Texto',
};

export const documentType = (mime: string): string => types[mime] ?? mime;
