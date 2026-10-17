<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Rol y esquema de los checkpoints de LangGraph de ai-service
 * (contracts/database/ai_service_checkpoints.sql). Como en
 * KnowledgeBaseReaderTest, se comprueba con SET LOCAL ROLE dentro de la
 * transacción del test.
 */

/** Ejecuta $callback como ai_service_checkpoints (con su search_path); se deshace todo al terminar. */
function asCheckpoints(Closure $callback): mixed
{
    DB::statement('SAVEPOINT checkpoints');
    try {
        DB::statement('SET LOCAL ROLE ai_service_checkpoints');
        DB::statement('SET LOCAL search_path = ai_service');

        return $callback();
    } finally {
        DB::statement('ROLLBACK TO SAVEPOINT checkpoints');
        DB::statement('RESET ROLE');
        DB::statement('RESET search_path');
    }
}

it('creates the ai_service schema owned by its own role', function () {
    $owner = DB::selectOne("SELECT pg_get_userbyid(nspowner) AS owner FROM pg_namespace WHERE nspname = 'ai_service'");

    expect($owner?->owner)->toBe('ai_service_checkpoints');
});

it('lets the role create and use tables in its schema', function () {
    $row = asCheckpoints(function () {
        DB::statement('CREATE TABLE probe (id int)');
        DB::statement('INSERT INTO probe VALUES (1)');

        return DB::selectOne('SELECT current_schema() AS schema, count(*) AS total FROM probe GROUP BY 1');
    });

    expect($row->schema)->toBe('ai_service')
        ->and($row->total)->toBe(1);
});

it('cannot read any table of Laravel', function (string $table) {
    asCheckpoints(fn () => DB::select("SELECT * FROM public.{$table} LIMIT 1"));
})->with(['emails', 'users', 'tenants', 'documents', 'ai_requests'])
    ->throws(QueryException::class, 'permission denied');

it('cannot create tables outside its schema', function () {
    asCheckpoints(fn () => DB::statement('CREATE TABLE public.probe (id int)'));
})->throws(QueryException::class, 'permission denied');
