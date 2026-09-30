<?php

namespace App\Mail\Data;

final readonly class MailAddress
{
    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {
    }

    public function toString(): string
    {
        return $this->name ? sprintf('"%s" <%s>', addcslashes($this->name, '"\\'), $this->email) : $this->email;
    }
}
