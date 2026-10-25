<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { NAlert, NButton, NCheckbox, NSpace, NSpin, NText, useMessage } from 'naive-ui';
import { ApiError } from '@/api/http';
import { listMailFolders, updateMailFolders, type MailConnection, type MailFolderInfo } from '@/api/mailConnections';
import { formatDateTime } from '@/utils/format';

const props = defineProps<{ connection: MailConnection; canManage: boolean }>();
const emit = defineEmits<{ saved: []; cancel: [] }>();
const message = useMessage();

const folders = ref<MailFolderInfo[]>([]);
const selected = ref<Set<string>>(new Set());
const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);

const roleNotes: Partial<Record<NonNullable<MailFolderInfo['role']>, string>> = {
    inbox: 'Siempre se revisa.',
    sent: 'Nunca: son las respuestas de la propia empresa.',
    drafts: 'Nunca: borradores.',
    trash: 'Nunca: papelera.',
    junk: 'Nunca: spam.',
};

const changed = computed(() => folders.value.some((f) => f.selectable && f.synced !== selected.value.has(f.id)));

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        folders.value = await listMailFolders(props.connection.id);
        selected.value = new Set(folders.value.filter((f) => f.selectable && f.synced).map((f) => f.id));
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudieron leer las carpetas.';
    } finally {
        loading.value = false;
    }
}

function toggle(id: string, on: boolean): void {
    const next = new Set(selected.value);
    on ? next.add(id) : next.delete(id);
    selected.value = next;
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;

    try {
        folders.value = await updateMailFolders(props.connection.id, [...selected.value]);
        message.success('Carpetas guardadas. Las nuevas empiezan a revisarse desde ahora, sin el correo que ya tenían.');
        emit('saved');
    } catch (e) {
        error.value = e instanceof ApiError ? (e.fieldError('folders') ?? e.message) : 'No se pudieron guardar las carpetas.';
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <NSpin :show="loading">
        <NText depth="3" class="folders__hint">
            Además de la bandeja de entrada, la plataforma puede revisar otras carpetas (por ejemplo, las que llenan tus reglas de correo).
            Una carpeta nueva empieza desde ahora: no se importa el correo que ya tenía.
        </NText>
        <NAlert v-if="error" type="error" class="folders__error">{{ error }}</NAlert>

        <div v-for="folder in folders" :key="folder.id" class="folders__row">
            <NCheckbox
                :checked="folder.role === 'inbox' || selected.has(folder.id)"
                :disabled="!folder.selectable || !canManage"
                @update:checked="(on: boolean) => toggle(folder.id, on)"
            >
                {{ folder.name }}
            </NCheckbox>
            <NText v-if="folder.role && roleNotes[folder.role]" depth="3" class="folders__note">{{ roleNotes[folder.role] }}</NText>
            <NText v-if="folder.last_error" type="error" class="folders__note">{{ folder.last_error }}</NText>
            <NText v-else-if="folder.last_success_at" depth="3" class="folders__note">Revisada {{ formatDateTime(folder.last_success_at) }}</NText>
        </div>

        <NSpace v-if="canManage" justify="end" class="folders__actions">
            <NButton @click="emit('cancel')">Cancelar</NButton>
            <NButton type="primary" :loading="saving" :disabled="!changed" @click="save">Guardar carpetas</NButton>
        </NSpace>
    </NSpin>
</template>

<style scoped>
.folders__hint {
    display: block;
    margin-bottom: 12px;
}

.folders__error {
    margin-bottom: 12px;
}

.folders__row {
    padding: 6px 0;
    border-bottom: 1px solid var(--n-border-color, rgba(128, 128, 128, 0.15));
}

.folders__note {
    display: block;
    font-size: 12px;
    margin-left: 24px;
}

.folders__actions {
    margin-top: 16px;
}
</style>
