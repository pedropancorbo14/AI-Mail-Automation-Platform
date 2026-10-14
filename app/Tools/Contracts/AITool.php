<?php

namespace App\Tools\Contracts;

use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * Herramienta que la IA puede pedir usar. La IA nunca accede a datos por
 * su cuenta: solo puede pedir una herramienta por su nombre con unos
 * argumentos, y ToolExecutor (el único que ejecuta) valida, comprueba
 * permisos y registra cada llamada.
 */
interface AITool
{
    /** Nombre único en snake_case (lo que la IA usa para llamarla). */
    public function name(): string;

    /** Para qué sirve y cuándo usarla: lo lee el modelo para decidir. */
    public function description(): string;

    /**
     * Esquema JSON de los argumentos (type object, additionalProperties
     * false, required). ToolExecutor valida contra él antes de ejecutar.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Ejecuta con argumentos ya validados. Los fallos esperados (no
     * encontrado, sin permiso...) se devuelven como ToolResult::error().
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ToolContext $context): ToolResult;
}
