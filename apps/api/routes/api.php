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
use App\Http\Middleware\RequireAdminRole;
use App\Http\Middleware\VerifyAgentHmac;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('password', [AuthController::class, 'changePassword']);
        Route::post('2fa/setup', [AuthController::class, 'twoFactorSetup']);
        Route::post('2fa/enable', [AuthController::class, 'twoFactorEnable']);
        Route::post('2fa/disable', [AuthController::class, 'twoFactorDisable']);
    });
    Route::post('invite/accept', [\App\Http\Controllers\Api\TeamController::class, 'accept']);
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
    Route::get('/dashboard', [\App\Http\Controllers\Api\OpsController::class, 'dashboard']);
    Route::get('/release', [\App\Http\Controllers\Api\ReleaseController::class, 'show']);
    Route::post('/release/deploy', [\App\Http\Controllers\Api\ReleaseController::class, 'deploy'])->middleware(RequireAdminRole::class);
    Route::get('/reviews', [\App\Http\Controllers\Api\OpsController::class, 'reviews']);
    Route::post('/reviews/{id}/approve', [\App\Http\Controllers\Api\OpsController::class, 'approveReview']);
    Route::post('/reviews/{id}/dismiss', [\App\Http\Controllers\Api\OpsController::class, 'dismissReview']);
    Route::post('/sites/bulk', [\App\Http\Controllers\Api\OpsController::class, 'bulk']);
    Route::post('/sites/{id}/freeze', [\App\Http\Controllers\Api\OpsController::class, 'freeze']);
    Route::post('/sites/{id}/rollback', [\App\Http\Controllers\Api\OpsController::class, 'rollback']);
    Route::post('/sites/{id}/probe', [\App\Http\Controllers\Api\OpsController::class, 'probe']);
    Route::post('/sites/{id}/hardening/policy', [\App\Http\Controllers\Api\OpsController::class, 'applyHardeningPolicy']);
    Route::put('/hardening-templates', [\App\Http\Controllers\Api\OpsController::class, 'saveHardeningTemplate'])->middleware(RequireAdminRole::class);
    Route::get('/audit-logs', [\App\Http\Controllers\Api\OpsController::class, 'auditLogs']);
    Route::get('/activity', [\App\Http\Controllers\Api\ActivityController::class, 'index']);
    Route::put('/sites/{id}/activity-guard', [\App\Http\Controllers\Api\ActivityController::class, 'updateGuard']);
    Route::get('/projects/{id}/report', [\App\Http\Controllers\Api\OpsController::class, 'monthlyReport']);
    Route::get('/team', [\App\Http\Controllers\Api\TeamController::class, 'index']);
    Route::post('/team/invites', [\App\Http\Controllers\Api\TeamController::class, 'invite'])->middleware(RequireAdminRole::class);
    Route::put('/team/members/{id}', [\App\Http\Controllers\Api\TeamController::class, 'updateRole'])->middleware(RequireAdminRole::class);
    Route::delete('/team/members/{id}', [\App\Http\Controllers\Api\TeamController::class, 'destroy'])->middleware(RequireAdminRole::class);
    Route::delete('/team/invites/{id}', [\App\Http\Controllers\Api\TeamController::class, 'revokeInvite'])->middleware(RequireAdminRole::class);
    Route::get('/time-entries', [\App\Http\Controllers\Api\TimeEntryController::class, 'index']);
    Route::post('/time-entries', [\App\Http\Controllers\Api\TimeEntryController::class, 'store']);
    Route::delete('/time-entries/{id}', [\App\Http\Controllers\Api\TimeEntryController::class, 'destroy']);
    Route::get('/plugin/download', [PluginController::class, 'download']);
    Route::get('/organization', [OrganizationController::class, 'show']);
    Route::put('/organization', [OrganizationController::class, 'update'])->middleware(RequireAdminRole::class);
    Route::post('/jobs/{id}/cancel', [JobController::class, 'cancel']);

    Route::get('/sites', [SiteController::class, 'index']);
    Route::post('/sites', [SiteController::class, 'store']);
    Route::get('/sites/{id}', [SiteController::class, 'show']);
    Route::delete('/sites/{id}', [SiteController::class, 'destroy'])->middleware(RequireAdminRole::class);
    Route::post('/sites/{id}/pairing-code', [SiteController::class, 'pairingCode']);
    Route::post('/sites/{id}/reconnect', [SiteController::class, 'reconnect']);
    Route::post('/sites/{id}/commands', [SiteController::class, 'dispatchCommand']);
    Route::post('/sites/{id}/rotate-secret', [SiteController::class, 'rotateSecret'])->middleware(RequireAdminRole::class);
    Route::post('/sites/{id}/staging/grant-admin', [StagingController::class, 'grantAdmin']);
    Route::get('/sites/{id}/maintenance', [MaintenanceController::class, 'show']);
    Route::put('/sites/{id}/maintenance', [MaintenanceController::class, 'updateSettings']);
    Route::post('/sites/{id}/maintenance/run', [MaintenanceController::class, 'run']);
    Route::post('/sites/{id}/maintenance/runs/{runId}/execute', [MaintenanceController::class, 'executePlan']);
    Route::put('/sites/{id}/backup-schedule', [SiteController::class, 'updateBackupSchedule']);
    Route::put('/sites/{id}/hardening', [SiteController::class, 'updateHardening']);
    Route::post('/sites/{id}/dev-clone', [\App\Http\Controllers\Api\DevCloneController::class, 'create']);
    Route::post('/sites/{id}/dev-clone/dry-run', [\App\Http\Controllers\Api\DevCloneController::class, 'dryRun']);
    Route::delete('/sites/{id}/dev-clone', [\App\Http\Controllers\Api\DevCloneController::class, 'destroy']);
    Route::get('/sites/{siteId}/backups/latest/download', [BackupController::class, 'downloadLatest']);
    Route::get('/sites/{siteId}/backups/{backupId}/download', [BackupController::class, 'download']);
    Route::get('/staging/{slug}', [StagingController::class, 'showBySlug']);

    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{id}', [ClientController::class, 'show']);
    Route::put('/clients/{id}', [ClientController::class, 'update']);
    Route::delete('/clients/{id}', [ClientController::class, 'destroy'])->middleware(RequireAdminRole::class);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{id}', [ProjectController::class, 'show']);
    Route::put('/projects/{id}', [ProjectController::class, 'update']);
    Route::delete('/projects/{id}', [ProjectController::class, 'destroy'])->middleware(RequireAdminRole::class);
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
    Route::post('/invoices/generate', [InvoiceController::class, 'generate'])->middleware(RequireAdminRole::class);
    Route::get('/invoices/export.csv', [InvoiceController::class, 'exportCsv']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{id}/send', [InvoiceController::class, 'send'])->middleware(RequireAdminRole::class);
    Route::post('/invoices/{id}/paid', [InvoiceController::class, 'markPaid'])->middleware(RequireAdminRole::class);
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf']);
});
