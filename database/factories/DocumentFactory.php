<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crear siempre dentro de un tenant: inTenant($tenant, fn () => Document::factory()->create()).
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $content = fake()->paragraphs(3, true);

        return [
            'title' => fake()->sentence(3),
            'original_filename' => fake()->slug(2) . '.txt',
            'mime_type' => 'text/plain',
            'size' => strlen($content),
            'disk' => 'local',
            'path' => 'tenants/0/documents/' . fake()->uuid(),
            'sha256' => hash('sha256', $content . fake()->uuid()),
        ];
    }
}
