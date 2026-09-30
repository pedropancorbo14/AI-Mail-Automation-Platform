<?php

namespace Database\Factories;

use App\Models\Email;
use App\Models\MailConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crear siempre dentro de un tenant (BelongsToTenant):
 * inTenant($tenant, fn () => Email::factory()->create()).
 *
 * @extends Factory<Email>
 */
class EmailFactory extends Factory
{
    public function definition(): array
    {
        $messageId = fake()->unique()->uuid() . '@cliente.test';

        return [
            'mail_connection_id' => MailConnection::factory(),
            'folder' => 'INBOX',
            'provider_message_id' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            'message_id' => $messageId,
            'dedup_hash' => hash('sha256', $messageId),
            'from_email' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'subject' => fake()->sentence(),
            'text_body' => fake()->paragraph(),
            'sent_at' => now(),
        ];
    }
}
