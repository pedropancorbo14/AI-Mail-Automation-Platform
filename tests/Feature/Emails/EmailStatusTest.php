<?php

use App\Mail\Enums\EmailStatus;
use App\Mail\Exceptions\EmailStatusException;
use App\Models\Email;
use App\Models\EmailStatusChange;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create());
});

it('changes the status and records why', function () {
    inTenant($this->tenant, fn () => $this->email->transitionTo(EmailStatus::Processing, 'Procesamiento automático'));

    $change = inTenant($this->tenant, fn () => $this->email->statusChanges()->sole());

    expect($this->email->status)->toBe(EmailStatus::Processing)
        ->and($this->email->isDirty('status'))->toBeFalse()
        ->and($this->email->fresh()->status)->toBe(EmailStatus::Processing)
        ->and($change->tenant_id)->toBe($this->tenant->id)
        ->and($change->from_status)->toBe(EmailStatus::Received)
        ->and($change->to_status)->toBe(EmailStatus::Processing)
        ->and($change->reason)->toBe('Procesamiento automático')
        ->and($change->user_id)->toBeNull()
        ->and($change->created_at)->not->toBeNull();
});

it('records who made a human decision', function () {
    $user = User::factory()->for($this->tenant)->create();

    inTenant($this->tenant, function () use ($user) {
        $this->email->transitionTo(EmailStatus::Processing);
        $this->email->transitionTo(EmailStatus::HumanReview, 'Confianza baja');
        $this->email->transitionTo(EmailStatus::Approved, 'Aprobada tras revisar', $user);
    });

    $history = inTenant($this->tenant, fn () => $this->email->statusChanges()->get());

    expect($history->map(fn (EmailStatusChange $change) => $change->to_status)->all())
        ->toBe([EmailStatus::Processing, EmailStatus::HumanReview, EmailStatus::Approved])
        ->and($history->last()->user->is($user))->toBeTrue();
});

it('follows the whole automatic path', function () {
    inTenant($this->tenant, function () {
        foreach ([EmailStatus::Processing, EmailStatus::AiReview, EmailStatus::Approved, EmailStatus::Sent] as $status) {
            $this->email->transitionTo($status);
        }
    });

    expect($this->email->fresh()->status)->toBe(EmailStatus::Sent)
        ->and(inTenant($this->tenant, fn () => $this->email->statusChanges()->count()))->toBe(4);
});

it('refuses steps that skip the rules', function (EmailStatus $from, EmailStatus $to) {
    $email = inTenant($this->tenant, fn () => Email::factory()->create(['status' => $from]));

    expect(fn () => inTenant($this->tenant, fn () => $email->transitionTo($to)))
        ->toThrow(EmailStatusException::class, "no puede pasar de {$from->value} a {$to->value}");

    expect($email->fresh()->status)->toBe($from)
        ->and(inTenant($this->tenant, fn () => $email->statusChanges()->count()))->toBe(0);
})->with([
    'enviar sin aprobar' => [EmailStatus::Processing, EmailStatus::Sent],
    'aprobar sin procesar' => [EmailStatus::Received, EmailStatus::Approved],
    'reabrir uno enviado' => [EmailStatus::Sent, EmailStatus::Processing],
    'aprobar sin la IA ni una persona' => [EmailStatus::Processing, EmailStatus::Approved],
]);

it('does not overwrite a change made by another process', function () {
    $mine = $this->email;
    $theirs = inTenant($this->tenant, fn () => Email::find($this->email->id));

    inTenant($this->tenant, fn () => $theirs->transitionTo(EmailStatus::Processing));

    expect(fn () => inTenant($this->tenant, fn () => $mine->transitionTo(EmailStatus::Ignored)))
        ->toThrow(EmailStatusException::class, 'otro proceso');

    expect($this->email->fresh()->status)->toBe(EmailStatus::Processing)
        ->and(inTenant($this->tenant, fn () => $this->email->statusChanges()->count()))->toBe(1);
});

it('keeps the status catalog consistent', function () {
    foreach (EmailStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty();

        // Todo estado salvo el inicial es alcanzable desde otro.
        if ($status !== EmailStatus::Received) {
            expect(collect(EmailStatus::cases())->contains(fn (EmailStatus $from) => $from->canTransitionTo($status)))->toBeTrue();
        }
    }

    expect(EmailStatus::Sent->allowedNext())->toBe([]);
});

it('keeps the history inside the company', function () {
    $other = Tenant::factory()->create();
    inTenant($this->tenant, fn () => $this->email->transitionTo(EmailStatus::Processing));

    expect(inTenant($other, fn () => EmailStatusChange::count()))->toBe(0)
        ->and(inTenant($this->tenant, fn () => EmailStatusChange::count()))->toBe(1);
});
