<?php

use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\GreenMail;

beforeEach(function () {
    $this->email = GreenMail::createAccount();

    // Contenido binario con bytes nulos y no-UTF8: comprueba que llega intacto.
    $this->pdf = "%PDF-1.4\x00\x01\x02\xff\xfe binario " . random_bytes(2048);

    $message = (new Email())
        ->from('cliente@cliente.test')
        ->to('soporte@acme.test')
        ->subject('Factura adjunta')
        ->text('Adjunto la factura y una foto.')
        ->html('<p>Foto: <img src="cid:logo@cliente.test"></p>')
        ->attach($this->pdf, 'factura ñ-2026.pdf', 'application/pdf')
        ->attach("id;producto\n1;Silla\n", 'pedido.csv', 'text/csv')
        ->addPart((new DataPart('PNGDATA', 'logo.png', 'image/png'))->asInline()->setContentId('logo@cliente.test'));

    $this->uid = (string) GreenMail::append($this->email, $message);
});

/** Id de un adjunto por nombre (el orden sigue la estructura MIME, no el de attach()). */
function attachmentIdByName(string $email, string $uid, string $filename): string
{
    $attachments = GreenMail::provider($email)->message('INBOX', $uid)->attachments;

    return collect($attachments)->firstWhere('filename', $filename)->id;
}

it('lists attachment metadata when reading the message', function () {
    $attachments = GreenMail::provider($this->email)->message('INBOX', $this->uid)->attachments;

    expect($attachments)->toHaveCount(3)
        ->and(array_map(fn ($a) => $a->id, $attachments))->toBe(['0', '1', '2']);

    $byName = collect($attachments)->keyBy('filename');
    $pdf = $byName['factura ñ-2026.pdf'];
    $csv = $byName['pedido.csv'];
    $logo = $byName['logo.png'];

    expect($pdf)
        ->filename->toBe('factura ñ-2026.pdf')
        ->contentType->toBe('application/pdf')
        ->size->toBe(strlen($this->pdf))
        ->inline->toBeFalse()
        ->and($csv->filename)->toBe('pedido.csv')
        ->and($logo)
        ->inline->toBeTrue()
        ->contentId->toBe('logo@cliente.test');
})->group('greenmail');

it('downloads an attachment byte for byte', function () {
    $id = attachmentIdByName($this->email, $this->uid, 'factura ñ-2026.pdf');
    $download = GreenMail::provider($this->email)->downloadAttachment('INBOX', $this->uid, $id);

    expect($download->content)->toBe($this->pdf)
        ->and($download->attachment->filename)->toBe('factura ñ-2026.pdf');
})->group('greenmail');

it('downloads text attachments', function () {
    $id = attachmentIdByName($this->email, $this->uid, 'pedido.csv');

    expect(GreenMail::provider($this->email)->downloadAttachment('INBOX', $this->uid, $id)->content)
        ->toBe("id;producto\n1;Silla\n");
})->group('greenmail');

it('fails with not found for unknown attachments', function (string $attachmentId) {
    GreenMail::provider($this->email)->downloadAttachment('INBOX', $this->uid, $attachmentId);
})->group('greenmail')->with(['7', 'abc', '-1'])->throws(MailNotFoundException::class);

it('refuses attachments above the size limit', function () {
    $provider = GreenMail::provider($this->email, maxAttachmentBytes: 1024);

    $provider->downloadAttachment('INBOX', $this->uid, attachmentIdByName($this->email, $this->uid, 'factura ñ-2026.pdf'));
})->group('greenmail')->throws(MailProviderException::class, 'supera el tamaño máximo');
