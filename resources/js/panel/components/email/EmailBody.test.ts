import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import EmailBody from '@/components/email/EmailBody.vue';
import { emailFixture } from '@/components/email/fixtures';
import { EMAIL_CSP, EMAIL_SANDBOX, emailHtmlDocument } from '@/utils/emailHtml';

function serveHtml(html: string | null, remoteImages = false): void {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { html, remote_images: remoteImages } }), { status: 200 })));
}

describe('cuerpo del email', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('muestra el HTML saneado en un iframe aislado, sin scripts ni acceso al panel', async () => {
        serveHtml('<p>Hola <b>Ana</b></p>', true);

        const wrapper = mount(EmailBody, { props: { email: emailFixture() } });
        await flushPromises();

        const frame = wrapper.get('[data-test="email-html"]');
        expect(frame.attributes('sandbox')).toBe(EMAIL_SANDBOX);
        expect(frame.attributes('sandbox')).not.toContain('allow-scripts');
        expect(frame.attributes('sandbox')).not.toContain('allow-same-origin');
        expect(frame.attributes('referrerpolicy')).toBe('no-referrer');
        expect(frame.attributes('srcdoc')).toContain(EMAIL_CSP);
        expect(frame.attributes('srcdoc')).toContain('<p>Hola <b>Ana</b></p>');
        expect(wrapper.text()).toContain('imágenes de internet están bloqueadas');
    });

    it('muestra el texto si el email no tiene HTML (sin pedirlo a la API)', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(EmailBody, { props: { email: emailFixture({ has_html: false }) } });

        expect(wrapper.get('[data-test="email-text"]').text()).toBe('Hola, ¿dónde está mi pedido?');
        expect(wrapper.find('[data-test="email-html"]').exists()).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('enlaza la descarga de los adjuntos guardados y avisa de los que no', () => {
        serveHtml(null);

        const wrapper = mount(EmailBody, { props: { email: emailFixture() } });

        const link = wrapper.get('a[download]');
        expect(link.attributes('href')).toBe('/api/v1/emails/12/attachments/7');
        expect(link.text()).toContain('justificante.pdf · 2 KB');
        expect(wrapper.text()).toContain('enorme.zip · No descargado');
        expect(wrapper.findAll('a[download]')).toHaveLength(1);
    });
});

describe('documento del iframe', () => {
    it('bloquea scripts, conexiones e imágenes remotas con una CSP', () => {
        const doc = emailHtmlDocument('<p>x</p>');

        expect(doc).toContain(`content="${EMAIL_CSP}"`);
        expect(EMAIL_CSP).toContain("default-src 'none'");
        expect(EMAIL_CSP).toContain('img-src data:');
        expect(EMAIL_CSP).not.toContain('script-src');
        expect(doc).toContain('<base target="_blank">');
    });
});
