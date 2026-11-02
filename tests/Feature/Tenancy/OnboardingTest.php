<?php

use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\Document;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;

/* Primeros pasos de una empresa nueva (Fase 17, tarea 7). */

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->for($this->tenant)->owner()->create();
});

function onboardingSteps(): array
{
    return collect(test()->actingAs(test()->owner, 'sanctum')->getJson('/api/v1/onboarding')->assertOk()->json('data.steps'))
        ->mapWithKeys(fn (array $step) => [$step['key'] => $step['done']])
        ->all();
}

it('starts with nothing done', function () {
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/onboarding')
        ->assertJsonPath('data.completed', 0)
        ->assertJsonPath('data.dismissed', false)
        ->assertJsonPath('data.steps.0.route', 'mail-connections');

    expect(onboardingSteps())->toBe(['mailbox' => false, 'documents' => false, 'ai_settings' => false, 'team' => false, 'first_email' => false]);
});

it('marks each step from what the company already did', function () {
    $mailbox = inTenant($this->tenant, fn () => MailConnection::factory()->create(['status' => MailConnectionStatus::Pending]));
    expect(onboardingSteps()['mailbox'])->toBeFalse();

    inTenant($this->tenant, function () use ($mailbox) {
        $mailbox->forceFill(['status' => MailConnectionStatus::Active])->save();
        Document::factory()->create()->markReady(3, 'bge-m3');
        $email = Email::factory()->create(['mail_connection_id' => $mailbox->id]);
        $email->transitionTo(EmailStatus::Processing, 'prueba');
        $email->transitionTo(EmailStatus::HumanReview, 'prueba');
    });
    User::factory()->for($this->tenant)->agent()->create();
    $this->actingAs($this->owner, 'sanctum')->patchJson('/api/v1/automation', ['mode' => 'auto'])->assertOk();

    expect(onboardingSteps())->toBe(['mailbox' => true, 'documents' => true, 'ai_settings' => true, 'team' => true, 'first_email' => true]);
});

it('only counts the own company', function () {
    $other = Tenant::factory()->create();
    inTenant($other, fn () => MailConnection::factory()->create(['status' => MailConnectionStatus::Active]));
    User::factory()->for($other)->count(3)->create();

    expect(onboardingSteps()['mailbox'])->toBeFalse()
        ->and(onboardingSteps()['team'])->toBeFalse();
});

it('can be hidden by whoever sets up the platform', function () {
    $agent = User::factory()->for($this->tenant)->agent()->create();
    $this->actingAs($agent, 'sanctum')->postJson('/api/v1/onboarding/dismiss')->assertForbidden();

    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/onboarding/dismiss')
        ->assertOk()
        ->assertJsonPath('data.dismissed', true);
    $this->actingAs($agent->fresh(), 'sanctum')->getJson('/api/v1/onboarding')->assertJsonPath('data.dismissed', true);
});
