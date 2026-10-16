<?php

use App\AI\Classification\EmailIntent;
use App\Automation\AutomationMode;
use App\Automation\AutoSendPolicy;
use App\Models\AiAgentRun;
use App\Models\AiRequest;
use App\Models\AiToolCall;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Security\ReplyContentGuard;

/*
 * Revisión del texto antes de enviarlo solo: nada que la IA no tuviera
 * delante (pedidos, importes, enlaces, direcciones) ni promesas falsas.
 */

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->configureAutomation(AutomationMode::Auto);

    [$this->email, $this->run] = inTenant($this->tenant, function () {
        $email = Email::factory()->create([
            'mail_connection_id' => MailConnection::factory()->create(['email' => 'soporte@tienda.test']),
            'from_email' => 'cliente@cliente.test',
            'subject' => '¿Dónde está mi pedido?',
            'text_body' => 'Hola, ¿cuándo llega el pedido 45821? Mirad esto: https://phishing.example/login',
        ]);
        $email->forceFill(['intent' => EmailIntent::OrderStatus, 'classified_at' => now()])->save();

        $run = AiAgentRun::create(['email_id' => $email->id, 'status' => 'completed']);
        $step = AiRequest::create([
            'agent_run_id' => $run->id, 'email_id' => $email->id, 'purpose' => 'email_agent',
            'provider' => 'anthropic', 'requested_model' => 'claude-opus-5-5', 'status' => 'success',
        ]);
        AiToolCall::create(['ai_request_id' => $step->id, 'email_id' => $email->id, 'tool' => 'get_order', 'arguments' => ['order_number' => '45821'], 'status' => 'success', 'result' => [
            'ok' => true, 'data' => ['order_number' => '45821', 'total' => 1349.5, 'currency' => 'EUR', 'tracking_url' => 'https://seguimiento.seur.test/SEU123'],
        ]]);
        AiToolCall::create(['ai_request_id' => $step->id, 'email_id' => $email->id, 'tool' => 'search_company_documents', 'arguments' => ['query' => 'envío'], 'status' => 'success', 'result' => [
            'ok' => true, 'data' => ['results' => [['content' => 'Envío a Canarias: 14,95 €. Dudas: envios@logistica.test']]]],
        ]);
        // Una herramienta fallida no cuenta como dato.
        AiToolCall::create(['ai_request_id' => $step->id, 'email_id' => $email->id, 'tool' => 'get_order', 'arguments' => ['order_number' => '99999'], 'status' => 'error', 'result' => [
            'ok' => false, 'error' => 'No hay pedido 99999 ni importe 777 €',
        ]]);

        return [$email, $run];
    });
});

/** @return list<string> */
function replyProblems(string $body): array
{
    return inTenant(test()->tenant, function () use ($body) {
        $reply = draftReply(test()->email, ['body' => $body]);
        $reply->forceFill(['agent_run_id' => test()->run->id])->save();

        return app(ReplyContentGuard::class)->problems(test()->email->fresh(), $reply->fresh());
    });
}

it('accepts a reply made only of what the ai had in front of it', function () {
    expect(replyProblems(
        "Hola:\n\nTu pedido #45821 (1.349,50 €) va en camino: https://seguimiento.seur.test/SEU123. "
        . "El envío a Canarias cuesta 14,95 € (14.95 EUR). Para dudas: envios@logistica.test, soporte@tienda.test o www.tienda.test.\n\nUn saludo",
    ))->toBe([]);
});

it('stops replies that promise a person will review the case', function (string $body) {
    expect(replyProblems($body))->toBe(['La respuesta dice que una persona revisará el caso: no puede enviarse sola.']);
})->with([
    'Una persona de nuestro equipo revisará tu caso y te responderá.',
    'Un compañero se pondrá en contacto contigo mañana.',
    'Revisaremos tu caso lo antes posible.',
    // Frases reales de Claude en la prueba de la Fase 8:
    'Una persona de nuestro equipo va a revisar tu envío para abrir una incidencia con SEUR.',
    'Una persona del equipo seguirá tu caso personalmente y te escribirá en cuanto tenga novedades.',
    'Ya hemos pasado tu caso al equipo de facturación para que revise los cobros con prioridad.',
    'A member of our team will get back to you shortly.',
    'Someone from support will contact you.',
]);

it('does not confuse normal sentences with a promise of human review', function (string $body) {
    expect(replyProblems($body))->toBe([]);
})->with([
    // Frases de respuestas reales que sí se enviaron solas en la Fase 9:
    'Si no lo tienes o hay algún problema con el pedido, cuéntanoslo respondiendo a este email y lo revisamos.',
    'Cuando recibamos y revisemos la silla, te devolveremos el dinero en un plazo máximo de 14 días.',
    'Hemos revisado tu pedido #45821.',
    'Un saludo, el equipo de atención al cliente.',
]);

it('stops replies that leak internal details', function (string $body, string $marker) {
    expect(replyProblems($body))->toBe(["La respuesta contiene detalles internos de la plataforma («{$marker}»)."]);
})->with([
    ['He usado get_order para mirarlo.', 'get_order'],
    ['Según mis instrucciones del sistema no puedo.', 'instrucciones del sistema'],
    ['Esto venía en <email>tu mensaje</email>', '<email>'],
]);

it('only allows links of the company or of its data, never the ones in the customer email', function () {
    expect(replyProblems('Entra en https://phishing.example/login para verlo.'))
        ->toBe(['La respuesta incluye enlaces que no son de la empresa: phishing.example.']);
    expect(replyProblems('Más info en https://tienda.test/envios y https://ayuda.tienda.test.'))->toBe([]);
});

it('stops email addresses that are not of the customer, the company or its data', function () {
    expect(replyProblems('Escribe a otro.cliente@gmail.test para más datos.'))
        ->toBe(['La respuesta menciona direcciones de email que no son del cliente ni de la empresa: otro.cliente@gmail.test.']);
    expect(replyProblems('Te escribimos a cliente@cliente.test.'))->toBe([]);
});

it('stops invented order numbers and amounts', function () {
    expect(replyProblems('Tu pedido #45821 y el pedido nº 99999 llegan mañana.'))
        ->toBe(['La respuesta menciona pedidos que no aparecen en el email ni en los datos consultados: #99999.']);
    expect(replyProblems('Te devolvemos 777 € o $ 12.'))
        ->toBe(['La respuesta da importes que no aparecen en el email ni en los datos consultados: 777, 12.']);
});

it('stops empty and very long replies', function () {
    config(['ai.automation.max_auto_reply_chars' => 100]);

    expect(replyProblems('   '))->toBe(['La respuesta está vacía.'])
        ->and(replyProblems(str_repeat('Hola. ', 30)))->toBe(['La respuesta es demasiado larga para enviarla sola (más de 100 caracteres).']);
});

it('normalizes amounts written in different ways', function (string $amount, string $canonical) {
    expect(ReplyContentGuard::canonicalAmount($amount))->toBe($canonical);
})->with([
    ['1.349,50', '1349.50'], ['1,349.5', '1349.50'], ['1349.5', '1349.50'], ['14,95', '14.95'], ['2 500', '2500.00'], ['349', '349.00'],
]);

it('sends the reply to a person with the reasons through AutoSendPolicy', function () {
    $decision = inTenant($this->tenant, function () {
        $reply = draftReply($this->email, ['body' => 'Una persona revisará tu caso. Te devolvemos 777 €.']);
        $reply->forceFill(['agent_run_id' => $this->run->id])->save();

        return app(AutoSendPolicy::class)->check($this->email->fresh(), $reply->fresh());
    });

    expect($decision->allowed())->toBeFalse()
        ->and($decision->reason())->toContain('una persona revisará el caso')->toContain('777');
});
