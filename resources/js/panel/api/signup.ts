import { api } from '@/api/http';

export interface SignupInfo {
    enabled: boolean;
    trial_days: number;
}

export interface SignupInput {
    company: string;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    accept_terms: boolean;
    /** Trampa para robots: siempre vacío. */
    website?: string;
}

export async function getSignupInfo(): Promise<SignupInfo> {
    return (await api.get<{ data: SignupInfo }>('/signup')).data;
}

/** Devuelve el mensaje para el usuario (el mismo exista o no el email). */
export async function signup(input: SignupInput): Promise<string> {
    return (await api.post<{ message: string }>('/signup', input)).message;
}

export async function resendVerification(email: string): Promise<string> {
    return (await api.post<{ message: string }>('/auth/verify-email/resend', { email })).message;
}
