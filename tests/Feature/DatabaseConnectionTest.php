<?php

use Illuminate\Support\Facades\DB;

it('runs against the postgres testing database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('app_testing');
});
