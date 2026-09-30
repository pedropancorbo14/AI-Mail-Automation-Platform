<?php

use App\Mail\Data\MailMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\GreenMail;

beforeEach(function () {
    $this->email = GreenMail::createAccount();
});

function arrive(string $account, string $subject, string $folder = 'INBOX'): void
{
    GreenMail::append($account, (new Email())->from('cliente@cliente.test')->to($account)->subject($subject)->text('x'), $folder);
}

/** @param  list<MailMessage>  $messages */
function subjects(array $messages): array
{
    return array_map(fn (MailMessage $m) => $m->subject, $messages);
}

it('only sets the starting point on the first sync (no history)', function () {
    arrive($this->email, 'Antiguo 1');
    arrive($this->email, 'Antiguo 2');

    $first = GreenMail::provider($this->email)->newMessages('INBOX', null);

    expect($first->messages)->toBe([])
        ->and($first->cursor)->toMatch('/^v1:\d+:\d+$/')
        ->and($first->resynced)->toBeFalse();
})->group('greenmail');

it('returns only the messages that arrived after the cursor, oldest first', function () {
    arrive($this->email, 'Antiguo');
    $provider = GreenMail::provider($this->email);
    $cursor = $provider->newMessages('INBOX', null)->cursor;

    arrive($this->email, 'Nuevo 1');
    arrive($this->email, 'Nuevo 2');

    $result = $provider->newMessages('INBOX', $cursor);

    expect(subjects($result->messages))->toBe(['Nuevo 1', 'Nuevo 2'])
        ->and($result->hasMore)->toBeFalse()
        ->and($result->cursor)->not->toBe($cursor);
})->group('greenmail');

it('never returns the same message twice (IMAP "UID n:*" edge case)', function () {
    $provider = GreenMail::provider($this->email);
    $cursor = $provider->newMessages('INBOX', null)->cursor;

    arrive($this->email, 'Único');
    $result = $provider->newMessages('INBOX', $cursor);

    // Sin mensajes nuevos, "UID n:*" devuelve igualmente el último: no debe repetirse.
    $again = $provider->newMessages('INBOX', $result->cursor);
    $andAgain = $provider->newMessages('INBOX', $again->cursor);

    expect(subjects($result->messages))->toBe(['Único'])
        ->and($again->messages)->toBe([])
        ->and($andAgain->messages)->toBe([])
        ->and($andAgain->cursor)->toBe($result->cursor);
})->group('greenmail');

it('works with an empty folder', function () {
    $provider = GreenMail::provider($this->email);
    $cursor = $provider->newMessages('INBOX', null)->cursor;

    expect($provider->newMessages('INBOX', $cursor)->messages)->toBe([]);

    arrive($this->email, 'Primero de todos');

    expect(subjects($provider->newMessages('INBOX', $cursor)->messages))->toBe(['Primero de todos']);
})->group('greenmail');

it('delivers large backlogs in batches without losing or repeating messages', function () {
    $provider = GreenMail::provider($this->email, newMessagesBatch: 2);
    $cursor = $provider->newMessages('INBOX', null)->cursor;

    foreach (range(1, 5) as $i) {
        arrive($this->email, "Mensaje {$i}");
    }

    $received = [];
    $calls = 0;

    do {
        $result = $provider->newMessages('INBOX', $cursor);
        $received = [...$received, ...subjects($result->messages)];
        $cursor = $result->cursor;
        $calls++;
    } while ($result->hasMore);

    expect($received)->toBe(['Mensaje 1', 'Mensaje 2', 'Mensaje 3', 'Mensaje 4', 'Mensaje 5'])
        ->and($calls)->toBe(3);
})->group('greenmail');

it('tracks each folder independently', function () {
    arrive($this->email, 'Preparar carpeta', 'Ventas');
    $provider = GreenMail::provider($this->email);
    $inbox = $provider->newMessages('INBOX', null)->cursor;
    $sales = $provider->newMessages('Ventas', null)->cursor;

    arrive($this->email, 'A ventas', 'Ventas');

    expect($provider->newMessages('INBOX', $inbox)->messages)->toBe([])
        ->and(subjects($provider->newMessages('Ventas', $sales)->messages))->toBe(['A ventas']);
})->group('greenmail');

it('resyncs when UIDVALIDITY changed or the cursor is invalid', function (string $cursor) {
    arrive($this->email, 'Cualquiera');

    $result = GreenMail::provider($this->email)->newMessages('INBOX', $cursor);

    expect($result->resynced)->toBeTrue()
        ->and($result->messages)->toBe([])
        ->and($result->cursor)->toMatch('/^v1:\d+:\d+$/');
})->group('greenmail')->with([
    'UIDVALIDITY distinta' => ['v1:1:0'],
    'cursor corrupto' => ['basura'],
]);

it('does not mark new messages as read', function () {
    $provider = GreenMail::provider($this->email);
    $cursor = $provider->newMessages('INBOX', null)->cursor;
    arrive($this->email, 'Sin leer');

    $new = $provider->newMessages('INBOX', $cursor)->messages[0];

    expect(GreenMail::provider($this->email)->message('INBOX', $new->id)->seen)->toBeFalse();
})->group('greenmail');
