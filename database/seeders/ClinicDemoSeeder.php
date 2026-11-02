<?php

namespace Database\Seeders;

use App\Billing\PlanCatalog;
use App\Billing\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Tools\TenantToolService;
use Illuminate\Database\Seeder;

/**
 * "Clínica Demo" (Fase 14): un negocio con citas en vez de pedidos, para
 * probar las herramientas de citas en local. Su slug, "clinica-demo", es el
 * que usa el servidor MCP para darle las citas de demostración
 * (ai-service/src/ai_service/mcp_server/data/appointments.json): no hay que
 * configurar ids. Idempotente. Se ejecuta aparte, como KnowledgeBaseSeeder
 * (DatabaseSeeder desactiva los eventos de modelo y BelongsToTenant los
 * necesita):
 *
 *   php artisan db:seed --class=ClinicDemoSeeder
 *
 * Usuario: clinica@example.com / password (propietaria). Pacientes con
 * citas: maria@, javier@ y ana@cliente.test.
 */
class ClinicDemoSeeder extends Seeder
{
    public function run(TenantToolService $tools): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $tenant = Tenant::firstOrCreate(['slug' => 'clinica-demo'], ['name' => 'Clínica Demo']);

        // Empresa de demostración: sin límites de plan (Fase 17).
        if ($tenant->plan !== 'enterprise') {
            $tenant->assignPlan(PlanCatalog::get('enterprise'), SubscriptionStatus::Active);
        }

        if (! User::where('email', 'clinica@example.com')->exists()) {
            User::factory()->for($tenant)->owner()->create(['name' => 'Clínica Demo', 'email' => 'clinica@example.com']);
        }

        // La IA puede consultar las citas del paciente (desactivadas por defecto).
        foreach (['get_customer_appointments', 'get_appointment'] as $tool) {
            $tools->enable($tenant, $tool);
        }

        $this->command?->info("Clínica Demo lista (empresa {$tenant->id}): entra con clinica@example.com / password.");
    }
}
