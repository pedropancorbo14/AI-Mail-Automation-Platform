<?php

namespace Database\Factories;

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Enums\MailEncryption;
use App\Mail\Enums\MailProviderType;
use App\Models\MailConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crear siempre dentro de un tenant (BelongsToTenant falla sin tenant):
 * app(TenantService::class)->runAs($tenant, fn () => MailConnection::factory()->create()).
 *
 * @extends Factory<MailConnection>
 */
class MailConnectionFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'name' => fake()->words(2, true),
            'email' => $email,
            'provider' => MailProviderType::Imap,
            'credentials' => new ImapSmtpCredentials(
                imapHost: 'imap.example.test', imapPort: 993, imapEncryption: MailEncryption::Ssl,
                imapUsername: $email, imapPassword: 'password',
                smtpHost: 'smtp.example.test', smtpPort: 587, smtpEncryption: MailEncryption::StartTls,
                smtpUsername: $email, smtpPassword: 'password',
            ),
        ];
    }

    public function microsoft(): static
    {
        return $this->oauth(MailProviderType::Microsoft);
    }

    public function google(): static
    {
        return $this->oauth(MailProviderType::Google);
    }

    private function oauth(MailProviderType $provider): static
    {
        return $this->state(fn () => [
            'provider' => $provider,
            'credentials' => new OAuthCredentials('access-token', 'refresh-token', CarbonImmutable::now()->addHour()),
        ]);
    }
}
