<?php

namespace App\Billing;

use App\Mail\Enums\MailConnectionStatus;
use App\Models\AiRequest;
use App\Models\Document;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Tenancy\TenantService;

/**
 * Uso del mes de una empresa frente a su plan. Lo que cuesta dinero sale de
 * contadores (tenant_usage) y de ai_requests (cost_usd, con los precios de
 * Laravel); lo que se tiene a la vez (buzones, usuarios, documentos), de un
 * recuento. Los buzones desactivados no cuentan: no se sincronizan.
 */
class UsageService
{
    public function __construct(private readonly UsageMeter $meter, private readonly TenantService $tenancy)
    {
    }

    public function forTenant(Tenant $tenant): Usage
    {
        return $this->tenancy->runAs($tenant, function () use ($tenant) {
            $period = UsageMeter::period();

            return new Usage(
                plan: $tenant->currentPlan(),
                period: $period->format('Y-m'),
                used: [
                    PlanLimit::AiEmailsPerMonth->value => $this->meter->get($tenant->id, UsageMeter::AI_EMAILS),
                    PlanLimit::AiBudgetUsdPerMonth->value => round((float) AiRequest::where('created_at', '>=', $period)->sum('cost_usd'), 4),
                    PlanLimit::Mailboxes->value => MailConnection::where('status', '!=', MailConnectionStatus::Disabled)->count(),
                    PlanLimit::Users->value => $tenant->users()->count(),
                    PlanLimit::Documents->value => Document::count(),
                ],
                emailsReceived: $this->meter->get($tenant->id, UsageMeter::EMAILS_RECEIVED),
            );
        });
    }
}
