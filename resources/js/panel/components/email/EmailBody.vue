<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { NAlert, NButton, NEmpty, NIcon, NSpin, NTabPane, NTabs, NTag } from 'naive-ui';
import { AttachOutline } from '@vicons/ionicons5';
import { attachmentUrl, getEmailHtml } from '@/api/emails';
import type { EmailDetail } from '@/api/types';
import { formatBytes } from '@/utils/format';
import { EMAIL_SANDBOX, emailHtmlDocument } from '@/utils/emailHtml';

const props = defineProps<{ email: EmailDetail }>();

const html = ref<string | null>(null);
const remoteImages = ref(false);
const loadingHtml = ref(false);
const htmlError = ref(false);

// Por defecto el formato original si lo hay; el texto siempre está disponible.
const tab = ref(props.email.has_html ? 'html' : 'text');
const srcdoc = computed(() => (html.value === null ? '' : emailHtmlDocument(html.value)));

onMounted(async () => {
    if (!props.email.has_html) {
        return;
    }

    loadingHtml.value = true;

    try {
        const result = await getEmailHtml(props.email.id);
        html.value = result.html;
        remoteImages.value = result.remote_images;
    } catch {
        htmlError.value = true;
        tab.value = 'text';
    } finally {
        loadingHtml.value = false;
    }
});

const attachmentStatus: Record<string, string> = { pending: 'Pendiente', skipped: 'No descargado', failed: 'Error' };
</script>

<template>
    <div>
        <NTabs v-model:value="tab" type="line" size="small">
            <NTabPane v-if="email.has_html" name="html" tab="Formato original">
                <NAlert v-if="remoteImages" type="info" :show-icon="false" class="body__notice">
                    Las imágenes de internet están bloqueadas para proteger la privacidad (pueden avisar al remitente de que se ha leído).
                </NAlert>
                <NSpin :show="loadingHtml">
                    <iframe
                        v-if="html !== null"
                        :srcdoc="srcdoc"
                        :sandbox="EMAIL_SANDBOX"
                        referrerpolicy="no-referrer"
                        title="Contenido del email"
                        class="body__frame"
                        data-test="email-html"
                    />
                </NSpin>
            </NTabPane>
            <NTabPane name="text" tab="Texto">
                <pre v-if="email.text_body" class="body__text" data-test="email-text">{{ email.text_body }}</pre>
                <NEmpty v-else description="El email no tiene cuerpo en texto." />
            </NTabPane>
        </NTabs>
        <NAlert v-if="htmlError" type="warning" class="body__notice">No se pudo cargar el formato original: se muestra el texto.</NAlert>

        <div v-if="email.attachments.length" class="body__attachments">
            <template v-for="attachment in email.attachments" :key="attachment.id">
                <a v-if="attachment.status === 'stored'" :href="attachmentUrl(email.id, attachment.id)" download>
                    <NButton size="small" secondary>
                        <template #icon><NIcon><AttachOutline /></NIcon></template>
                        {{ attachment.filename }} · {{ formatBytes(attachment.size) }}
                    </NButton>
                </a>
                <NTag v-else size="small" :title="attachment.error ?? undefined">
                    {{ attachment.filename }} · {{ attachmentStatus[attachment.status] }}
                </NTag>
            </template>
        </div>
    </div>
</template>

<style scoped>
/* Un iframe aislado no puede ajustar su altura al contenido (necesitaría
   acceso al panel): altura moderada y el usuario puede estirarlo. */
.body__frame {
    width: 100%;
    height: 300px;
    resize: vertical;
    border: 1px solid #eee;
    border-radius: 4px;
    background: #fff;
}

.body__text {
    margin: 0;
    padding: 12px;
    max-height: 480px;
    overflow: auto;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-family: inherit;
    font-size: 14px;
    background: #fafafa;
    border-radius: 4px;
}

.body__notice {
    margin-bottom: 8px;
}

.body__attachments {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}
</style>
