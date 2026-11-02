<?php

namespace App\Tenancy\Signup;

use App\Billing\PlanCatalog;
use App\Billing\SubscriptionStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Observability\Metrics;
use App\Security\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Alta de una empresa desde la web (Fase 17): empresa en prueba + su
 * propietario, que no puede entrar hasta confirmar el email.
 *
 * Mismo resultado visible pase lo que pase (no se puede averiguar qué emails
 * tienen cuenta): con un email que ya existe no se crea nada y a su dueño le
 * llega un aviso.
 */
class SignupService
{
    public function __construct(private readonly AuditLogger $audit, private readonly Metrics $metrics)
    {
    }

    /** @param array{company: string, name: string, email: string, password: string} $data */
    public function signup(array $data): void
    {
        $email = mb_strtolower(trim($data['email']));
        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            Mail::to($existing->email)->send(new AccountExistsMessage($existing));
            $this->metrics->increment('tenant_signups_total', ['result' => 'existing']);

            return;
        }

        [$tenant, $user] = DB::transaction(function () use ($data, $email) {
            $tenant = Tenant::create(['name' => trim($data['company']), 'slug' => self::uniqueSlug($data['company'])]);
            $plan = PlanCatalog::find(config('plans.default')) ?? PlanCatalog::get('trial');
            $tenant->assignPlan(
                $plan,
                $plan->key === 'trial' ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
                $plan->key === 'trial' ? (int) config('plans.trial_days') : null,
            );

            $user = new User(['name' => trim($data['name']), 'email' => $email, 'password' => $data['password']]);
            $user->tenant()->associate($tenant);
            $user->role()->associate(Role::where('name', Role::OWNER)->firstOrFail());
            $user->save();

            return [$tenant, $user];
        });

        $this->metrics->increment('tenant_signups_total', ['result' => 'created']);
        $this->audit->record('tenant.signed_up', $tenant, $tenant->name, metadata: ['owner_email' => $email, 'plan' => $tenant->plan], actor: $user, tenantId: $tenant->id);
        $this->sendVerification($user);
    }

    /** Vuelve a mandar el enlace (sin decir si el email existe). */
    public function resend(string $email): void
    {
        $user = User::where('email', mb_strtolower(trim($email)))->first();

        if ($user !== null && $user->email_verified_at === null) {
            $this->sendVerification($user);
        }
    }

    /** true si el enlace es de ese usuario y su email actual; marca el email como confirmado. */
    public function verify(User $user, string $hash): bool
    {
        if (! hash_equals(self::hash($user), $hash)) {
            return false;
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->audit->record('auth.email_verified', $user, $user->email, actor: $user, tenantId: $user->tenant_id);
        }

        return true;
    }

    private function sendVerification(User $user): void
    {
        $url = URL::temporarySignedRoute(
            'auth.verify-email',
            now()->addHours((int) config('plans.verification_hours')),
            ['user' => $user->id, 'hash' => self::hash($user)],
        );

        Mail::to($user->email)->send(new VerifyEmailMessage($user, $url));
    }

    /** El enlace solo vale para el email con el que se generó. */
    private static function hash(User $user): string
    {
        return sha1(mb_strtolower($user->email));
    }

    private static function uniqueSlug(string $company): string
    {
        $base = Str::slug($company) ?: 'empresa';
        $slug = $base;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
