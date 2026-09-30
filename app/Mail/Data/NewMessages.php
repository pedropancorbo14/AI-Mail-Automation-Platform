<?php

namespace App\Mail\Data;

final readonly class NewMessages
{
    /**
     * @param  list<MailMessage>  $messages  Mensajes nuevos, del más antiguo al más reciente.
     * @param  string  $cursor  Cursor a guardar y pasar en la siguiente llamada.
     * @param  bool  $resynced  El cursor anterior ya no era válido (p. ej. cambió UIDVALIDITY en
     *                          IMAP): se ha fijado un punto de partida nuevo y puede haberse
     *                          perdido correo intermedio que habrá que resincronizar.
     */
    public function __construct(
        public array $messages,
        public string $cursor,
        public bool $resynced = false,
    ) {
    }
}
