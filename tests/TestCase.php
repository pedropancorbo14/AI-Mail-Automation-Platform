<?php

namespace Tests;

use App\AI\Remote\Contracts\EventStream;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\InMemoryEventStream;

abstract class TestCase extends BaseTestCase
{
    /**
     * Los roles y permisos son un catálogo fijo que la app da por existente:
     * se cargan en cada test que refresca la BD.
     */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Los eventos para ai-service van a streams en memoria: ningún test
        // publica en Redis salvo los que lo piden (RedisEventStreamTest).
        $this->app->instance(EventStream::class, new InMemoryEventStream());
    }
}
