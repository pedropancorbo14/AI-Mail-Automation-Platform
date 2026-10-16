import { api } from '@/api/http';

export interface PasswordChange {
    current_password: string;
    password: string;
    password_confirmation: string;
}

/** Cambia la contraseña propia; cierra la sesión en los demás dispositivos. */
export async function changePassword(input: PasswordChange): Promise<string> {
    return (await api.put<{ message: string }>('/auth/password', input)).message;
}

/** Cierra la sesión en los demás dispositivos (pide la contraseña). */
export async function logoutOtherDevices(password: string): Promise<string> {
    return (await api.post<{ message: string }>('/auth/logout-other-devices', { password })).message;
}
