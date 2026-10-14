<?php

namespace App\Mail\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\File;

/**
 * Limpia el HTML de un email antes de mostrarlo en el panel. El HTML viene
 * de fuera (cualquiera puede escribir a un buzón): se queda solo con el
 * formato (texto, listas, tablas, enlaces, imágenes) y quita scripts,
 * formularios, iframes, eventos (onclick...), estilos <style> y enlaces
 * javascript:. Los enlaces se abren en otra pestaña sin dar acceso al panel
 * (rel="noopener noreferrer"). El panel lo muestra además en un iframe
 * aislado (sandbox + CSP que bloquea imágenes remotas): dos capas.
 */
class HtmlSanitizer
{
    private const array ELEMENTS = [
        'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'small', 'sub', 'sup', 'center', 'font',
        'blockquote', 'pre', 'code', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
        'a', 'img',
    ];

    private const array ATTRIBUTES = [
        '*.style', '*.align',
        'a.href', 'a.title',
        'img.src', 'img.alt', 'img.width', 'img.height',
        'table.width', 'table.border', 'table.cellpadding', 'table.cellspacing',
        'td.colspan', 'td.rowspan', 'td.width', 'td.valign', 'th.colspan', 'th.rowspan', 'th.width', 'th.valign',
        'font.color', 'font.size', 'font.face',
    ];

    private const array CSS = [
        'color', 'background-color', 'font-weight', 'font-style', 'font-size', 'font-family', 'text-align',
        'text-decoration', 'line-height', 'margin', 'padding', 'border', 'width', 'height', 'vertical-align',
    ];

    private ?HTMLPurifier $purifier = null;

    public function sanitize(string $html): string
    {
        return trim($this->purifier()->purify($html));
    }

    /** Si el HTML (ya limpio) carga imágenes de internet (posibles píxeles de rastreo). */
    public static function hasRemoteImages(string $html): bool
    {
        return (bool) preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']?\s*(https?:)?\/\//i', $html);
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $cache = storage_path('framework/cache/htmlpurifier');
        File::ensureDirectoryExists($cache);

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cache);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.AllowedElements', self::ELEMENTS);
        $config->set('HTML.AllowedAttributes', self::ATTRIBUTES);
        $config->set('CSS.AllowedProperties', self::CSS);
        // Sin javascript:, data: ni otros esquemas: solo enlaces web y de correo.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('HTML.Nofollow', true);

        return $this->purifier = new HTMLPurifier($config);
    }
}
