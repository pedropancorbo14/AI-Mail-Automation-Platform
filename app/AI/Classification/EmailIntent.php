<?php

namespace App\AI\Classification;

/**
 * Intenciones que reconoce el clasificador de ai-service: espejo de
 * `category` en contracts/events/common.schema.json (un test lo comprueba).
 * Añadir una exige cambiar el contrato y el prompt de ai-service.
 */
enum EmailIntent: string
{
    case OrderStatus = 'order_status';
    case ReturnRequest = 'return_request';
    case Cancellation = 'cancellation';
    case InvoiceRequest = 'invoice_request';
    case BillingIssue = 'billing_issue';
    case ProductQuestion = 'product_question';
    case TechnicalSupport = 'technical_support';
    case Complaint = 'complaint';
    case SalesInquiry = 'sales_inquiry';
    case AccountChange = 'account_change';
    case Appointment = 'appointment';
    case Feedback = 'feedback';
    case AutoReply = 'auto_reply';
    case Spam = 'spam';
    case Other = 'other';

    /** Descripción que lee el modelo en el prompt. */
    public function description(): string
    {
        return match ($this) {
            self::OrderStatus => 'pregunta por el estado, la entrega o el seguimiento de un pedido',
            self::ReturnRequest => 'quiere devolver un producto o pide un reembolso por devolución',
            self::Cancellation => 'quiere cancelar un pedido, servicio o suscripción (no una cita)',
            self::InvoiceRequest => 'pide una factura, un duplicado o un justificante',
            self::BillingIssue => 'problema con un cobro: importe incorrecto, cargo duplicado, pago fallido',
            self::ProductQuestion => 'pregunta sobre un producto o servicio antes o después de comprarlo',
            self::TechnicalSupport => 'algo no funciona y necesita ayuda técnica',
            self::Complaint => 'queja o reclamación por el servicio, el trato o un problema no resuelto',
            self::SalesInquiry => 'quiere comprar, pide presupuesto, precios o condiciones comerciales',
            self::AccountChange => 'cambiar datos de su cuenta: dirección, email, contraseña, datos fiscales',
            self::Appointment => 'pregunta por una cita (fecha, hora, lugar, profesional) o pide reservarla, cambiarla o cancelarla',
            self::Feedback => 'opinión, agradecimiento o sugerencia sin petición concreta',
            self::AutoReply => 'respuesta automática: fuera de la oficina, acuse de recibo, aviso de entrega fallida',
            self::Spam => 'publicidad no solicitada, phishing o correo sin relación con la empresa',
            self::Other => 'ninguna de las anteriores',
        };
    }

    /**
     * Si una empresa puede permitir que la IA responda sola a esta
     * intención. Nunca a quejas ni a problemas de cobro (los ve una
     * persona), ni a lo que no necesita respuesta o no se ha entendido.
     */
    public function allowsAutoSend(): bool
    {
        return ! in_array($this, [self::Complaint, self::BillingIssue, self::AutoReply, self::Spam, self::Other], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
