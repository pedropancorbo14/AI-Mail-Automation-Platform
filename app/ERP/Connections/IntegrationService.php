<?php

namespace App\ERP\Connections;

use App\ERP\StandardApi\StandardApiTester;
use App\ERP\WooCommerce\WooCommerceTester;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Security\Audit\AuditLogger;
use InvalidArgumentException;

/**
 * Sistemas que conecta la empresa actual (API estándar de su ERP, tienda
 * WooCommerce): guardar —y probar al momento: sin probar no se usa a
 * ciegas—, probar y borrar. Las credenciales van cifradas y nunca salen en
 * la auditoría.
 *
 * Credenciales (`credentials`, cifradas): la clave de la API estándar, o el
 * JSON {consumer_key, consumer_secret} de WooCommerce.
 */
class IntegrationService
{
    public const array LABELS = [
        TenantIntegration::STANDARD_API => 'API estándar',
        TenantIntegration::WOOCOMMERCE => 'WooCommerce',
    ];

    public function __construct(
        private readonly StandardApiTester $standardApi,
        private readonly WooCommerceTester $woocommerce,
        private readonly AuditLogger $audit,
    ) {
    }

    public function current(string $type): ?TenantIntegration
    {
        return TenantIntegration::where('type', $type)->first();
    }

    public function rejectUrl(string $type, string $url): ?string
    {
        return match ($type) {
            TenantIntegration::STANDARD_API => $this->standardApi->rejectUrl($url),
            TenantIntegration::WOOCOMMERCE => $this->woocommerce->rejectUrl($url),
            default => throw new InvalidArgumentException("Tipo de integración desconocido: {$type}"),
        };
    }

    /**
     * @param  array<string, mixed>  $data  base_url, enabled y, según el tipo, token
     *                                      (+ orders/invoices/appointments) o consumer_key +
     *                                      consumer_secret. Sin `appointments`, se conserva.
     *                                      Credenciales vacías = conservar las guardadas.
     * @return array{connection: TenantIntegration, result: ConnectionTestResult}
     */
    public function save(string $type, array $data, ?User $user): array
    {
        $connection = $this->current($type) ?? new TenantIntegration;
        $created = ! $connection->exists;

        $connection->forceFill([
            'type' => $type,
            'base_url' => rtrim($data['base_url'], '/'),
            // WooCommerce: solo pedidos (no tiene facturas de serie).
            'orders' => $type === TenantIntegration::WOOCOMMERCE ? true : (bool) $data['orders'],
            'invoices' => $type === TenantIntegration::WOOCOMMERCE ? false : (bool) $data['invoices'],
            'appointments' => $type !== TenantIntegration::WOOCOMMERCE
                && (bool) ($data['appointments'] ?? $connection->appointments),
            'enabled' => (bool) $data['enabled'],
        ]);
        if ($created) {
            $connection->created_by = $user?->id;
        }

        $credentials = $this->credentials($type, $data);
        if ($credentials !== null) {
            $connection->credentials = $credentials;
        }
        $connection->save();

        $changes = AuditLogger::changesOf($connection, ['base_url', 'orders', 'invoices', 'appointments', 'enabled']);
        if ($credentials !== null) {
            $changes['credentials'] = ['from' => $created ? null : '[oculto]', 'to' => '[oculto]'];
        }
        $this->audit->record($created ? 'integration.created' : 'integration.updated', $connection, self::LABELS[$type], changes: $changes);

        // Cambiar la conexión exige volver a probarla.
        return $this->test($connection);
    }

    /** @return array{connection: TenantIntegration, result: ConnectionTestResult} */
    public function test(TenantIntegration $connection): array
    {
        $result = match ($connection->type) {
            TenantIntegration::STANDARD_API => $this->standardApi->test(
                $connection->base_url, (string) $connection->credentials, $connection->orders, $connection->invoices,
                $connection->appointments,
            ),
            TenantIntegration::WOOCOMMERCE => $this->woocommerce->test(
                $connection->base_url, ...array_values(self::wooKeys($connection)),
            ),
        };

        $connection->forceFill([
            'status' => $result->ok ? 'active' : 'failing',
            'last_tested_at' => now(),
            'last_error' => $result->error,
        ])->save();

        $this->audit->record('integration.tested', $connection, self::LABELS[$connection->type], metadata: ['ok' => $result->ok]);

        return ['connection' => $connection, 'result' => $result];
    }

    public function delete(TenantIntegration $connection): void
    {
        $this->audit->record('integration.deleted', $connection, self::LABELS[$connection->type], metadata: ['base_url' => $connection->base_url]);
        $connection->delete();
    }

    /** @return array{consumer_key: string, consumer_secret: string} */
    public static function wooKeys(TenantIntegration $connection): array
    {
        $keys = json_decode((string) $connection->credentials, true) ?: [];

        return ['consumer_key' => (string) ($keys['consumer_key'] ?? ''), 'consumer_secret' => (string) ($keys['consumer_secret'] ?? '')];
    }

    /** Las credenciales nuevas a guardar, o null para conservar las de antes. */
    private function credentials(string $type, array $data): ?string
    {
        if ($type === TenantIntegration::WOOCOMMERCE) {
            return filled($data['consumer_key'] ?? null) && filled($data['consumer_secret'] ?? null)
                ? json_encode(['consumer_key' => $data['consumer_key'], 'consumer_secret' => $data['consumer_secret']])
                : null;
        }

        return filled($data['token'] ?? null) ? $data['token'] : null;
    }
}
