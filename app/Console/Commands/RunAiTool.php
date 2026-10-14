<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Models\Tenant;
use App\Tenancy\Scopes\TenantScope;
use App\Tools\Contracts\AITool;
use App\Tools\Contracts\RequiresSender;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prueba las herramientas de la IA a mano, pasando por ToolExecutor (valida,
 * comprueba permisos y registra en ai_tool_calls igual que con la IA).
 */
#[Signature('ai:tool
    {name? : Herramienta a ejecutar}
    {--args= : Argumentos en JSON (por defecto, ninguno)}
    {--email= : Id del email que se "procesa" (da la empresa y el remitente)}
    {--tenant= : Id de la empresa (si no se indica email)}
    {--list : Lista las herramientas disponibles}')]
#[Description('Lista o ejecuta las herramientas que puede usar la IA')]
class RunAiTool extends Command
{
    public function handle(ToolRegistry $registry, ToolExecutor $executor): int
    {
        if ($this->option('list') || ! $this->argument('name')) {
            $this->table(['Herramienta', 'Necesita email', 'Descripción'], array_map(fn (AITool $tool) => [
                $tool->name(),
                $tool instanceof RequiresSender ? 'sí' : 'no',
                Str::limit($tool->description(), 90),
            ], $registry->all()));

            return self::SUCCESS;
        }

        $arguments = json_decode((string) ($this->option('args') ?: '{}'), true);

        if (! is_array($arguments)) {
            $this->error('--args debe ser un objeto JSON, p. ej. --args=\'{"order_number":"45821"}\'.');

            return self::FAILURE;
        }

        $context = $this->toolContext();

        if ($context === null) {
            $this->error('Indica --email=<id> (empresa y remitente salen del email) o --tenant=<id>.');

            return self::FAILURE;
        }

        $result = $executor->execute((string) $this->argument('name'), $arguments, $context);

        $result->ok ? $this->info('OK') : $this->warn('ERROR');
        $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result->ok ? self::SUCCESS : self::FAILURE;
    }

    private function toolContext(): ?ToolContext
    {
        if ($this->option('email')) {
            // Consulta global deliberada: el comando no tiene tenant; el ejecutor entra en el del email.
            $email = Email::withoutGlobalScope(TenantScope::class)->with('tenant')->find($this->option('email'));

            return $email ? new ToolContext($email->tenant, $email) : null;
        }

        $tenant = Tenant::find($this->option('tenant'));

        return $tenant ? new ToolContext($tenant) : null;
    }
}
