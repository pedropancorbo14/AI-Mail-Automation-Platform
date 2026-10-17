<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Contracts\LLMProvider;
use App\AI\Embeddings\EmbeddingProvider;
use App\Mail\Data\MailMessage;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\Email;
use App\Models\EmailStatusChange;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Support\GreenMail;

/*
 * La revisión humana de principio a fin contra un servidor de correo real
 * (GreenMail) y con la IA SIMULADA (AI_PROVIDER=simulated, sin coste): el
 * cliente escribe → mail:sync → ingesta → clasificación y agente simulados →
 * la empresa está en modo borrador → revisión humana → una persona decide por
 * la API del panel → SMTP → la respuesta llega al buzón del cliente.
 */

beforeEach(function () {
    config([
        'mail_connections.allow_private_hosts' => true,
        'ai.provider' => 'simulated',
        'ai.simulated.delay_ms' => 0,
        'ai.anthropic.api_key' => '',
    ]);
    app()->forgetInstance(LLMProvider::class);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider());
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']); // modo borrador por defecto
    $this->ana = User::factory()->for($this->tenant)->agent()->create(['name' => 'Ana']);
    $this->support = GreenMail::createAccount();
    $this->customer = GreenMail::createAccount();

    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->create([
        'name' => 'Soporte Acme',
        'email' => $this->support,
        'credentials' => GreenMail::credentials($this->support),
    ]));
    $connection->forceFill(['status' => MailConnectionStatus::Active])->save();

    $this->artisan('mail:sync')->assertSuccessful(); // primera pasada: punto de partida

    // El cliente pregunta por envíos: la IA simulada busca en la documentación (vacía) y no puede responder sola.
    $email = (new MimeEmail())
        ->from($this->customer)
        ->to($this->support)
        ->subject('¿Hacéis envíos a Canarias?')
        ->text('Hola, vivo en Las Palmas. ¿Hacéis envíos a Canarias?');
    $email->getHeaders()->addIdHeader('Message-ID', 'consulta-canarias@cliente.test');
    GreenMail::append($this->support, $email);

    $this->artisan('mail:sync')->assertSuccessful();
    $this->email = inTenant($this->tenant, fn () => Email::sole());
});

/** @return list<MailMessage> */
function reviewCustomerInbox(): array
{
    return GreenMail::provider(test()->customer)->messages('INBOX');
}

function historyOf(Email $email): array
{
    return inTenant(test()->tenant, fn () => $email->statusChanges()->with('user')->get()
        ->map(fn (EmailStatusChange $change) => $change->to_status->value . ($change->user ? " ({$change->user->name})" : ''))
        ->all());
}

it('waits for a person, who corrects the proposal of the ai and sends it in the same thread', function () {
    $draft = inTenant($this->tenant, fn () => $this->email->replies()->sole());

    expect($this->email->status)->toBe(EmailStatus::HumanReview)
        ->and($draft->status)->toBe(EmailReplyStatus::Draft)
        ->and(reviewCustomerInbox())->toBe([]);

    $this->actingAs($this->ana, 'sanctum')
        ->getJson('/api/v1/review-queue')
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->email->id)
        ->assertJsonPath('data.0.has_draft', true);

    $this->actingAs($this->ana, 'sanctum')
        ->postJson("/api/v1/emails/{$this->email->id}/replies/{$draft->id}/approve", [
            'body' => "Hola:\n\nSí, enviamos a Canarias: 14,95 € y de 5 a 7 días laborables.\n\nUn saludo,\nAna",
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.replies.0.original_body', $draft->body);

    $inbox = reviewCustomerInbox();

    expect($inbox)->toHaveCount(1)
        ->and($inbox[0]->subject)->toBe('Re: ¿Hacéis envíos a Canarias?')
        ->and($inbox[0]->from->name)->toBe('Soporte Acme')
        ->and($inbox[0]->inReplyTo)->toBe('consulta-canarias@cliente.test')
        ->and($inbox[0]->textBody)->toContain('14,95 € y de 5 a 7 días laborables')
        // La envió una persona: sin Auto-Submitted (no es una respuesta automática).
        ->and($inbox[0]->automatedReason)->toBeNull()
        ->and(historyOf($this->email))->toBe(['processing', 'ai_review', 'human_review', 'approved (Ana)', 'sent'])
        ->and(inTenant($this->tenant, fn () => $this->email->reviews()->sole()->edited))->toBeTrue();
})->group('greenmail');

it('lets the person discard the proposal and write the reply', function () {
    $draft = inTenant($this->tenant, fn () => $this->email->replies()->sole());

    $this->actingAs($this->ana, 'sanctum')
        ->postJson("/api/v1/emails/{$this->email->id}/replies/{$draft->id}/reject", ['reason' => 'No da el precio.'])
        ->assertOk();

    $this->actingAs($this->ana, 'sanctum')
        ->postJson("/api/v1/emails/{$this->email->id}/replies", ['body' => "Hola:\n\nTe llamo hoy para explicártelo.\n\nAna"])
        ->assertOk()
        ->assertJsonPath('data.replies.0.author.name', 'Ana');

    $inbox = reviewCustomerInbox();

    expect($inbox)->toHaveCount(1)
        ->and($inbox[0]->textBody)->toContain('Te llamo hoy para explicártelo.')
        ->and(inTenant($this->tenant, fn () => $this->email->reviews()->pluck('decision')->map->value->all()))->toBe(['rejected', 'replied']);
})->group('greenmail');

it('sends the email back to the simulated ai, which prepares a new proposal', function () {
    $old = inTenant($this->tenant, fn () => $this->email->replies()->sole());

    $this->actingAs($this->ana, 'sanctum')
        ->postJson("/api/v1/emails/{$this->email->id}/reprocess")
        ->assertOk();

    // Cola sync: el reproceso ya ha terminado (vuelve a revisión humana con una propuesta nueva).
    $replies = inTenant($this->tenant, fn () => $this->email->replies()->get());

    expect($this->email->fresh()->status)->toBe(EmailStatus::HumanReview)
        ->and($replies)->toHaveCount(2)
        ->and($replies[0]->status)->toBe(EmailReplyStatus::Draft)
        ->and($replies[1]->id)->toBe($old->id)
        ->and($replies[1]->status)->toBe(EmailReplyStatus::Rejected)
        ->and(historyOf($this->email))->toContain('processing (Ana)')
        ->and(reviewCustomerInbox())->toBe([]);
})->group('greenmail');
