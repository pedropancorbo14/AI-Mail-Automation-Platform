<script setup lang="ts">
import { NCode, NCollapse, NCollapseItem, NEmpty, NSpace, NTag, NText } from 'naive-ui';
import type { AgentRun, ToolCall } from '@/api/types';
import { formatDateTime, formatUsd } from '@/utils/format';
import { toolLabel } from '@/utils/labels';

defineProps<{ runs: AgentRun[] }>();

const toolStatus: Record<ToolCall['status'], { label: string; type: 'success' | 'warning' | 'error' }> = {
    success: { label: 'OK', type: 'success' },
    error: { label: 'Error', type: 'warning' },
    rejected: { label: 'Rechazada', type: 'error' },
};


const json = (value: unknown) => JSON.stringify(value, null, 2);
const seconds = (ms: number | null) => (ms === null ? '—' : `${(ms / 1000).toLocaleString('es-ES', { maximumFractionDigits: 1 })} s`);
</script>

<template>
    <NEmpty v-if="!runs.length" description="El agente no se ha ejecutado con este email." />

    <div v-for="run in runs" :key="run.id" class="trace" data-test="agent-run">
        <NSpace size="small" align="center" class="trace__summary">
            <NTag :type="run.status === 'completed' ? 'success' : 'error'" size="small">{{ run.stop_reason_label ?? run.status }}</NTag>
            <NText depth="3">
                {{ formatDateTime(run.started_at) }} · {{ run.iterations }} pasos · {{ run.tool_calls_count }} herramientas ·
                {{ (run.input_tokens + run.output_tokens).toLocaleString('es-ES') }} tokens · {{ formatUsd(run.cost_usd) }} · {{ seconds(run.duration_ms) }}
            </NText>
        </NSpace>
        <NText v-if="run.error" type="error" class="trace__error">{{ run.error }}</NText>

        <ol class="trace__steps">
            <li v-for="step in run.steps" :key="step.id">
                <NText depth="3">
                    Paso: {{ step.model ?? '—' }} · {{ (step.input_tokens + step.output_tokens).toLocaleString('es-ES') }} tokens ·
                    {{ formatUsd(step.cost_usd) }} · {{ seconds(step.latency_ms) }}
                </NText>
                <NText v-if="step.error" type="error"> · {{ step.error }}</NText>
                <div v-if="!step.tool_calls.length" class="trace__final">
                    <NText depth="2">{{ step.status === 'success' ? 'Respuesta final' : 'Sin respuesta' }}</NText>
                </div>
                <NCollapse v-else class="trace__tools">
                    <NCollapseItem v-for="call in step.tool_calls" :key="call.id" :name="call.id" data-test="tool-call">
                        <template #header>
                            <NSpace size="small" align="center">
                                <NTag :type="toolStatus[call.status].type" size="tiny">{{ toolStatus[call.status].label }}</NTag>
                                <strong>{{ toolLabel(call.tool) }}</strong>
                                <NText depth="3" code>{{ JSON.stringify(call.arguments) }}</NText>
                            </NSpace>
                        </template>
                        <NText v-if="call.error" type="warning">{{ call.error }}</NText>
                        <NText depth="3" class="trace__label">Lo que vio la IA:</NText>
                        <NCode :code="json(call.result)" word-wrap class="trace__code" />
                    </NCollapseItem>
                </NCollapse>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.trace + .trace {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px dashed #e5e5e5;
}

.trace__error {
    display: block;
    margin-top: 4px;
}

.trace__steps {
    margin: 8px 0 0;
    padding-left: 20px;
}

.trace__steps li {
    margin-bottom: 8px;
}

.trace__tools {
    margin-top: 4px;
}

.trace__final {
    margin-top: 2px;
}

.trace__label {
    display: block;
    margin: 4px 0;
    font-size: 12px;
}

.trace__code {
    display: block;
    max-height: 320px;
    overflow: auto;
    padding: 8px;
    font-size: 12px;
    background: #fafafa;
    border-radius: 4px;
}
</style>
