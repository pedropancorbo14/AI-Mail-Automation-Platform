<?php

namespace App\ERP\StandardApi;

use App\Models\TenantIntegration;
use App\Models\User;
use App\Security\Audit\AuditLogger;

/**
 * Conexión de la empresa actual con la API estándar de su sistema: guardar
 * (y probar al momento: sin probar no se usa a ciegas), probar y borrar.
 * Todo queda en la auditoría, nunca la clave.
 */
class StandardApiConnectionService
{
    public function __construct(
        private readonly StandardApiTester $tester,
        private readonly AuditLogger $audit,
    ) {
    }

    public function current(): ?TenantIntegration
    {
        return TenantIntegration::where('type', TenantIntegration::STANDARD_API)->first();
    }

    /**
     * @param  array{base_url: string, token?: ?string, orders: bool, invoices: bool, enabled: bool}  $data
     *                                                                                                       `token` vacío = conservar la guardada.
     */
    public function save(array $data, ?User $user): array
    {
        $connection = $this->current() ?? new TenantIntegration;
        $created = ! $connection->exists;

        $connection->forceFill([
            'type' => TenantIntegration::STANDARD_API,
            'base_url' => rtrim($data['base_url'], '/'),
            'orders' => $data['orders'],
            'invoices' => $data['invoices'],
            'enabled' => $data['enabled'],
        ]);
        if ($created) {
            $connection->created_by = $user?->id;
        }
        $tokenChanged = filled($data['token'] ?? null);
        if ($tokenChanged) {
            $connection->credentials = $data['token'];
        }
        $connection->save();

        $changes = AuditLogger::changesOf($connection, ['base_url', 'orders', 'invoices', 'enabled']);
        if ($tokenChanged) {
            $changes['token'] = ['from' => $created ? null : '[oculto]', 'to' => '[oculto]'];
        }
        $this->audit->record($created ? 'integration.created' : 'integration.updated', $connection, 'API estándar', changes: $changes);

        // Cambiar la conexión exige volver a probarla.
        return $this->test($connection, $user);
    }

    /** @return array{connection: TenantIntegration, result: StandardApiTestResult} */
    public function test(TenantIntegration $connection, ?User $user = null): array
    {
        $result = $this->tester->test($connection->base_url, (string) $connection->credentials, $connection->orders, $connection->invoices);

        $connection->forceFill([
            'status' => $result->ok ? 'active' : 'failing',
            'last_tested_at' => now(),
            'last_error' => $result->error,
        ])->save();

        $this->audit->record('integration.tested', $connection, 'API estándar', metadata: ['ok' => $result->ok]);

        return ['connection' => $connection, 'result' => $result];
    }

    public function delete(TenantIntegration $connection): void
    {
        $this->audit->record('integration.deleted', $connection, 'API estándar', metadata: ['base_url' => $connection->base_url]);
        $connection->delete();
    }
}
