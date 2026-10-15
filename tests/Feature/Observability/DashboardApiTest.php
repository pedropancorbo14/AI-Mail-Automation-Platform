<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Classification\EmailIntent;
use App\Mail\Enums\EmailStatus;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo('2026-10-14 12:00:00');
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->for($this->tenant)->create();
});

/** Email de la empresa recibido hace $daysAgo días. */
function dashboardEmail(EmailStatus $status, ?EmailIntent $intent = null, int $daysAgo = 1, ?Tenant $tenant = null): Email
{
    return inTenant($tenant ?? test()->tenant, function () use ($status, $intent, $daysAgo) {
        $email = Email::factory()->create(['status' => $status]);
        $email->forceFill(['intent' => $intent, 'created_at' => now()->subDays($daysAgo)])->save();

        return $email;
    });
}

function aiCall(float $cost, ?Email $email = null, int $daysAgo = 1, string $status = 'success', int $latency = 2000, string $purpose = 'email_agent', ?Tenant $tenant = null): void
{
    inTenant($tenant ?? test()->tenant, function () use ($cost, $email, $daysAgo, $status, $latency, $purpose) {
        $request = AiRequest::create([
            'email_id' => $email?->id, 'purpose' => $purpose, 'provider' => 'anthropic', 'requested_model' => 'claude-opus-5-5',
            'model' => 'claude-opus-5-5', 'cost_usd' => $cost, 'latency_ms' => $latency, 'status' => $status,
        ]);
        $request->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    });
}

function dashboard(array $query = []): Illuminate\Testing\TestResponse
{
    Sanctum::actingAs(test()->user);

    return test()->getJson('/api/v1/dashboard?' . http_build_query($query))->assertOk();
}

it('summarizes the emails of the period and what the automation did', function () {
    $sent = dashboardEmail(EmailStatus::Sent, EmailIntent::OrderStatus);
    inTenant($this->tenant, fn () => draftReply($sent)->forceFill([
        'status' => EmailReplyStatus::Sent, 'sent_automatically' => true, 'sent_to' => 'a@b.test', 'sent_at' => now()->subDay(),
    ])->save());
    dashboardEmail(EmailStatus::HumanReview, EmailIntent::OrderStatus);
    dashboardEmail(EmailStatus::HumanReview, EmailIntent::Complaint);
    dashboardEmail(EmailStatus::Ignored, EmailIntent::Spam);
    dashboardEmail(EmailStatus::HumanReview, EmailIntent::ReturnRequest, daysAgo: 40); // fuera del periodo

    $data = dashboard()->json('data');

    expect($data['period']['days'])->toBe(30)
        ->and($data['emails']['received'])->toBe(4)
        ->and($data['emails']['by_status']['human_review'])->toBe(2)
        ->and($data['emails']['by_status']['sent'])->toBe(1)
        ->and($data['emails']['by_status']['failed'])->toBe(0)
        ->and($data['emails']['sent_automatically'])->toBe(1)
        // 1 enviado solo de 3 que necesitaban respuesta (el spam no cuenta).
        ->and($data['emails']['automation_rate'])->toBe(0.333)
        // Lo pendiente cuenta aunque sea antiguo.
        ->and($data['emails']['awaiting_human'])->toBe(3)
        ->and($data['intents'])->toBe([
            ['intent' => 'order_status', 'count' => 2],
            ['intent' => 'complaint', 'count' => 1],
            ['intent' => 'spam', 'count' => 1],
        ]);
});

it('counts the ai requests and latency, never costs or tokens', function () {
    $email = dashboardEmail(EmailStatus::Sent);
    $other = dashboardEmail(EmailStatus::HumanReview);
    aiCall(0.012, $email, daysAgo: 0, latency: 3000, purpose: 'email_classification');
    aiCall(0.048, $email, daysAgo: 0, latency: 9000);
    aiCall(0.030, $other, daysAgo: 3, status: 'failed', latency: 6000);
    aiCall(0.0, null, daysAgo: 0, latency: 500, purpose: 'knowledge_search'); // embeddings locales: no cuentan para la latencia
    aiCall(1.0, $email, daysAgo: 45); // fuera del periodo y del mes

    $ai = dashboard()->json('data.ai');

    expect($ai['requests'])->toBe(4)
        ->and($ai['failed_requests'])->toBe(1)
        ->and($ai['avg_latency_ms'])->toBe(6000)
        // Costes y tokens: solo para el operador (Grafana), nunca para los clientes.
        ->and(costOrTokenKeys(dashboard()->json('data')))->toBe([]);
});

it('lists the latest emails waiting for a person', function () {
    $review = dashboardEmail(EmailStatus::HumanReview);
    $failed = dashboardEmail(EmailStatus::Failed);
    dashboardEmail(EmailStatus::Sent);

    expect(dashboard()->json('data.needs_attention.*.id'))->toBe([$failed->id, $review->id]);
});

it('changes the period', function () {
    dashboardEmail(EmailStatus::Sent, daysAgo: 1);
    dashboardEmail(EmailStatus::Sent, daysAgo: 20);
    dashboardEmail(EmailStatus::Sent, daysAgo: 60);

    expect(dashboard(['days' => 7])->json('data.emails.received'))->toBe(1)
        ->and(dashboard(['days' => 90])->json('data.emails.received'))->toBe(3);

    Sanctum::actingAs($this->user);
    $this->getJson('/api/v1/dashboard?days=365')->assertUnprocessable()->assertJsonValidationErrors('days');
});

it('works for a company without data', function () {
    expect(dashboard()->json('data'))
        ->emails->received->toBe(0)
        ->emails->automation_rate->toBeNull()
        ->ai->avg_latency_ms->toBeNull()
        ->intents->toBe([])
        ->needs_attention->toBe([]);
});

it('only counts the company own data', function () {
    $other = Tenant::factory()->create();
    $theirs = dashboardEmail(EmailStatus::HumanReview, EmailIntent::Spam, tenant: $other);
    aiCall(5.0, $theirs, tenant: $other);

    expect(dashboard()->json('data'))
        ->emails->received->toBe(0)
        ->emails->awaiting_human->toBe(0)
        ->ai->requests->toBe(0)
        ->needs_attention->toBe([]);
});

it('requires the emails.view permission', function () {
    $this->getJson('/api/v1/dashboard')->assertUnauthorized();
});
