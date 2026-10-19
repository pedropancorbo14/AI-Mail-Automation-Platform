<?php

namespace App\ERP\Csv;

use App\Models\CustomerOrder;
use App\Tenancy\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Importa pedidos de un CSV a la empresa actual (Fase 14). Pensado para un
 * Excel en español rellenado a mano:
 *
 * - Separador `;` o `,` (o tabulador), UTF-8 con o sin BOM o Windows-1252.
 * - Cabeceras en inglés (las de la API estándar) o en español
 *   ("Nº pedido", "Email", "Estado"…); columnas desconocidas se ignoran.
 * - Estados en inglés o en español ("Enviado" → shipped), decimales con coma
 *   o punto, fechas ISO o dd/mm/aaaa (zona horaria de la aplicación), y
 *   productos como "2x Silla roble; 1x Mesa".
 *
 * Todo o nada: si una fila tiene errores no se guarda ninguna, y se dice qué
 * falla en cada fila. Volver a importar actualiza por número de pedido.
 */
class OrderCsvImporter
{
    /** Columna canónica => cabeceras aceptadas (ya normalizadas). */
    private const array COLUMNS = [
        'number' => ['number', 'order_number', 'order', 'numero', 'numero_pedido', 'pedido', 'n_pedido', 'no_pedido', 'num_pedido', 'numero_de_pedido'],
        'customer_email' => ['customer_email', 'email', 'e_mail', 'correo', 'email_cliente', 'correo_cliente'],
        'customer_name' => ['customer_name', 'nombre', 'cliente', 'nombre_cliente'],
        'status' => ['status', 'estado'],
        'placed_at' => ['placed_at', 'fecha', 'fecha_pedido', 'fecha_del_pedido'],
        'total' => ['total', 'importe'],
        'currency' => ['currency', 'moneda'],
        'items' => ['items', 'productos', 'articulos'],
        'carrier' => ['carrier', 'transportista', 'mensajeria'],
        'tracking_number' => ['tracking_number', 'tracking', 'seguimiento', 'numero_seguimiento', 'n_seguimiento'],
        'tracking_url' => ['tracking_url', 'url_seguimiento', 'enlace_seguimiento'],
        'shipped_at' => ['shipped_at', 'fecha_envio', 'fecha_de_envio'],
        'estimated_delivery' => ['estimated_delivery', 'entrega_prevista', 'fecha_entrega_prevista'],
        'delivered_at' => ['delivered_at', 'fecha_entrega', 'fecha_de_entrega'],
        'shipping_city' => ['shipping_city', 'ciudad'],
        'shipping_country' => ['shipping_country', 'pais'],
    ];

    private const array REQUIRED = [
        'number' => 'número de pedido',
        'customer_email' => 'email del cliente',
        'status' => 'estado',
    ];

    /** Estado normalizado => estado de la API estándar. */
    private const array STATUSES = [
        'pending_payment' => 'pending_payment', 'pendiente_de_pago' => 'pending_payment',
        'pendiente_pago' => 'pending_payment', 'pendiente' => 'pending_payment',
        'processing' => 'processing', 'en_preparacion' => 'processing', 'preparando' => 'processing',
        'procesando' => 'processing', 'en_proceso' => 'processing', 'preparacion' => 'processing',
        'shipped' => 'shipped', 'enviado' => 'shipped', 'en_camino' => 'shipped', 'en_reparto' => 'shipped',
        'delivered' => 'delivered', 'entregado' => 'delivered',
        'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'cancelado' => 'cancelled', 'anulado' => 'cancelled',
        'returned' => 'returned', 'devuelto' => 'returned',
    ];

    // Con y sin ceros delante ("01/09/2026" y "1/9/2026": Excel usa las dos).
    private const array DATE_FORMATS = [
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'j/n/Y H:i', 'j/n/Y', 'd-m-Y H:i', 'd-m-Y',
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
    ];

    /** Lo que se guarda tal cual (texto) con su longitud máxima. */
    private const array TEXT_LIMITS = ['customer_name' => 200, 'carrier' => 100, 'tracking_number' => 100, 'shipping_city' => 100];

    public function __construct(private readonly TenantService $tenants)
    {
    }

    public function import(string $contents): OrderImportResult
    {
        $tenantId = $this->tenants->currentOrFail()->id;
        $orders = $this->parse($contents);
        $now = now();

        return DB::transaction(function () use ($orders, $tenantId, $now) {
            $existing = 0;
            foreach (array_chunk(array_keys($orders), 1000) as $numbers) {
                $existing += CustomerOrder::whereIn('number', $numbers)->count();
            }

            foreach (array_chunk(array_values($orders), 500) as $chunk) {
                $rows = array_map(fn (array $order) => [
                    ...$order,
                    'tenant_id' => $tenantId,
                    // upsert no aplica los casts del modelo.
                    'items' => json_encode($order['items'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk);

                CustomerOrder::query()->upsert(
                    $rows,
                    ['tenant_id', 'number'],
                    [...array_keys(self::COLUMNS), 'updated_at'],
                );
            }

            return new OrderImportResult(count($orders), count($orders) - $existing, $existing);
        });
    }

    /**
     * Valida el CSV entero sin guardar nada.
     *
     * @return array<string, array<string, mixed>> número de pedido => columnas
     */
    public function parse(string $contents): array
    {
        $records = $this->records($contents);
        $header = array_shift($records);
        $columns = $this->columns($header ?? []);

        $orders = [];
        $errors = [];
        $total = 0;
        $maxReported = (int) config('integrations.csv.max_reported_errors');

        foreach ($records as $index => $record) {
            if (implode('', array_map('trim', $record)) === '') {
                continue; // fila vacía
            }

            $row = $index + 2; // fila de la hoja (la 1 es la cabecera)
            $values = [];
            foreach ($columns as $position => $column) {
                $values[$column] = trim((string) ($record[$position] ?? ''));
            }

            [$order, $rowErrors] = $this->order($values);

            if ($order !== null && isset($orders[$order['number']])) {
                $rowErrors[] = "el pedido {$order['number']} está repetido en el fichero.";
            }

            if ($rowErrors !== []) {
                $total++;
                if (count($errors) < $maxReported) {
                    $errors[] = ['row' => $row, 'errors' => $rowErrors];
                }

                continue;
            }

            $orders[$order['number']] = $order;
        }

        if ($total > 0) {
            $message = $total === 1
                ? 'Una fila tiene errores: no se ha importado nada. Corrígela y vuelve a subir el fichero.'
                : "{$total} filas tienen errores: no se ha importado nada. Corrígelas y vuelve a subir el fichero.";

            throw new OrderImportException($message, $errors, $total);
        }

        if ($orders === []) {
            throw new OrderImportException('El fichero no tiene ningún pedido.');
        }

        return $orders;
    }

    /** @return list<list<string|null>> */
    private function records(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (! mb_check_encoding($contents, 'UTF-8')) {
            // Excel en Windows guarda los CSV en Windows-1252.
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        if (trim($contents) === '') {
            throw new OrderImportException('El fichero está vacío.');
        }

        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $records = [];
        $max = (int) config('integrations.csv.max_rows');
        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if (count($records) > $max) {
                fclose($stream);

                throw new OrderImportException("El fichero tiene más de {$max} pedidos: divídelo en varios.");
            }
            $records[] = $record;
        }
        fclose($stream);

        return $records;
    }

    /** @return array<int, string> posición => columna canónica */
    private function columns(array $header): array
    {
        $aliases = [];
        foreach (self::COLUMNS as $column => $names) {
            foreach ($names as $name) {
                $aliases[$name] = $column;
            }
        }

        $columns = [];
        foreach ($header as $position => $name) {
            $column = $aliases[$this->normalize((string) $name)] ?? null;
            if ($column !== null && ! in_array($column, $columns, true)) {
                $columns[$position] = $column;
            }
        }

        $missing = array_diff_key(self::REQUIRED, array_flip($columns));
        if ($missing !== []) {
            throw new OrderImportException(
                'Faltan columnas obligatorias en la primera fila: ' . implode(', ', $missing)
                . '. Descarga la plantilla para ver el formato.'
            );
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $v
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    private function order(array $v): array
    {
        $errors = [];
        $get = fn (string $column) => ($v[$column] ?? '') === '' ? null : $v[$column];

        $number = ltrim((string) $get('number'), '#');
        if ($number === '') {
            $errors[] = 'falta el número de pedido.';
        } elseif (mb_strlen($number) > 64) {
            $errors[] = 'el número de pedido tiene más de 64 caracteres.';
        }

        $email = mb_strtolower((string) $get('customer_email'));
        if ($email === '') {
            $errors[] = 'falta el email del cliente.';
        } elseif (! preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) || mb_strlen($email) > 254) {
            $errors[] = "el email «{$email}» no es válido.";
        }

        $rawStatus = (string) $get('status');
        $status = self::STATUSES[$this->normalize($rawStatus)] ?? null;
        if ($status === null) {
            $errors[] = $rawStatus === ''
                ? 'falta el estado.'
                : "el estado «{$rawStatus}» no es válido (pendiente de pago, en preparación, enviado, entregado, cancelado o devuelto).";
        }

        $dates = [];
        foreach (['placed_at' => 'la fecha del pedido', 'shipped_at' => 'la fecha de envío', 'estimated_delivery' => 'la entrega prevista', 'delivered_at' => 'la fecha de entrega'] as $column => $label) {
            $raw = $get($column);
            $dates[$column] = $raw === null ? null : $this->date($raw);
            if ($raw !== null && $dates[$column] === null) {
                $errors[] = "{$label} «{$raw}» no es una fecha válida (usa dd/mm/aaaa o aaaa-mm-dd).";
            }
        }

        $total = null;
        if (($raw = $get('total')) !== null && ($total = $this->amount($raw)) === null) {
            $errors[] = "el total «{$raw}» no es un importe válido.";
        }

        $currency = $get('currency');
        if ($currency !== null) {
            $currency = $currency === '€' ? 'EUR' : mb_strtoupper($currency);
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                $errors[] = "la moneda «{$currency}» no es válida (código de 3 letras, p. ej. EUR).";
            }
        }

        $country = $get('shipping_country');
        if ($country !== null) {
            $country = mb_strtoupper($country);
            if (! preg_match('/^[A-Z]{2}$/', $country)) {
                $errors[] = "el país «{$country}» no es válido (código de 2 letras, p. ej. ES).";
            }
        }

        $url = $get('tracking_url');
        if ($url !== null && (! preg_match('#^https?://\S+$#i', $url) || mb_strlen($url) > 2000)) {
            $errors[] = 'el enlace de seguimiento debe empezar por http:// o https://.';
        }

        $texts = [];
        foreach (self::TEXT_LIMITS as $column => $limit) {
            $texts[$column] = $get($column);
            if ($texts[$column] !== null && mb_strlen($texts[$column]) > $limit) {
                $errors[] = "«{$column}» tiene más de {$limit} caracteres.";
            }
        }

        [$items, $itemErrors] = $this->items((string) $get('items'));
        $errors = [...$errors, ...$itemErrors];

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'number' => mb_strtoupper($number),
            'customer_email' => $email,
            'status' => $status,
            'total' => $total,
            'currency' => $currency,
            'items' => $items,
            'tracking_url' => $url,
            'shipping_country' => $country,
            ...$texts,
            ...$dates,
        ], []];
    }

    private function date(string $raw): ?string
    {
        $tz = config('app.timezone');
        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!' . $format, $raw, $tz);
            } catch (Throwable) {
                continue;
            }
            // Sin desbordes: "31/02/2026" no es el 3 de marzo.
            if ($date !== null && $date->format($format) === $raw) {
                return $date->toIso8601String();
            }
        }

        // ISO 8601 con zona ("2026-09-28T10:12:00+02:00").
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $raw)) {
            try {
                return CarbonImmutable::parse($raw, $tz)->toIso8601String();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /** "89,90", "89.90", "1.234,56", "1,234.56", "89,90 €" → "89.90". */
    private function amount(string $raw): ?string
    {
        $value = preg_replace('/[\s€]/u', '', $raw) ?? '';
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        $decimal = $comma !== false && ($dot === false || $comma > $dot) ? ',' : '.';
        $thousands = $decimal === ',' ? '.' : ',';
        $value = str_replace([$thousands, $decimal], ['', '.'], $value);

        return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) ? number_format((float) $value, 2, '.', '') : null;
    }

    /**
     * "2x Silla roble; 1x Mesa" (también con "|" o saltos de línea; sin
     * cantidad = 1).
     *
     * @return array{0: list<array{name: string, quantity: int}>, 1: list<string>}
     */
    private function items(string $raw): array
    {
        $items = [];
        foreach (preg_split('/[;|\n]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s*[x×]\s*(.+)$/iu', $part, $m)) {
                $items[] = ['name' => trim($m[2]), 'quantity' => (int) $m[1]];
            } else {
                $items[] = ['name' => $part, 'quantity' => 1];
            }
        }

        $errors = [];
        if (count($items) > 100) {
            $errors[] = 'un pedido no puede tener más de 100 productos.';
        }
        foreach ($items as $item) {
            if ($item['quantity'] < 1) {
                $errors[] = "la cantidad de «{$item['name']}» debe ser al menos 1.";
            }
            if (mb_strlen($item['name']) > 300) {
                $errors[] = 'el nombre de un producto tiene más de 300 caracteres.';
            }
        }

        return [$items, $errors];
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(Str::ascii(trim($value)))), '_');
    }
}
