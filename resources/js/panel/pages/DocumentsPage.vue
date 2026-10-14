<script setup lang="ts">
import { computed, h, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    NAlert,
    NButton,
    NCard,
    NDataTable,
    NEmpty,
    NInput,
    NInputGroup,
    NPopconfirm,
    NSpace,
    NTag,
    NText,
    NUpload,
    useMessage,
    type DataTableColumns,
    type UploadCustomRequestOptions,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import {
    ACCEPTED_FILES,
    deleteDocument,
    documentType,
    isIndexing,
    listDocuments,
    reindexDocument,
    searchKnowledge,
    uploadDocument,
    type DocumentStatus,
    type KnowledgeDocument,
    type SearchResult,
} from '@/api/documents';
import { formatBytes, formatDateTime, formatPercent } from '@/utils/format';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const message = useMessage();
const canManage = computed(() => auth.can('documents.manage'));

const documents = ref<KnowledgeDocument[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const title = ref('');

const query = ref('');
const searching = ref(false);
const results = ref<SearchResult[] | null>(null);
const searchError = ref<string | null>(null);

const statusInfo: Record<DocumentStatus, { label: string; type: 'default' | 'info' | 'success' | 'error' }> = {
    pending: { label: 'En cola', type: 'default' },
    processing: { label: 'Indexando', type: 'info' },
    ready: { label: 'Listo', type: 'success' },
    failed: { label: 'Error', type: 'error' },
};

let poll: ReturnType<typeof setInterval> | undefined;

async function load(quiet = false): Promise<void> {
    loading.value = !quiet;

    try {
        documents.value = (await listDocuments()).data;
        error.value = null;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar la documentación.';
    } finally {
        loading.value = false;
        schedulePoll();
    }
}

// Indexar va en cola: mientras haya documentos pendientes, se refresca cada 3 s.
function schedulePoll(): void {
    if (isIndexing(documents.value) && !poll) {
        poll = setInterval(() => load(true), 3000);
    } else if (!isIndexing(documents.value) && poll) {
        clearInterval(poll);
        poll = undefined;
    }
}

function replace(document: KnowledgeDocument): void {
    const index = documents.value.findIndex((d) => d.id === document.id);
    index === -1 ? documents.value.unshift(document) : documents.value.splice(index, 1, document);
    schedulePoll();
}

async function upload({ file, onFinish, onError }: UploadCustomRequestOptions): Promise<void> {
    try {
        replace(await uploadDocument(file.file as File, title.value.trim() || undefined));
        title.value = '';
        message.success(`«${file.name}» subido: se está indexando.`);
        onFinish();
    } catch (e) {
        message.error(e instanceof ApiError ? (e.fieldError('file') ?? e.message) : 'No se pudo subir el documento.');
        onError();
    }
}

async function reindex(document: KnowledgeDocument): Promise<void> {
    try {
        replace(await reindexDocument(document.id));
        message.success('Reindexando.');
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo reindexar.');
    }
}

async function remove(document: KnowledgeDocument): Promise<void> {
    try {
        await deleteDocument(document.id);
        documents.value = documents.value.filter((d) => d.id !== document.id);
        message.success('Documento eliminado: la IA ya no lo consultará.');
    } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'No se pudo eliminar.');
    }
}

async function search(): Promise<void> {
    if (!query.value.trim()) {
        return;
    }

    searching.value = true;
    searchError.value = null;

    try {
        results.value = await searchKnowledge(query.value.trim());
    } catch (e) {
        searchError.value = e instanceof ApiError ? e.message : 'No se pudo buscar.';
        results.value = null;
    } finally {
        searching.value = false;
    }
}

onMounted(() => load());
onBeforeUnmount(() => clearInterval(poll));

const columns = computed<DataTableColumns<KnowledgeDocument>>(() => [
    {
        title: 'Documento',
        key: 'title',
        render: (row) => h('div', [h('strong', row.title), h(NText, { depth: 3, style: 'display:block;font-size:12px' }, () => row.original_filename)]),
    },
    { title: 'Tipo', key: 'mime_type', width: 100, render: (row) => documentType(row.mime_type) },
    { title: 'Tamaño', key: 'size', width: 100, render: (row) => formatBytes(row.size) },
    {
        title: 'Estado',
        key: 'status',
        width: 260,
        render: (row) => h('div', [
            h(NTag, { type: statusInfo[row.status].type, size: 'small' }, () => statusInfo[row.status].label),
            row.status === 'ready' ? h(NText, { depth: 3, style: 'margin-left:8px' }, () => `${row.chunks_count} fragmentos`) : null,
            row.error ? h(NText, { type: 'error', style: 'display:block;font-size:12px;margin-top:4px' }, () => row.error) : null,
        ]),
    },
    { title: 'Subido', key: 'created_at', width: 140, render: (row) => formatDateTime(row.created_at) },
    {
        title: '',
        key: 'actions',
        width: 190,
        render: (row) => !canManage.value ? null : h(NSpace, { size: 8, justify: 'end', wrap: false }, () => [
            h(NButton, { size: 'small', disabled: isIndexing([row]), onClick: () => reindex(row) }, () => 'Reindexar'),
            h(NPopconfirm, { onPositiveClick: () => remove(row), positiveText: 'Eliminar', negativeText: 'Cancelar' }, {
                trigger: () => h(NButton, { size: 'small', type: 'error', quaternary: true }, () => 'Eliminar'),
                default: () => '¿Eliminar el documento? La IA dejará de usarlo para responder.',
            }),
        ]),
    },
]);
</script>

<template>
    <NSpace vertical :size="16">
        <NCard title="Documentación de la empresa">
            <NText depth="3" class="docs__hint">
                Políticas, preguntas frecuentes, manuales... La IA busca en ellos para responder y cita de dónde saca cada dato.
                Se procesan en el propio servidor: no salen a ningún servicio externo.
            </NText>

            <NSpace v-if="canManage" align="center" class="docs__upload">
                <NInput v-model:value="title" placeholder="Título (opcional: si no, el nombre del fichero)" class="docs__title" />
                <NUpload :custom-request="upload" :accept="ACCEPTED_FILES" :show-file-list="false" :multiple="false">
                    <NButton type="primary">Subir documento</NButton>
                </NUpload>
                <NText depth="3" class="docs__formats">PDF con texto, Markdown, HTML o texto plano.</NText>
            </NSpace>

            <NAlert v-if="error" type="error" class="docs__error">{{ error }}</NAlert>
            <NDataTable :columns="columns" :data="documents" :loading="loading" :row-key="(row: KnowledgeDocument) => row.id" />
        </NCard>

        <NCard title="Probar la búsqueda: lo que encontraría la IA">
            <NInputGroup>
                <NInput v-model:value="query" placeholder="Por ejemplo: ¿cuánto tarda un envío a Canarias?" maxlength="2000" @keyup.enter="search" />
                <NButton type="primary" :loading="searching" :disabled="!query.trim()" data-test="search" @click="search">Buscar</NButton>
            </NInputGroup>

            <NAlert v-if="searchError" type="error" class="docs__results">{{ searchError }}</NAlert>
            <NEmpty v-else-if="results && !results.length" class="docs__results" description="Nada parecido en la documentación: la IA no inventará una respuesta y lo pasará a una persona." />
            <div v-else-if="results" class="docs__results" data-test="search-results">
                <div v-for="(result, index) in results" :key="index" class="result">
                    <NSpace justify="space-between" align="center">
                        <strong>{{ result.source }}</strong>
                        <NText depth="3">Parecido {{ formatPercent(result.similarity) }}</NText>
                    </NSpace>
                    <p class="result__content">{{ result.content }}</p>
                </div>
            </div>
        </NCard>
    </NSpace>
</template>

<style scoped>
.docs__hint {
    display: block;
    margin-bottom: 16px;
}

.docs__upload {
    margin-bottom: 16px;
}

.docs__title {
    width: 320px;
}

.docs__formats {
    font-size: 12px;
}

.docs__error {
    margin-bottom: 16px;
}

.docs__results {
    margin-top: 16px;
}

.result {
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.result:last-child {
    border-bottom: none;
}

.result__content {
    margin: 6px 0 0;
    max-height: 7.5em;
    overflow: hidden;
    white-space: pre-wrap;
    color: #555;
    font-size: 13px;
}
</style>
