import { api } from '@/api/http';

export interface OnboardingStep {
    key: string;
    label: string;
    description: string;
    done: boolean;
    /** Ruta del panel donde se hace. */
    route: string;
}

export interface Onboarding {
    steps: OnboardingStep[];
    completed: number;
    dismissed: boolean;
}

export async function getOnboarding(): Promise<Onboarding> {
    return (await api.get<{ data: Onboarding }>('/onboarding')).data;
}

export async function dismissOnboarding(): Promise<Onboarding> {
    return (await api.post<{ data: Onboarding }>('/onboarding/dismiss')).data;
}
