<?php

use App\Automation\Review\ReviewDecision;
use App\Models\Email;
use App\Models\EmailReview;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Exceptions\MissingTenantException;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->for($this->tenant)->create();
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create());
});

it('adds the review columns', function () {
    expect(Schema::hasColumns('email_replies', ['created_by', 'original_body', 'edited_by', 'edited_at', 'rejected_by', 'rejected_at', 'rejection_reason']))->toBeTrue()
        ->and(Schema::hasColumns('emails', ['assigned_to', 'assigned_at']))->toBeTrue()
        ->and(Schema::hasColumns('email_reviews', ['tenant_id', 'email_id', 'email_reply_id', 'user_id', 'decision', 'edited', 'reason', 'waited_seconds', 'created_at']))->toBeTrue();
});

it('stores a review decision linked to the email, the reply and the person', function () {
    $review = inTenant($this->tenant, function () {
        $reply = draftReply($this->email);

        return $this->email->reviews()->create([
            'email_reply_id' => $reply->id,
            'user_id' => $this->user->id,
            'decision' => ReviewDecision::Approved,
            'edited' => true,
            'waited_seconds' => 120,
        ]);
    });

    $review = inTenant($this->tenant, fn () => EmailReview::with('email', 'reply', 'user')->find($review->id));

    expect($review->decision)->toBe(ReviewDecision::Approved)
        ->and($review->edited)->toBeTrue()
        ->and($review->tenant_id)->toBe($this->tenant->id)
        ->and($review->email->is($this->email))->toBeTrue()
        ->and($review->user->is($this->user))->toBeTrue()
        ->and($review->reply)->not->toBeNull()
        ->and($review->updated_at)->toBeNull();
});

it('keeps reviews inside their tenant and fails closed without one', function () {
    inTenant($this->tenant, fn () => $this->email->reviews()->create(['user_id' => $this->user->id, 'decision' => ReviewDecision::Closed]));
    $other = Tenant::factory()->create();

    expect(inTenant($other, fn () => EmailReview::count()))->toBe(0)
        ->and(inTenant($this->tenant, fn () => EmailReview::count()))->toBe(1)
        ->and(fn () => EmailReview::count())->toThrow(MissingTenantException::class);
});

it('deletes the reviews with the email', function () {
    inTenant($this->tenant, function () {
        $this->email->reviews()->create(['user_id' => $this->user->id, 'decision' => ReviewDecision::Closed]);
        $this->email->delete();
    });

    expect(inTenant($this->tenant, fn () => EmailReview::count()))->toBe(0);
});

it('knows who wrote, edited and rejected a reply', function () {
    $reply = inTenant($this->tenant, function () {
        $reply = draftReply($this->email);
        $reply->forceFill([
            'created_by' => $this->user->id,
            'original_body' => 'Texto de la IA',
            'edited_by' => $this->user->id,
            'edited_at' => now(),
            'rejected_by' => $this->user->id,
            'rejected_at' => now(),
        ])->save();

        return $reply->fresh(['author', 'editedBy', 'rejectedBy']);
    });

    expect($reply->wasEdited())->toBeTrue()
        ->and($reply->author->is($this->user))->toBeTrue()
        ->and($reply->editedBy->is($this->user))->toBeTrue()
        ->and($reply->rejectedBy->is($this->user))->toBeTrue()
        ->and(inTenant($this->tenant, fn () => draftReply($this->email))->wasEdited())->toBeFalse();
});

it('does not mass assign the assignment of an email', function () {
    $email = inTenant($this->tenant, fn () => tap($this->email)->update(['assigned_to' => $this->user->id])->fresh());

    expect($email->assigned_to)->toBeNull();

    inTenant($this->tenant, fn () => $this->email->forceFill(['assigned_to' => $this->user->id, 'assigned_at' => now()])->save());

    expect($this->email->fresh()->assignee->is($this->user))->toBeTrue();
});

it('labels each decision', function () {
    expect(ReviewDecision::Approved->label())->toBe('Aprobada')
        ->and(ReviewDecision::Approved->sendsReply())->toBeTrue()
        ->and(ReviewDecision::Replied->sendsReply())->toBeTrue()
        ->and(ReviewDecision::Rejected->sendsReply())->toBeFalse();
});
