<?php

use App\ERP\Csv\OrderCsvImporter;
use App\ERP\Csv\OrderImportException;
use App\ERP\Csv\OrderImportResult;
use App\Models\CustomerOrder;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

function importCsv(Tenant $tenant, string $csv): OrderImportResult
{
    return inTenant($tenant, fn () => app(OrderCsvImporter::class)->import($csv));
}

/** @return array{0: string, 1: list<array{row: int, errors: list<string>}>} */
function importCsvErrors(Tenant $tenant, string $csv): array
{
    try {
        importCsv($tenant, $csv);
    } catch (OrderImportException $e) {
        return [$e->getMessage(), $e->rows];
    }

    throw new RuntimeException('Se esperaba que la importación fallara.');
}

function ordersOf(Tenant $tenant): Illuminate\Support\Collection
{
    return inTenant($tenant, fn () => CustomerOrder::orderBy('number')->get());
}

it('imports the downloadable template exactly as it is offered', function () {
    $result = importCsv($this->tenant, file_get_contents(resource_path('templates/pedidos-plantilla.csv')));

    expect($result->toArray())->toBe(['rows' => 2, 'created' => 2, 'updated' => 0]);

    $order = ordersOf($this->tenant)->firstWhere('number', '45821');
    expect($order->customer_email)->toBe('cliente@ejemplo.com')
        ->and($order->customer_name)->toBe('Ana Pérez')
        ->and($order->status)->toBe('shipped')
        ->and((string) $order->total)->toBe('89.90')
        ->and($order->items)->toBe([
            ['name' => 'Lámpara de mesa', 'quantity' => 1],
            ['name' => 'Bombilla LED', 'quantity' => 2],
        ])
        ->and($order->carrier)->toBe('SEUR')
        ->and($order->placed_at->format('Y-m-d'))->toBe('2026-09-28')
        ->and($order->shipping_country)->toBe('ES');
});

it('accepts comma separated files with English headers and ISO dates', function () {
    $csv = "number,customer_email,status,placed_at,total,currency\n"
        . "ES-1,Laura@Cliente.TEST,processing,2026-10-05T09:31:00+02:00,129.00,eur\n";

    importCsv($this->tenant, $csv);

    $order = ordersOf($this->tenant)->sole();
    expect($order->number)->toBe('ES-1')
        ->and($order->customer_email)->toBe('laura@cliente.test')
        ->and($order->currency)->toBe('EUR')
        // El mismo instante (se devuelve en la zona horaria de la aplicación).
        ->and(Carbon\CarbonImmutable::parse($order->toStandardApi()['placed_at'])->equalTo('2026-10-05T09:31:00+02:00'))->toBeTrue();
});

it('reads Excel files saved in Windows-1252', function () {
    $csv = mb_convert_encoding("Nº pedido;Email;Estado;Nombre\n1;ana@cliente.test;En preparación;José Muñoz\n", 'Windows-1252', 'UTF-8');

    importCsv($this->tenant, $csv);

    expect(ordersOf($this->tenant)->sole())
        ->status->toBe('processing')
        ->customer_name->toBe('José Muñoz');
});

it('understands statuses, amounts and dates the way a Spanish spreadsheet writes them', function (string $status, string $total, string $date, string $expectedStatus, string $expectedTotal, string $expectedDate) {
    importCsv($this->tenant, "pedido;email;estado;importe;fecha\n1;a@b.test;\"{$status}\";\"{$total}\";{$date}\n");

    $order = ordersOf($this->tenant)->sole();
    expect($order->status)->toBe($expectedStatus)
        ->and((string) $order->total)->toBe($expectedTotal)
        ->and($order->placed_at->format('Y-m-d'))->toBe($expectedDate);
})->with([
    ['ENVIADO', '89,90', '28/09/2026', 'shipped', '89.90', '2026-09-28'],
    ['Pendiente de pago', '1.234,56 €', '1/9/2026', 'pending_payment', '1234.56', '2026-09-01'],
    ['entregado', '1,234.56', '2026-09-28 10:12', 'delivered', '1234.56', '2026-09-28'],
    ['Cancelado', '12', '28-09-2026', 'cancelled', '12.00', '2026-09-28'],
    ['devuelto', '0', '2026-09-28', 'returned', '0.00', '2026-09-28'],
]);

it('updates existing orders by number when the file is imported again', function () {
    importCsv($this->tenant, "pedido;email;estado\n1;a@b.test;en preparación\n2;a@b.test;enviado\n");

    $result = importCsv($this->tenant, "pedido;email;estado\n1;a@b.test;entregado\n3;c@d.test;enviado\n");

    expect($result->toArray())->toBe(['rows' => 2, 'created' => 1, 'updated' => 1])
        ->and(ordersOf($this->tenant)->pluck('status', 'number')->all())
        ->toBe(['1' => 'delivered', '2' => 'shipped', '3' => 'shipped']);
});

it('imports nothing when a single row is wrong and says what is wrong in each row', function () {
    importCsv($this->tenant, "pedido;email;estado\n1;a@b.test;enviado\n");

    [$message, $rows] = importCsvErrors($this->tenant, implode("\n", [
        'pedido;email;estado;fecha;total;moneda;pais;url seguimiento;productos',
        '2;a@b.test;entregado;;;;;;',
        ';no-es-email;volando;31/02/2026;gratis;euros;España;javascript:alert(1);0x Silla',
        '2;a@b.test;enviado;;;;;;',
    ]));

    expect($message)->toBe('2 filas tienen errores: no se ha importado nada. Corrígelas y vuelve a subir el fichero.')
        ->and($rows[0]['row'])->toBe(3)
        ->and(implode(' ', $rows[0]['errors']))
        ->toContain('falta el número de pedido')
        ->toContain('«no-es-email» no es válido')
        ->toContain('«volando» no es válido')
        ->toContain('«31/02/2026» no es una fecha válida')
        ->toContain('«gratis» no es un importe válido')
        ->toContain('«EUROS» no es válida')
        ->toContain('«ESPAÑA» no es válido')
        ->toContain('debe empezar por http')
        ->toContain('al menos 1')
        ->and($rows[1])->toBe(['row' => 4, 'errors' => ['el pedido 2 está repetido en el fichero.']])
        // Todo o nada: el pedido 1 sigue como estaba y el 2 no se ha creado.
        ->and(ordersOf($this->tenant)->pluck('status', 'number')->all())->toBe(['1' => 'shipped']);
});

it('rejects files without the required columns, empty files and files without orders', function (string $csv, string $message) {
    expect(importCsvErrors($this->tenant, $csv)[0])->toContain($message);
})->with([
    'sin estado' => ["pedido;email\n1;a@b.test\n", 'Faltan columnas obligatorias en la primera fila: estado'],
    'vacío' => ["  \n", 'El fichero está vacío'],
    'solo cabecera' => ["pedido;email;estado\n;;\n", 'no tiene ningún pedido'],
]);

it('limits the number of rows', function () {
    config(['integrations.csv.max_rows' => 2]);

    expect(importCsvErrors($this->tenant, "pedido;email;estado\n1;a@b.test;enviado\n2;a@b.test;enviado\n3;a@b.test;enviado\n")[0])
        ->toContain('más de 2 pedidos');
});

it('reports a limited number of row errors but counts all of them', function () {
    config(['integrations.csv.max_reported_errors' => 2]);
    $csv = "pedido;email;estado\n" . implode("\n", array_map(fn ($n) => "{$n};mal;enviado", range(1, 5)));

    try {
        importCsv($this->tenant, $csv);
    } catch (OrderImportException $e) {
        expect($e->rows)->toHaveCount(2)->and($e->totalRowErrors)->toBe(5);

        return;
    }

    throw new RuntimeException('Se esperaba que la importación fallara.');
});

it('keeps the orders of each company apart even with the same numbers', function () {
    $other = Tenant::factory()->create();
    importCsv($this->tenant, "pedido;email;estado\n1;a@b.test;enviado\n");
    importCsv($other, "pedido;email;estado\n1;x@y.test;entregado\n");

    expect(ordersOf($this->tenant)->sole()->customer_email)->toBe('a@b.test')
        ->and(ordersOf($other)->sole()->customer_email)->toBe('x@y.test');
});
