<?php

use App\Http\Controllers\Api\AgentIngressController;
use App\Http\Controllers\Api\AgentReleaseController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PluginController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\StagingController;
use App\Http\Middleware\EnsureOrganizationAccess;
use App\Http\Middleware\VerifyAgentHmac;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

// Public pairing (one-time code)
Route::post('/agent/pair', [AgentIngressController::class, 'pair']);
Route::get('/agent-releases/latest', [AgentReleaseController::class, 'latest']);
Route::get('/agent-releases/download', [AgentReleaseController::class, 'download']);
Route::get('/connection-info', [ConnectionController::class, 'info']);

// HMAC-protected agent ingress
Route::prefix('agent')->middleware(VerifyAgentHmac::class)->group(function () {
    Route::post('heartbeat', [AgentIngressController::class, 'heartbeat']);
    Route::post('events', [AgentIngressController::class, 'events']);
    Route::post('jobs/{jobId}/progress', [AgentIngressController::class, 'jobProgress']);
    Route::post('jobs/{jobId}/result', [AgentIngressController::class, 'jobResult']);
    // Off-site backup storage on the WWC server
    Route::post('backups/init', [\App\Http\Controllers\Api\AgentBackupController::class, 'init']);
    Route::post('backups/chunk', [\App\Http\Controllers\Api\AgentBackupController::class, 'chunk']);
    Route::post('backups/complete', [\App\Http\Controllers\Api\AgentBackupController::class, 'complete']);
    Route::get('backups/download', [\App\Http\Controllers\Api\AgentBackupController::class, 'download']);
    Route::post('backups/delete', [\App\Http\Controllers\Api\AgentBackupController::class, 'delete']);
});

Route::middleware(['auth:sanctum', EnsureOrganizationAccess::class])->group(function () {
    Route::get('/dashboard', [SiteController::class, 'dashboard']);
    Route::get('/plugin/download', [PluginController::class, 'download']);
    Route::get('/organization', [OrganizationController::class, 'show']);
    Route::put('/organization', [OrganizationController::class, 'update']);
    Route::post('/jobs/{id}/cancel', [JobController::class, 'cancel']);

    Route::get('/sites', [SiteController::class, 'index']);
    Route::post('/sites', [SiteController::class, 'store']);
    Route::get('/sites/{id}', [SiteController::class, 'show']);
    Route::delete('/sites/{id}', [SiteController::class, 'destroy']);
    Route::post('/sites/{id}/pairing-code', [SiteController::class, 'pairingCode']);
    Route::post('/sites/{id}/reconnect', [SiteController::class, 'reconnect']);
    Route::post('/sites/{id}/commands', [SiteController::class, 'dispatchCommand']);
    Route::post('/sites/{id}/rotate-secret', [SiteController::class, 'rotateSecret']);
    Route::post('/sites/{id}/staging/grant-admin', [StagingController::class, 'grantAdmin']);
    Route::get('/sites/{id}/maintenance', [MaintenanceController::class, 'show']);
    Route::put('/sites/{id}/maintenance', [MaintenanceController::class, 'updateSettings']);
    Route::post('/sites/{id}/maintenance/run', [MaintenanceController::class, 'run']);
    Route::post('/sites/{id}/maintenance/runs/{runId}/execute', [MaintenanceController::class, 'executePlan']);
    Route::put('/sites/{id}/backup-schedule', [SiteController::class, 'updateBackupSchedule']);
    Route::post('/sites/{id}/dev-clone', [\App\Http\Controllers\Api\DevCloneController::class, 'create']);
    Route::delete('/sites/{id}/dev-clone', [\App\Http\Controllers\Api\DevCloneController::class, 'destroy']);
    Route::get('/sites/{siteId}/backups/latest/download', [BackupController::class, 'downloadLatest']);
    Route::get('/sites/{siteId}/backups/{backupId}/download', [BackupController::class, 'download']);
    Route::get('/staging/{slug}', [StagingController::class, 'showBySlug']);

    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{id}', [ClientController::class, 'show']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{id}', [ProjectController::class, 'show']);
    Route::put('/projects/{id}', [ProjectController::class, 'update']);
    Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);
    Route::post('/projects/{id}/reconnect', [ProjectController::class, 'reconnect']);

    Route::get('/onboarding/tiers', [OnboardingController::class, 'tiers']);
    Route::post('/onboarding/impressum', [OnboardingController::class, 'analyzeImpressum']);
    Route::post('/onboarding/projects', [OnboardingController::class, 'createProject']);
    Route::get('/onboarding/sites/{siteId}', [OnboardingController::class, 'siteStatus']);

    Route::get('/security/findings', [SecurityController::class, 'findings']);
    Route::get('/security/failed-logins', [SecurityController::class, 'failedLogins']);
    Route::post('/security/sync', [SecurityController::class, 'syncAdvisories']);
    Route::post('/security/sites/{siteId}/scan', [SecurityController::class, 'scanSite']);
    Route::post('/security/sites/{siteId}/auto-fix', [SecurityController::class, 'autoFix']);
    Route::post('/security/findings/{id}/ignore', [SecurityController::class, 'ignoreFinding']);

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices/generate', [InvoiceController::class, 'generate']);
    Route::get('/invoices/export.csv', [InvoiceController::class, 'exportCsv']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{id}/paid', [InvoiceController::class, 'markPaid']);
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf']);
});
