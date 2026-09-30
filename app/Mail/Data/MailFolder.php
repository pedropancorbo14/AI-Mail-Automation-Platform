<?php

namespace App\Mail\Data;

final readonly class MailFolder
{
    public const string INBOX = 'inbox';

    public const string SENT = 'sent';

    public const string DRAFTS = 'drafts';

    public const string TRASH = 'trash';

    public const string JUNK = 'junk';

    public const string ARCHIVE = 'archive';

    /**
     * @param  string  $id  Identificador a usar en el resto de llamadas (ruta en IMAP, id en Graph/Gmail).
     * @param  string|null  $role  Uso especial normalizado (self::INBOX, self::SENT...), si el proveedor lo indica.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $role = null,
    ) {
    }
}
