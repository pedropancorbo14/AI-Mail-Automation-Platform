import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { NMessageProvider } from 'naive-ui';
import DocumentsPage from '@/pages/DocumentsPage.vue';
import { isIndexing, type KnowledgeDocument } from '@/api/documents';
import { useAuthStore } from '@/stores/auth';

const doc = (overrides: Partial<KnowledgeDocument> = {}): KnowledgeDocument => ({
    id: 1, title: 'Política de devoluciones', original_filename: 'politica-devoluciones.md', mime_type: 'text/markdown', size: 4096,
    status: 'ready', error: null, chunks_count: 6, embedding_model: 'bge-m3', uploaded_by: 1, processed_at: null, created_at: '2026-10-08T10:00:00Z',
    ...overrides,
});

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

function stubApi(search: unknown = { data: [] }): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => Promise.resolve(
        url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
            : url.startsWith('/api/v1/knowledge-base/search') ? json(search)
                : json({ data: [doc(), doc({ id: 2, title: 'Manual roto', original_filename: 'escaneado.pdf', mime_type: 'application/pdf', status: 'failed', error: 'El PDF no contiene texto extraíble.' })], meta: {} }),
    ));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountPage(permissions: string[]) {
    useAuthStore().user = { id: 1, name: 'Ana', email: 'a@b.test', role: 'admin', permissions, tenant: { id: 1, name: 'Acme', slug: 'acme' } };
    const wrapper = mount(defineComponent(() => () => h(NMessageProvider, () => h(DocumentsPage))));
    await flushPromises();

    return wrapper;
}

describe('documentación', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.unstubAllGlobals());

    it('muestra cada documento con su estado y por qué falló', async () => {
        stubApi();

        const wrapper = await mountPage(['documents.view', 'documents.manage']);

        expect(wrapper.text()).toContain('Política de devoluciones');
        expect(wrapper.text()).toContain('6 fragmentos');
        expect(wrapper.text()).toContain('El PDF no contiene texto extraíble.');
        expect(wrapper.text()).toContain('Subir documento');
    });

    it('sin permiso de gestión no deja subir ni borrar', async () => {
        stubApi();

        const wrapper = await mountPage(['documents.view']);

        expect(wrapper.text()).not.toContain('Subir documento');
        expect(wrapper.text()).not.toContain('Eliminar');
    });

    it('enseña lo que encontraría la IA, con su fuente', async () => {
        const fetchMock = stubApi({ data: [{ source: 'Envíos y entregas, sección «Coste del envío»', document_id: 1, document_title: 'Envíos', content: 'Canarias: 14,95 €.', metadata: {}, similarity: 0.74 }] });
        const wrapper = await mountPage(['documents.view']);

        await wrapper.get('input[placeholder^="Por ejemplo"]').setValue('envío a Canarias');
        await wrapper.get('[data-test="search"]').trigger('click');
        await flushPromises();

        const results = wrapper.get('[data-test="search-results"]').text();
        expect(results).toContain('Envíos y entregas, sección «Coste del envío»');
        expect(results).toContain('Parecido 74 %');
        expect(results).toContain('Canarias: 14,95 €.');
        expect(fetchMock.mock.calls.some(([url, init]) => url === '/api/v1/knowledge-base/search' && init.body === '{"query":"envío a Canarias","limit":5}')).toBe(true);
    });

    it('sabe cuándo hay documentos indexándose', () => {
        expect(isIndexing([doc(), doc({ status: 'processing' })])).toBe(true);
        expect(isIndexing([doc(), doc({ status: 'failed' })])).toBe(false);
    });
});
