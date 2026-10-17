<?php

use App\Automation\Review\ReviewDecision;
use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Models\EmailReview;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->ana = User::factory()->for($this->tenant)->agent()->create(['name' => 'Ana']);
    $this->bea = User::factory()->for($this->tenant)->agent()->create(['name' => 'Bea']);
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create([
        'subject' => 'Pedido 45821', 'from_email' => 'cliente@cliente.test', 'intent' => 'order_status',
    ]));
});

function decision(User $user, ReviewDecision $decision, ?int $waited = 60, bool $edited = false, int $daysAgo = 0, ?Tenant $tenant = null, ?Email $email = null): EmailReview
{
    $tenant ??= test()->tenant;

    return inTenant($tenant, function () use ($user, $decision, $waited, $edited, $daysAgo, $email) {
        $review = ($email ?? test()->email)->reviews()->create([
            'user_id' => $user->id, 'decision' => $decision, 'edited' => $edited, 'waited_seconds' => $waited,
        ]);
        $review->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $review;
    });
}

function history(string $uri): Illuminate\Testing\TestResponse
{
    Sanctum::actingAs(test()->ana);

    return test()->getJson($uri);
}

it('lists the decisions of the people, newest first, with their email', function () {
    decision($this->ana, ReviewDecision::Rejected, waited: 30);
    decision($this->bea, ReviewDecision::Approved, waited: 90, edited: true);

    $response = history('/api/v1/reviews')->assertOk();

    expect($response->json('data.*.decision'))->toBe(['approved', 'rejected'])
        ->and($response->json('data.0'))->toMatchArray([
            'decision_label' => 'Aprobada',
            'edited' => true,
            'waited_seconds' => 90,
            'user' => ['id' => $this->bea->id, 'name' => 'Bea'],
        ])
        ->and($response->json('data.0.email'))->toBe([
            'id' => $this->email->id,
            'subject' => 'Pedido 45821',
            'from' => ['email' => 'cliente@cliente.test', 'name' => $this->email->from_name],
            'intent' => 'order_status',
            'status' => EmailStatus::Received->value,
        ]);
});

it('filters the history by decision, person and dates', function () {
    decision($this->ana, ReviewDecision::Approved);
    decision($this->bea, ReviewDecision::Closed);
    decision($this->ana, ReviewDecision::Closed, daysAgo: 10);

    expect(history('/api/v1/reviews?decision=closed')->json('data'))->toHaveCount(2)
        ->and(history("/api/v1/reviews?user={$this->bea->id}")->json('data.*.decision'))->toBe(['closed'])
        ->and(history('/api/v1/reviews?from=' . now()->subDays(2)->toDateString())->json('data'))->toHaveCount(2)
        ->and(history('/api/v1/reviews?to=' . now()->subDays(5)->toDateString())->json('data'))->toHaveCount(1);

    history('/api/v1/reviews?decision=borrada')->assertUnprocessable()->assertJsonValidationErrors('decision');
});

it('summarises the period: decisions, waiting time and what happens to the ai proposals', function () {
    decision($this->ana, ReviewDecision::Approved, waited: 60);
    decision($this->ana, ReviewDecision::Approved, waited: 120, edited: true);
    decision($this->bea, ReviewDecision::Approved, waited: 600);
    decision($this->bea, ReviewDecision::Rejected, waited: 5000);
    decision($this->bea, ReviewDecision::Closed, waited: 300);
    decision($this->ana, ReviewDecision::Approved, waited: 9999, daysAgo: 40);
    inTenant($this->tenant, fn () => Email::factory()->create(['status' => EmailStatus::HumanReview]));

    $summary = history('/api/v1/reviews/summary?days=30')->assertOk()->json('data');

    expect($summary['days'])->toBe(30)
        ->and($summary['pending'])->toBe(1)
        ->and($summary['decisions'])->toBe(['approved' => 3, 'rejected' => 1, 'replied' => 0, 'closed' => 1, 'reprocessed' => 0])
        ->and($summary['resolved'])->toBe(4)
        // Esperas de las decisiones que resuelven el caso: 60, 120, 300, 600 (el descarte no cuenta).
        ->and($summary['wait_seconds'])->toBe(['average' => 270, 'median' => 210])
        ->and($summary['ai_proposals'])->toBe([
            'reviewed' => 4, 'approved_unchanged' => 2, 'approved_edited' => 1, 'rejected' => 1, 'unchanged_rate' => 0.5,
        ])
        ->and($summary['people'][0])->toBe([
            'user' => ['id' => $this->bea->id, 'name' => 'Bea'],
            'total' => 3,
            'decisions' => ['approved' => 1, 'rejected' => 1, 'replied' => 0, 'closed' => 1, 'reprocessed' => 0],
            'edited' => 0,
            'average_wait_seconds' => 450,
        ])
        ->and($summary['people'][1]['user']['name'])->toBe('Ana')
        ->and($summary['people'][1]['edited'])->toBe(1);

    expect(history('/api/v1/reviews/summary?days=90')->json('data.decisions.approved'))->toBe(4);
    history('/api/v1/reviews/summary?days=12')->assertUnprocessable();
});

it('summarises an empty period without dividing by zero', function () {
    $summary = history('/api/v1/reviews/summary')->assertOk()->json('data');

    expect($summary['days'])->toBe(30)
        ->and($summary['resolved'])->toBe(0)
        ->and($summary['wait_seconds'])->toBe(['average' => null, 'median' => null])
        ->and($summary['ai_proposals']['unchanged_rate'])->toBeNull()
        ->and($summary['people'])->toBe([]);
});

it('never shows the decisions of another company', function () {
    $other = Tenant::factory()->create();
    $boss = User::factory()->for($other)->owner()->create();
    $foreign = inTenant($other, fn () => Email::factory()->create());
    decision($boss, ReviewDecision::Approved, tenant: $other, email: $foreign);

    history('/api/v1/reviews')->assertOk()->assertJsonPath('data', []);
    expect(history('/api/v1/reviews/summary')->json('data.decisions.approved'))->toBe(0)
        ->and(history('/api/v1/reviews/summary')->json('data.people'))->toBe([]);
});

it('requires being logged in', function () {
    $this->getJson('/api/v1/reviews')->assertUnauthorized();
    $this->getJson('/api/v1/reviews/summary')->assertUnauthorized();
});
