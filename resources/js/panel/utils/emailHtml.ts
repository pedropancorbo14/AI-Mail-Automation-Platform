/**
 * Política de contenido del documento del email: nada de scripts, frames,
 * formularios ni conexiones; solo estilos en línea e imágenes incrustadas.
 * Las imágenes remotas quedan bloqueadas (suelen ser píxeles de rastreo).
 */
export const EMAIL_CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'none'; base-uri 'none'";

/**
 * Atributo sandbox del iframe: sin allow-scripts ni allow-same-origin (el
 * contenido no puede ejecutar código ni leer la sesión del panel). Los
 * enlaces se abren en otra pestaña fuera del aislamiento.
 */
export const EMAIL_SANDBOX = 'allow-popups allow-popups-to-escape-sandbox';

/**
 * Documento completo para el srcdoc del iframe. El HTML ya viene saneado
 * del servidor: esta es una segunda capa de protección.
 */
export function emailHtmlDocument(html: string): string {
    return `<!DOCTYPE html><html><head><meta charset="utf-8">`
        + `<meta http-equiv="Content-Security-Policy" content="${EMAIL_CSP}">`
        + `<base target="_blank">`
        + `<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-size:14px;line-height:1.5;color:#1f2225;margin:12px;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}</style>`
        + `</head><body>${html}</body></html>`;
}
