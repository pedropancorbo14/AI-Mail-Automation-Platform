import { describe, expect, it } from 'vitest';
import { formatAddress, formatBytes, formatDateTime, formatPercent, formatUsd } from '@/utils/format';

describe('formato de datos en el panel', () => {
    it('formatea fechas en español y marca las vacías', () => {
        expect(formatDateTime('2026-10-14T16:08:00')).toMatch(/^14\/10\/26,? 16:08$/);
        expect(formatDateTime(null)).toBe('—');
    });

    it('muestra la confianza como porcentaje', () => {
        expect(formatPercent(0.923)).toBe('92 %');
        expect(formatPercent(null)).toBe('—');
    });

    it('muestra los costes con la precisión justa', () => {
        expect(formatUsd(0.01102)).toBe('0,0110 $');
        expect(formatUsd(1.5)).toBe('1,50 $');
        expect(formatUsd(0)).toBe('0,00 $');
        expect(formatUsd(null)).toBe('—');
    });

    it('muestra tamaños legibles', () => {
        expect(formatBytes(512)).toBe('512 B');
        expect(formatBytes(2048)).toBe('2 KB');
        expect(formatBytes(3.5 * 1024 * 1024)).toBe('3,5 MB');
    });

    it('muestra el remitente con nombre y email', () => {
        expect(formatAddress({ email: 'ana@cliente.test', name: 'Ana' })).toBe('Ana <ana@cliente.test>');
        expect(formatAddress({ email: 'ana@cliente.test', name: null })).toBe('ana@cliente.test');
        expect(formatAddress({ email: null, name: null })).toBe('—');
    });
});
