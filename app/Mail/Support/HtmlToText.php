<?php

namespace App\Mail\Support;

/**
 * Texto plano legible a partir de un cuerpo HTML (para proveedores que solo
 * dan HTML). No pretende ser perfecto: basta para que la IA y las personas
 * lean el contenido.
 */
class HtmlToText
{
    public static function convert(string $html): string
    {
        // Lo que no es contenido visible.
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html);

        // Saltos de línea de los elementos de bloque.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|tr|h[1-6]|blockquote)>#i', "\n", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Espacios repetidos y como mucho una línea en blanco seguida.
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
