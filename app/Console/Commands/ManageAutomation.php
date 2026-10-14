<?php

namespace App\Console\Commands;

use App\AI\Classification\EmailIntent;
use App\Automation\AutomationMode;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Ver o cambiar cuánto automatiza la plataforma en una empresa. */
#[Signature('ai:automation
    {tenant : Id de la empresa}
    {--mode= : off, draft o auto}
    {--intents=* : Intenciones que la IA puede responder sola en modo auto (sin valor = las de la configuración)}')]
#[Description('Muestra y cambia el modo de automatización de una empresa')]
class ManageAutomation extends Command
{
    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if ($tenant === null) {
            $this->error('No existe esa empresa.');

            return self::FAILURE;
        }

        $mode = $this->option('mode');
        $intents = $this->option('intents');

        if ($mode !== null || $intents !== []) {
            $newMode = $mode === null ? $tenant->automation_mode : AutomationMode::tryFrom($mode);

            if ($newMode === null) {
                $this->error("Modo «{$mode}» desconocido: usa off, draft o auto.");

                return self::FAILURE;
            }

            // --intents=x,y o --intents=x --intents=y; "--intents=" a secas vuelve a las de la configuración.
            $list = $intents === [] ? null : array_values(array_filter(array_map('trim', explode(',', implode(',', $intents)))));

            try {
                $tenant->configureAutomation($newMode, $list);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $this->show($tenant->fresh());

        return self::SUCCESS;
    }

    private function show(Tenant $tenant): void
    {
        $this->info("Automatización de «{$tenant->name}»: {$tenant->automation_mode->label()} ({$tenant->automation_mode->value})");
        $this->line('  ' . $tenant->automation_mode->description());

        $allowed = $tenant->autoSendIntents();
        $origin = $tenant->auto_send_intents === null ? 'configuración por defecto' : 'elegidas por la empresa';

        $this->newLine();
        $this->table(['Intención', 'Se envía sola en modo auto'], array_map(fn (EmailIntent $intent) => [
            $intent->value,
            match (true) {
                ! $intent->allowsAutoSend() => 'nunca (siempre una persona)',
                in_array($intent, $allowed, true) => "SÍ ({$origin})",
                default => 'no',
            },
        ], EmailIntent::cases()));

        if ($tenant->automation_mode !== AutomationMode::Auto) {
            $this->line('  En este modo no se envía nada sin revisión humana.');
        }
    }
}
