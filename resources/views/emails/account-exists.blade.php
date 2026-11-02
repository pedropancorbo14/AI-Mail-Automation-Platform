{{-- Texto plano: sin escapar como HTML (un "&" del enlace firmado se rompería). --}}
Hola {!! $name !!}:

Alguien ha intentado darse de alta con este email, pero ya tienes una cuenta. Puedes entrar aquí:

{!! $url !!}

Si no has sido tú, no tienes que hacer nada: no se ha cambiado nada en tu cuenta.
