import { api } from '@/api/http';
import type { EmailIntent } from '@/api/types';

export type AutomationModeValue = 'off' | 'draft' | 'auto';

export interface AutomationSettings {
    mode: AutomationModeValue;
    modes: { value: AutomationModeValue; label: string; description: string }[];
    auto_send_intents: EmailIntent[];
    auto_send_intents_custom: boolean;
    intents: { value: EmailIntent; allows_auto_send: boolean }[];
    tools: { tool: string; enabled: boolean; available: boolean | null; source: 'company' | 'default' }[];
    platform: {
        ai_configured: boolean;
        auto_send_enabled: boolean;
        model: string;
        min_confidence: number;
        max_auto_replies_per_address: number;
    };
}

type Response = { data: AutomationSettings };

export async function getAutomation(): Promise<AutomationSettings> {
    return (await api.get<Response>('/automation')).data;
}

/** auto_send_intents: [] = volver a las de la plataforma. */
export async function updateAutomation(changes: { mode?: AutomationModeValue; auto_send_intents?: EmailIntent[] }): Promise<AutomationSettings> {
    return (await api.patch<Response>('/automation', changes)).data;
}

export async function setToolEnabled(tool: string, enabled: boolean): Promise<AutomationSettings> {
    return (await api.patch<Response>(`/automation/tools/${encodeURIComponent(tool)}`, { enabled })).data;
}
