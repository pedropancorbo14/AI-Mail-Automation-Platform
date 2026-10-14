<?php

use App\AI\Classification\EmailIntent;
use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->for($this->tenant)->create(); // rol agente
    [$this->support, $this->sales] = inTenant($this->tenant, fn () => [
        MailConnection::factory()->create(['name' => 'Soporte', 'email' => 'soporte@acme.test']),
        MailConnection::factory()->create(['name' => 'Ventas', 'email' => 'ventas@acme.test']),
    ]);
});

/** Email de la empresa del test con los datos indicados. */
function listedEmail(array $attributes = []): Email
{
    return inTenant(test()->tenant, fn () => Email::factory()->create(array_merge([
        'mail_connection_id' => test()->support->id,
    ], $attributes)));
}

function listEmails(array $query = []): Illuminate\Testing\TestResponse
{
    Sanctum::actingAs(test()->user);

    return test()->getJson('/api/v1/emails?' . http_build_query($query));
}

it('lists the company emails, newest first, without their bodies', function () {
    $old = listedEmail(['subject' => 'Primero', 'from_email' => 'ana@cliente.test', 'from_name' => 'Ana']);
    $new = listedEmail(['subject' => 'Segundo', 'status' => EmailStatus::HumanReview]);
    inTenant($this->tenant, fn () => $new->forceFill([
        'intent' => EmailIntent::OrderStatus, 'intent_confidence' => 0.92, 'summary' => 'Pregunta por su pedido.', 'urgency' => 'high',
    ])->save());

    $response = listEmails()->assertOk();

    expect($response->json('data.*.id'))->toBe([$new->id, $old->id]);
    $response->assertJsonPath('data.0.status', 'human_review')
        ->assertJsonPath('data.0.status_label', 'Revisión humana')
        ->assertJsonPath('data.0.intent', 'order_status')
        ->assertJsonPath('data.0.intent_confidence', 0.92)
        ->assertJsonPath('data.0.summary', 'Pregunta por su pedido.')
        ->assertJsonPath('data.0.mailbox.name', 'Soporte')
        ->assertJsonPath('data.0.attachments_count', 0)
        ->assertJsonPath('data.1.from', ['email' => 'ana@cliente.test', 'name' => 'Ana'])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonMissingPath('data.0.text_body')
        ->assertJsonMissingPath('data.0.html_body');
});

it('filters by status, intent and mailbox', function () {
    $target = listedEmail(['status' => EmailStatus::HumanReview, 'mail_connection_id' => $this->sales->id]);
    inTenant($this->tenant, fn () => $target->forceFill(['intent' => EmailIntent::ReturnRequest])->save());
    listedEmail(['status' => EmailStatus::HumanReview]);
    listedEmail(['status' => EmailStatus::Sent, 'mail_connection_id' => $this->sales->id]);

    expect(listEmails(['status' => 'human_review'])->json('meta.total'))->toBe(2)
        ->and(listEmails(['intent' => 'return_request'])->json('data.*.id'))->toBe([$target->id])
        ->and(listEmails(['mailbox' => $this->sales->id, 'status' => 'human_review'])->json('data.*.id'))->toBe([$target->id]);
});

it('searches the subject and the sender, without caring about case', function (string $text) {
    $match = listedEmail(['subject' => 'Pedido 45821 sin llegar', 'from_email' => 'marcos@cliente.test', 'from_name' => 'Marcos Vidal']);
    listedEmail(['subject' => 'Otra cosa', 'from_email' => 'ana@cliente.test', 'from_name' => 'Ana']);

    expect(listEmails(['q' => $text])->json('data.*.id'))->toBe([$match->id]);
})->with(['pedido 45821', 'MARCOS@cliente', 'vidal']);

it('treats % and _ in the search as plain text', function () {
    $match = listedEmail(['subject' => 'Descuento del 50% hoy']);
    listedEmail(['subject' => 'Descuento del 5 hoy']);

    expect(listEmails(['q' => '50%'])->json('data.*.id'))->toBe([$match->id])
        ->and(listEmails(['q' => '%'])->json('data.*.id'))->toBe([$match->id]);
});

it('filters by the day it arrived', function () {
    $this->travelTo('2026-10-01 10:00');
    listedEmail();
    $this->travelTo('2026-10-05 23:30');
    $inRange = listedEmail();
    $this->travelTo('2026-10-08 09:00');
    listedEmail();

    expect(listEmails(['from' => '2026-10-02', 'to' => '2026-10-05'])->json('data.*.id'))->toBe([$inRange->id]);
});

it('paginates', function () {
    foreach (range(1, 3) as $_) {
        listedEmail();
    }

    listEmails(['per_page' => 2, 'page' => 2])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.last_page', 2);
});

it('rejects invalid filters', function (array $query, string $field) {
    listEmails($query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'estado desconocido' => [['status' => 'borrado'], 'status'],
    'intención desconocida' => [['intent' => 'reembolsar_todo'], 'intent'],
    'demasiados por página' => [['per_page' => 500], 'per_page'],
    'fechas al revés' => [['from' => '2026-10-05', 'to' => '2026-10-01'], 'to'],
]);

it('never shows emails of another company, not even through its mailbox id', function () {
    listedEmail(['subject' => 'Nuestro']);
    $other = Tenant::factory()->create();
    $theirMailbox = inTenant($other, fn () => MailConnection::factory()->create());
    inTenant($other, fn () => Email::factory()->create(['subject' => 'De Globex', 'mail_connection_id' => $theirMailbox->id]));

    expect(listEmails()->json('data.*.subject'))->toBe(['Nuestro'])
        ->and(listEmails(['mailbox' => $theirMailbox->id])->json('data'))->toBe([])
        ->and(listEmails(['q' => 'Globex'])->json('data'))->toBe([]);
});

it('requires a session and the emails.view permission', function () {
    $this->getJson('/api/v1/emails')->assertUnauthorized();

    Role::where('name', Role::AGENT)->sole()->permissions()->detach(Permission::where('name', Permission::EMAILS_VIEW)->sole());

    listEmails()->assertForbidden();
});
