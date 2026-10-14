<?php

namespace App\Tools\Contracts;

/**
 * Marca las herramientas que devuelven datos de un cliente concreto: solo
 * se pueden usar al procesar un email con remitente, y deben limitar lo que
 * devuelven a ese remitente (ToolContext::senderEmail()).
 */
interface RequiresSender
{
}
