<?php

use App\Automation\Review\HumanReviewService;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => new FakeMailProvider());
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->ana = User::factory()->for($this->tenant)->agent()->create(['name' => 'Ana']);
});

function waitingEmail(int $minutesAgo): array
{
    return inTenant(test()->tenant, function () use ($minutesAgo) {
        test()->travel(-$minutesAgo)->minutes();
        $email = Email::factory()->create([
            'mail_connection_id' => MailConnection::factory()->create(['status' => MailConnectionStatus::Active]),
            'from_email' => 'cliente@cliente.test',
            'status' => EmailStatus::AiReview,
        ]);
        $reply = draftReply($email);
        $email->transitionTo(EmailStatus::HumanReview, 'Confianza baja.');
        test()->travelBack();

        return [$email, $reply];
    });
}

it('counts each decision of a person and how long the case waited', function () {
    [$email, $reply] = waitingEmail(30);
    [$other, $otherReply] = waitingEmail(5);

    inTenant($this->tenant, function () use ($reply, $other, $otherReply) {
        app(HumanReviewService::class)->approve($reply, $this->ana, 'Texto corregido por Ana');
        app(HumanReviewService::class)->reject($otherReply, $this->ana);
        app(HumanReviewService::class)->close($other, $this->ana);
    });

    expect(metricSample('email_reviews_total', ['tenant' => 'acme', 'decision' => 'approved', 'edited' => true]))->toBe(1.0)
        ->and(metricSample('email_reviews_total', ['tenant' => 'acme', 'decision' => 'rejected', 'edited' => false]))->toBe(1.0)
        ->and(metricSample('email_reviews_total', ['tenant' => 'acme', 'decision' => 'closed']))->toBe(1.0)
        ->and(metricSample('email_review_wait_seconds_count', ['tenant' => 'acme', 'decision' => 'approved']))->toBe(1.0)
        // 30 min de espera: dentro del intervalo de 1 h, fuera del de 15 min.
        ->and(metricSample('email_review_wait_seconds_bucket', ['decision' => 'approved', 'le' => '3600']))->toBe(1.0)
        ->and(metricSample('email_review_wait_seconds_bucket', ['decision' => 'approved', 'le' => '900']))->toBe(0.0);
});

it('does not count a decision that was rolled back', function () {
    [$email] = waitingEmail(10);

    DB::beginTransaction();
    inTenant($this->tenant, fn () => app(HumanReviewService::class)->close($email, $this->ana));
    DB::rollBack();

    expect(metricSample('email_reviews_total', ['decision' => 'closed']))->toBeNull();
});

it('publishes how long the oldest case has been waiting in each company', function () {
    waitingEmail(90);
    waitingEmail(10);
    $globex = Tenant::factory()->create(['slug' => 'globex']);
    inTenant($globex, fn () => Email::factory()->create(['status' => EmailStatus::Sent]));

    $oldest = metricSample('email_review_oldest_waiting_seconds', ['tenant' => 'acme']);

    expect($oldest)->toBeGreaterThanOrEqual(90 * 60.0)
        ->and($oldest)->toBeLessThan(91 * 60.0)
        ->and(metricSample('email_review_oldest_waiting_seconds', ['tenant' => 'globex']))->toBeNull();
});
