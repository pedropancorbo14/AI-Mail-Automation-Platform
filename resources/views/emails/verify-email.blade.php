{{-- Texto plano: sin escapar como HTML (un "&" del enlace firmado se rompería). --}}
Hola {!! $name !!}:

Gracias por darte de alta. Para empezar, confirma tu email abriendo este enlace:

{!! $url !!}

El enlace vale {!! $hours !!} horas. Si no te has dado de alta tú, ignora este mensaje: no se creará nada a tu nombre que puedas usar sin confirmarlo.
