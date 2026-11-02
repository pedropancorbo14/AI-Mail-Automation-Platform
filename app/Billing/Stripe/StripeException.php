<?php

namespace App\Billing\Stripe;

use RuntimeException;

/** Stripe no respondió o rechazó la petición. Mensaje apto para el panel, sin claves. */
class StripeException extends RuntimeException
{
}
