<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SignupController;
use App\Http\Controllers\Api\V1\AutomationController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EmailController;
use App\Http\Controllers\Api\V1\EmailReviewController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\KnowledgeSearchController;
use App\Http\Controllers\Api\V1\MailConnectionController;
use App\Http\Controllers\Api\V1\MailOAuthController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ReviewHistoryController;
use App\Http\Controllers\Api\V1\ReviewQueueController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
// Panel de administración: sesión por cookie (Sanctum SPA) en vez de token.
Route::post('/auth/session', [AuthController::class, 'session'])->middleware('throttle:login');

// Vuelta del navegador desde Microsoft/Google: pública (sin nuestro token);
// empresa y usuario salen del "state" guardado al iniciar el flujo.
Route::get('/mail-connections/oauth/{provider}/callback', [MailOAuthController::class, 'callback'])
    ->middleware('throttle:30,1');

// Alta de empresas desde la web (Fase 17; solo con SAAS_SIGNUP_ENABLED).
Route::get('/signup', [SignupController::class, 'show'])->middleware('throttle:30,1');
Route::post('/signup', [SignupController::class, 'store'])->middleware('throttle:signup');
Route::post('/auth/verify-email/resend', [SignupController::class, 'resend'])->middleware('throttle:signup');
// El enlace del email: la firma (con caducidad) la comprueba el controlador.
Route::get('/auth/verify-email/{user}/{hash}', [SignupController::class, 'verify'])
    ->name('auth.verify-email')
    ->middleware('throttle:30,1');

// Avisos de Stripe (Fase 17): públicos, solo valen con su firma.
Route::post('/webhooks/stripe', StripeWebhookController::class)->middleware('throttle:120,1');

// Todo lo que requiere usuario autenticado (token Sanctum o cookie SPA) y
// se ejecuta dentro de su tenant.
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    // Cuenta propia. Piden la contraseña actual: límite bajo contra quien la adivina con una sesión robada.
    Route::put('/auth/password', [AccountController::class, 'changePassword'])->middleware('throttle:6,1');
    Route::post('/auth/logout-other-devices', [AccountController::class, 'logoutOtherDevices'])->middleware('throttle:6,1');

    Route::get('/tenant', [TenantController::class, 'show']);

    // Plan, suscripción y uso del mes (Fase 17).
    Route::get('/billing', [BillingController::class, 'show']);
    // Llaman a Stripe: límite bajo.
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1');
    Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware('throttle:10,1');

    // Primeros pasos de una empresa nueva (Fase 17).
    Route::get('/onboarding', [OnboardingController::class, 'show']);
    Route::post('/onboarding/dismiss', [OnboardingController::class, 'dismiss']);
    Route::patch('/tenant', [TenantController::class, 'update']);

    Route::apiResource('users', UserController::class);

    Route::apiResource('mail-connections', MailConnectionController::class)
        ->parameters(['mail-connections' => 'mailConnection']);
    Route::post('/mail-connections/oauth/{provider}/start', [MailOAuthController::class, 'start']);
    // Hace peticiones a servidores de fuera: mismo límite que probar una conexión.
    Route::post('/mail-connections/autodetect', [MailConnectionController::class, 'autodetect'])
        ->middleware('throttle:mail-connection-test');
    Route::post('/mail-connections/{mailConnection}/test', [MailConnectionController::class, 'test'])
        ->middleware('throttle:mail-connection-test');
    // Leer y elegir carpetas también pregunta al servidor de correo.
    Route::get('/mail-connections/{mailConnection}/folders', [MailConnectionController::class, 'folders'])
        ->middleware('throttle:mail-connection-test');
    Route::put('/mail-connections/{mailConnection}/folders', [MailConnectionController::class, 'updateFolders'])
        ->middleware('throttle:mail-connection-test');

    // Configuración de la IA de la empresa (modo, intenciones, herramientas).
    Route::get('/automation', [AutomationController::class, 'show']);
    Route::patch('/automation', [AutomationController::class, 'update']);
    Route::patch('/automation/tools/{tool}', [AutomationController::class, 'updateTool']);

    // Inicio del panel: resumen del periodo.
    Route::get('/dashboard', DashboardController::class);

    // Emails recibidos y lo que hizo la IA con ellos (panel, Fase 10).
    Route::get('/emails', [EmailController::class, 'index']);
    Route::get('/emails/{email}', [EmailController::class, 'show']);
    Route::delete('/emails/{email}', [EmailController::class, 'destroy']);
    Route::get('/emails/{email}/html', [EmailController::class, 'html']);
    // El adjunto tiene que ser de ese email (scopeBindings).
    Route::get('/emails/{email}/attachments/{attachment}', [EmailController::class, 'attachment'])->scopeBindings();

    // Revisión humana (Fase 13). La respuesta tiene que ser de ese email (scopeBindings).
    Route::post('/emails/{email}/replies/{reply}/approve', [EmailReviewController::class, 'approve'])->scopeBindings();
    Route::post('/emails/{email}/replies/{reply}/reject', [EmailReviewController::class, 'reject'])->scopeBindings();
    Route::post('/emails/{email}/replies', [EmailReviewController::class, 'reply']);
    Route::post('/emails/{email}/close', [EmailReviewController::class, 'close']);
    Route::post('/emails/{email}/reprocess', [EmailReviewController::class, 'reprocess']);
    Route::post('/emails/{email}/action/approve', [EmailReviewController::class, 'approveAction']);
    Route::post('/emails/{email}/action/reject', [EmailReviewController::class, 'rejectAction']);
    Route::put('/emails/{email}/assignee', [EmailReviewController::class, 'assign']);
    Route::post('/emails/{email}/review-checks', [EmailReviewController::class, 'check']);
    // Bandeja de revisión: lo que espera a una persona, lo urgente y lo más antiguo primero.
    Route::get('/review-queue', ReviewQueueController::class);
    // Historial de las decisiones de las personas y su resumen.
    Route::get('/reviews', [ReviewHistoryController::class, 'index']);
    Route::get('/reviews/summary', [ReviewHistoryController::class, 'summary']);

    // Base de conocimiento (RAG): documentación de la empresa y búsqueda.
    Route::apiResource('documents', DocumentController::class)->except(['update']);
    Route::post('/documents/{document}/reindex', [DocumentController::class, 'reindex']);
    Route::post('/knowledge-base/search', KnowledgeSearchController::class)->middleware('throttle:30,1');

    // Registro de auditoría (solo lectura): quién hizo qué en la empresa.
    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    // Pedidos importados por CSV (Fase 14): los consulta la IA por el servidor MCP.
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/template', [OrderController::class, 'template']);
    Route::post('/orders/import', [OrderController::class, 'import'])->middleware('throttle:10,1');
    Route::delete('/orders', [OrderController::class, 'destroyAll']);

    // Sistemas que conecta la empresa (Fase 14): su ERP (API estándar) o su tienda.
    Route::prefix('/integrations/{type}')->whereIn('type', array_keys(IntegrationController::TYPES))->group(function () {
        Route::get('/', [IntegrationController::class, 'show']);
        Route::put('/', [IntegrationController::class, 'update'])->middleware('throttle:10,1');
        Route::post('/test', [IntegrationController::class, 'test'])->middleware('throttle:10,1');
        Route::delete('/', [IntegrationController::class, 'destroy']);
    });
});
