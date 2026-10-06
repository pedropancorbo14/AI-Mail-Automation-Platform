<?php

use App\AI\Classification\EmailClassification;
use App\AI\Classification\EmailIntent;
use App\Mail\Enums\EmailStatus;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create());
});

it('adds the classification columns to emails', function () {
    expect(Schema::hasColumns('emails', [
        'intent', 'intent_confidence', 'language', 'summary', 'needs_human', 'needs_human_reason',
        'sentiment', 'urgency', 'injection_suspected', 'ai_entities', 'classification_ai_request_id', 'classified_at',
    ]))->toBeTrue();
});

it('starts unclassified', function () {
    $email = inTenant($this->tenant, fn () => $this->email->fresh());

    expect($email->intent)->toBeNull()
        ->and($email->classified_at)->toBeNull()
        ->and($email->injection_suspected)->toBeFalse();
});

it('stores the classification as the current one without touching the status', function () {
    $this->freezeSecond();

    $email = inTenant($this->tenant, function () {
        $record = AiRequest::create([
            'email_id' => $this->email->id, 'purpose' => 'email_classification', 'provider' => 'anthropic',
            'requested_model' => 'claude-opus-5-5', 'status' => 'success',
        ]);
        $this->email->recordClassification(EmailClassification::fromArray(classificationData(), aiRequestId: $record->id));

        return $this->email->fresh()->load('classificationRequest', 'aiRequests');
    });

    expect($email->intent)->toBe(EmailIntent::OrderStatus)
        ->and($email->intent_confidence)->toBe('0.920')
        ->and($email->language)->toBe('es')
        ->and($email->summary)->toBe('El cliente pregunta dónde está su pedido #45821.')
        ->and($email->needs_human)->toBeFalse()
        ->and($email->sentiment)->toBe('neutral')
        ->and($email->urgency)->toBe('normal')
        ->and($email->ai_entities)->toEqualCanonicalizing(['order_numbers' => ['45821'], 'invoice_numbers' => [], 'customer_name' => 'Cliente Pérez'])
        ->and($email->classified_at->eq(now()))->toBeTrue()
        ->and($email->classificationRequest->purpose)->toBe('email_classification')
        ->and($email->aiRequests)->toHaveCount(1)
        ->and($email->status)->toBe(EmailStatus::Received);
});

it('replaces the previous classification when classified again', function () {
    inTenant($this->tenant, function () {
        $this->email->recordClassification(EmailClassification::fromArray(classificationData()));
        $this->email->recordClassification(EmailClassification::fromArray(classificationData([
            'intent' => 'complaint', 'needs_human' => true, 'needs_human_reason' => 'Queja grave.',
        ])));
    });

    $email = inTenant($this->tenant, fn () => $this->email->fresh());

    expect($email->intent)->toBe(EmailIntent::Complaint)
        ->and($email->needs_human)->toBeTrue()
        ->and($email->needs_human_reason)->toBe('Queja grave.');
});

it('does not mass assign the classification', function () {
    $email = new Email(['intent' => 'spam', 'needs_human' => false, 'subject' => 'Hola']);

    expect($email->intent)->toBeNull()
        ->and($email->needs_human)->toBeNull();
});
