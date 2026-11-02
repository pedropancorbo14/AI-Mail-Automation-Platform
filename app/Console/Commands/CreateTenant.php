<?php

namespace App\Console\Commands;

use App\Billing\PlanCatalog;
use App\Billing\SubscriptionStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Alta de una empresa con su propietario (Fase 16). En producción no hay
 * seeders de demostración ni registro público: el operador crea cada empresa
 * con este comando y el propietario da de alta al resto desde el panel.
 *
 * La contraseña se pide sin mostrarla; con --generate-password se genera una
 * aleatoria y se muestra UNA vez (para enviársela por un canal seguro: el
 * propietario la cambia en "Mi cuenta").
 */
#[Signature('tenant:create
    {name : Nombre de la empresa}
    {email : Email del propietario (con él entra en el panel)}
    {--owner-name= : Nombre del propietario (por defecto, el de la empresa)}
    {--slug= : Identificador de la empresa (por defecto, a partir del nombre)}
    {--generate-password : Generar una contraseña aleatoria en vez de pedirla}
    {--plan= : Plan (trial, starter, business, enterprise; por defecto el de SAAS_DEFAULT_PLAN)}
    {--trial-days= : Días de prueba si el plan es trial (por defecto SAAS_TRIAL_DAYS)}')]
#[Description('Crea una empresa y su usuario propietario')]
class CreateTenant extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $slug = $this->option('slug') ?: Str::slug((string) $this->argument('name'));

        $validator = Validator::make(['email' => $email, 'slug' => $slug, 'name' => $this->argument('name')], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'slug' => ['required', 'alpha_dash', 'max:255', 'unique:tenants,slug'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $plan = PlanCatalog::find($this->option('plan') ?: config('plans.default'));

        if ($plan === null) {
            $this->error('El plan no existe. Planes: '.implode(', ', PlanCatalog::keys()).'.');

            return self::FAILURE;
        }

        $generated = (bool) $this->option('generate-password');
        $password = $generated ? Str::password(20, symbols: false) : (string) $this->secret('Contraseña del propietario');

        $passwordCheck = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);

        if ($passwordCheck->fails()) {
            $this->error($passwordCheck->errors()->first('password'));

            return self::FAILURE;
        }

        $role = Role::where('name', Role::OWNER)->first();

        if ($role === null) {
            $this->error('No hay roles en la base de datos: php artisan db:seed --class=RolesAndPermissionsSeeder --force');

            return self::FAILURE;
        }

        [$tenant, $user] = DB::transaction(function () use ($email, $slug, $password, $role, $plan) {
            $tenant = Tenant::create(['name' => $this->argument('name'), 'slug' => $slug]);
            $trial = $plan->key === 'trial';
            $tenant->assignPlan(
                $plan,
                $trial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
                $trial ? (int) ($this->option('trial-days') ?? config('plans.trial_days')) : null,
            );

            $user = new User([
                'name' => $this->option('owner-name') ?: $this->argument('name'),
                'email' => $email,
                'password' => $password,
            ]);
            $user->tenant()->associate($tenant);
            $user->role()->associate($role);
            // Lo da de alta el operador: no hace falta confirmar el email.
            $user->email_verified_at = now();
            $user->save();

            return [$tenant, $user];
        });

        $audit->record('tenant.created', $tenant, $tenant->name, metadata: ['owner_email' => $email, 'plan' => $plan->key], tenantId: $tenant->id);

        $this->info("Empresa \"{$tenant->name}\" creada (id {$tenant->id}, slug {$tenant->slug}, plan {$plan->name}). Propietario: {$user->email}.");

        if ($generated) {
            $this->warn("Contraseña (no se volverá a mostrar): {$password}");
        }

        return self::SUCCESS;
    }
}
