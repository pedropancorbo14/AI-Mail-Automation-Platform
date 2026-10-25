<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import {
    NAlert,
    NButton,
    NCard,
    NCheckbox,
    NGrid,
    NGridItem,
    NRadio,
    NRadioGroup,
    NSkeleton,
    NSpace,
    NSwitch,
    NTag,
    NText,
    useDialog,
    useMessage,
} from 'naive-ui';
import { ApiError } from '@/api/http';
import { getAutomation, setToolEnabled, updateAutomation, type AutomationModeValue, type AutomationSettings } from '@/api/automation';
import type { EmailIntent } from '@/api/types';
import { formatPercent } from '@/utils/format';
import { intentLabels, toolLabels } from '@/utils/labels';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const dialog = useDialog();
const message = useMessage();

const settings = ref<AutomationSettings | null>(null);
const error = ref<string | null>(null);
const saving = ref(false);
const selectedIntents = ref<EmailIntent[]>([]);

// Ver es para todos; cambiar, solo propietario y administradores (la API lo vuelve a comprobar).
const canManage = computed(() => auth.can('automation.manage'));
const intentsChanged = computed(() =>
    settings.value !== null && [...selectedIntents.value].sort().join() !== [...settings.value.auto_send_intents].sort().join(),
);

function apply(result: AutomationSettings): void {
    settings.value = result;
    selectedIntents.value = [...result.auto_send_intents];
}

async function save(action: () => Promise<AutomationSettings>, done: string): Promise<void> {
    saving.value = true;

    try {
        apply(await action());
        message.success(done);
    } catch (e) {
        message.error(e instanceof ApiError ? (e.fieldError('auto_send_intents') ?? e.message) : 'No se pudo guardar.');
    } finally {
        saving.value = false;
    }
}

function changeMode(mode: AutomationModeValue): void {
    const run = () => save(() => updateAutomation({ mode }), 'Modo de automatización guardado.');

    if (mode !== 'auto') {
        void run();

        return;
    }

    // Enviar sin persona tiene consecuencias: se confirma.
    dialog.warning({
        title: '¿Activar el envío automático?',
        content: 'Las respuestas que la IA considere seguras, de las intenciones permitidas, se enviarán a los clientes sin que nadie las revise. Lo demás seguirá yendo a revisión humana.',
        positiveText: 'Activar',
        negativeText: 'Cancelar',
        onPositiveClick: run,
    });
}

onMounted(async () => {
    try {
        apply(await getAutomation());
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar la configuración.';
    }
});
</script>

<template>
    <NSpace vertical :size="16">
        <h2 class="settings__title">Configuración de la IA</h2>

        <NAlert v-if="error" type="error">{{ error }}</NAlert>
        <NSkeleton v-if="!settings && !error" text :repeat="8" />

        <template v-if="settings">
            <NAlert v-if="!settings.platform.ai_configured" type="warning" title="La IA no está configurada en el servidor">
                Falta la clave de la API de la IA: todos los emails van a revisión humana hasta que el administrador del servidor la configure.
            </NAlert>
            <NAlert v-if="!settings.platform.auto_send_enabled" type="info" title="Envío automático desactivado en la plataforma">
                Aunque elijas el modo automático, ninguna respuesta se enviará sin revisión hasta que se reactive en el servidor.
            </NAlert>
            <NAlert v-if="!canManage" type="info" :show-icon="false">Solo los propietarios y administradores pueden cambiar esta configuración.</NAlert>

            <NCard title="Modo de automatización" size="small">
                <NRadioGroup :value="settings.mode" :disabled="!canManage || saving" name="mode" @update:value="changeMode">
                    <NSpace vertical>
                        <NRadio v-for="mode in settings.modes" :key="mode.value" :value="mode.value" :data-test="`mode-${mode.value}`">
                            <strong>{{ mode.label }}</strong>
                            <NText depth="3" class="settings__description">{{ mode.description }}</NText>
                        </NRadio>
                    </NSpace>
                </NRadioGroup>
            </NCard>

            <NGrid :cols="24" :x-gap="16" :y-gap="16" item-responsive responsive="screen">
                <NGridItem span="24 l:12">
                    <NCard title="Qué puede responder la IA sola" size="small">
                        <template #header-extra>
                            <NTag size="small" :bordered="false">{{ settings.auto_send_intents_custom ? 'Elegidas por la empresa' : 'Por defecto' }}</NTag>
                        </template>
                        <NText depth="3" class="settings__hint">
                            Solo en modo automático, y solo si la respuesta es segura: confianza de al menos
                            {{ formatPercent(settings.platform.min_confidence) }}, sin acciones pendientes ni dudas de la IA, y como máximo
                            {{ settings.platform.max_auto_replies_per_address }} respuestas automáticas al día a la misma dirección.
                            <template v-if="settings.platform.max_email_age_days > 0">
                                Un correo de hace más de {{ settings.platform.max_email_age_days }} días (por ejemplo, uno antiguo movido a la bandeja)
                                no pasa por la IA: lo revisa una persona.
                            </template>
                        </NText>
                        <div class="settings__intents">
                            <NCheckbox
                                v-for="intent in settings.intents"
                                :key="intent.value"
                                :checked="selectedIntents.includes(intent.value)"
                                :disabled="!canManage || !intent.allows_auto_send"
                                @update:checked="(checked: boolean) => selectedIntents = checked ? [...selectedIntents, intent.value] : selectedIntents.filter((v) => v !== intent.value)"
                            >
                                {{ intentLabels[intent.value] }}
                                <NText v-if="!intent.allows_auto_send" depth="3"> · siempre una persona</NText>
                            </NCheckbox>
                        </div>
                        <NSpace v-if="canManage" class="settings__actions">
                            <NButton type="primary" size="small" :disabled="!intentsChanged" :loading="saving"
                                @click="save(() => updateAutomation({ auto_send_intents: selectedIntents }), 'Intenciones guardadas.')">
                                Guardar intenciones
                            </NButton>
                            <NButton v-if="settings.auto_send_intents_custom" size="small" :disabled="saving"
                                @click="save(() => updateAutomation({ auto_send_intents: [] }), 'Intenciones restablecidas.')">
                                Volver a las de por defecto
                            </NButton>
                        </NSpace>
                    </NCard>
                </NGridItem>

                <NGridItem span="24 l:12">
                    <NCard title="Herramientas del agente" size="small">
                        <NText depth="3" class="settings__hint">
                            Lo que la IA puede consultar para preparar las respuestas. Solo ve datos del cliente que escribe y nunca modifica nada por su cuenta: lo que cambia datos (abrir un ticket) solo lo propone y una persona lo aprueba.
                        </NText>
                        <div v-for="tool in settings.tools" :key="tool.tool" class="settings__tool">
                            <NSwitch
                                :value="tool.enabled && tool.available !== false"
                                :disabled="!canManage || tool.available === false || saving"
                                :data-test="`tool-${tool.tool}`"
                                @update:value="(enabled: boolean) => save(() => setToolEnabled(tool.tool, enabled), enabled ? 'Herramienta activada.' : 'Herramienta desactivada.')"
                            />
                            <div>
                                <strong>{{ toolLabels[tool.tool]?.label ?? tool.tool }}</strong>
                                <NText v-if="tool.available === false" type="warning"> · sin datos configurados</NText>
                                <NText v-else-if="tool.available === null" depth="3"> · disponibilidad desconocida (el servicio de IA no responde)</NText>
                                <NText depth="3" class="settings__description">{{ toolLabels[tool.tool]?.description }}</NText>
                            </div>
                        </div>
                        <NText depth="3" class="settings__hint">Modelo de IA: {{ settings.platform.model }}</NText>
                    </NCard>
                </NGridItem>
            </NGrid>
        </template>
    </NSpace>
</template>

<style scoped>
.settings__title {
    margin: 0;
    font-size: 20px;
    font-weight: 500;
}

.settings__description {
    display: block;
    font-size: 13px;
}

.settings__hint {
    display: block;
    margin-bottom: 12px;
    font-size: 13px;
}

.settings__intents {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 8px;
}

.settings__actions {
    margin-top: 16px;
}

.settings__tool {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 16px;
}
</style>
