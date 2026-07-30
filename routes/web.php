<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DivisionController;
use App\Http\Controllers\Admin\HierarchyController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\PasswordManagementController;
use App\Http\Controllers\Admin\RecipientAliasController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\SecurityController;
use App\Http\Controllers\Dashboards\AdminDashboardController;
use App\Http\Controllers\Dashboards\DepartmentDashboardController;
use App\Http\Controllers\Dashboards\ExecutiveDashboardController;
use App\Http\Controllers\Dashboards\OfficerDashboardController;
use App\Http\Controllers\Dashboards\SecretaryOfficeDashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Mail\MailAssignmentController;
use App\Http\Controllers\Mail\MailAttachmentController;
use App\Http\Controllers\Mail\MailRecipientSearchController;
use App\Http\Controllers\Mail\MailRecordController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Oversight\CorrespondenceController;
use App\Http\Controllers\Oversight\OfficerLookupController;
use App\Http\Controllers\Oversight\OfficerPerformanceController;
use App\Http\Controllers\Oversight\ReportController;
use App\Http\Controllers\PwaManifestController;
use App\Http\Controllers\Tasks\AnnotationController;
use App\Http\Controllers\Tasks\AssigneeSearchController;
use App\Http\Controllers\Tasks\AssignmentWorkflowController;
use App\Http\Controllers\Tasks\EvidenceController;
use App\Http\Controllers\Tasks\ProgressController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\Tasks\WorkstreamController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/home');

// PWA manifest — public and unauthenticated: browsers fetch it without
// credentials when installing the app. Served from config/pwa.php.
Route::get('manifest.webmanifest', PwaManifestController::class)->name('pwa.manifest');

Route::middleware(['auth', 'password.change'])->group(function () {
    Route::get('home', HomeController::class)->name('home');

    // Self-service password change (also the forced-change landing page).
    Route::get('password/change', [ChangePasswordController::class, 'show'])->name('password.change');
    Route::post('password/change', [ChangePasswordController::class, 'store'])->name('password.change.store');
    Route::get('security', SecurityController::class)
        ->middleware('password.confirm')
        ->name('security.show');

    // Notifications
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    // Tasks / assignments — server-side scoped per role.
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::post('workstreams', [WorkstreamController::class, 'store'])->name('workstreams.store');
    Route::get('tasks/assignee-search', AssigneeSearchController::class)->name('tasks.assignee-search');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::post('tasks/{task}/progress', [ProgressController::class, 'store'])->name('tasks.progress.store');
    Route::post('tasks/{task}/annotations', [AnnotationController::class, 'store'])->name('tasks.annotations.store');
    Route::post('tasks/{task}/delegate', [AssignmentWorkflowController::class, 'delegate'])->name('tasks.workflow.delegate');
    Route::post('tasks/{task}/submit', [AssignmentWorkflowController::class, 'submit'])->name('tasks.workflow.submit');
    Route::post('assignment-submissions/{submission}/review', [AssignmentWorkflowController::class, 'review'])->name('tasks.workflow.review');
    Route::post('tasks/{task}/reassign', [AssignmentWorkflowController::class, 'reassign'])->name('tasks.workflow.reassign');
    Route::post('tasks/{task}/unassign', [AssignmentWorkflowController::class, 'unassign'])->name('tasks.workflow.unassign');
    Route::get('evidence/{evidence}/download', [EvidenceController::class, 'download'])->name('evidence.download');
    Route::get('evidence/{evidence}/preview', [EvidenceController::class, 'preview'])->name('evidence.preview');

    // The PS office and secretary accounts may browse and search both full
    // registers. Capture and assignment remain limited to the registry team.
    Route::get('mail/{mail}', [MailRecordController::class, 'show'])->name('mail.show');
    Route::get('mail-attachments/{attachment}/download', [MailAttachmentController::class, 'download'])->name('mail.attachments.download');
    Route::get('mail-attachments/{attachment}/preview', [MailAttachmentController::class, 'preview'])->name('mail.attachments.preview');

    Route::middleware('capability:mail.view,sysadmin,ps,clerk,commissioner,secretary')->group(function () {
        Route::get('incoming-mail', [MailRecordController::class, 'incoming'])->name('mail.incoming.index');
        Route::get('outgoing-mail', [MailRecordController::class, 'outgoing'])->name('mail.outgoing.index');
    });

    Route::middleware('capability:mail.manage,sysadmin,ps,clerk,secretary')->group(function () {
        Route::post('incoming-mail', [MailRecordController::class, 'storeIncoming'])->name('mail.incoming.store');
        Route::put('incoming-mail/{mail}', [MailRecordController::class, 'updateIncoming'])->name('mail.incoming.update');
        Route::post('outgoing-mail', [MailRecordController::class, 'storeOutgoing'])->name('mail.outgoing.store');
        Route::put('mail/{mail}', [MailRecordController::class, 'update'])->name('mail.update');
        Route::post('mail/{mail}/status', [MailRecordController::class, 'transition'])->name('mail.transition');
    });
    Route::middleware('capability:mail.assign,sysadmin,ps,clerk,commissioner,secretary')->group(function () {
        Route::get('incoming-mail/{mail}/recipient-search', MailRecipientSearchController::class)->name('mail.recipient-search');
        Route::post('incoming-mail/{mail}/assign', [MailAssignmentController::class, 'store'])->name('mail.assign');
    });

    Route::middleware('capability:admin.access,sysadmin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('dashboard', AdminDashboardController::class)->name('dashboard');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('users/{user}/restore', [UserController::class, 'restore'])->name('users.restore');
        Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::post('users/{user}/reset-password', [PasswordManagementController::class, 'reset'])->name('users.reset-password');
        Route::post('users/{user}/toggle-lock', [PasswordManagementController::class, 'toggleLock'])->name('users.toggle-lock');
        Route::post('users/{user}/force-change', [PasswordManagementController::class, 'forceChange'])->name('users.force-change');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::post('roles/{role}/toggle', [RoleController::class, 'toggle'])->name('roles.toggle');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

        Route::get('recipient-aliases', [RecipientAliasController::class, 'index'])->name('recipient-aliases.index');
        Route::post('recipient-aliases', [RecipientAliasController::class, 'store'])->name('recipient-aliases.store');
        Route::put('recipient-aliases/{recipientAlias}', [RecipientAliasController::class, 'update'])->name('recipient-aliases.update');
        Route::post('recipient-aliases/{recipientAlias}/toggle', [RecipientAliasController::class, 'toggle'])->name('recipient-aliases.toggle');

        Route::get('hierarchy', [HierarchyController::class, 'index'])->name('hierarchy.index');
        Route::post('hierarchy/units', [HierarchyController::class, 'storeUnit'])->name('hierarchy.units.store');
        Route::put('hierarchy/units/{unit}', [HierarchyController::class, 'updateUnit'])->name('hierarchy.units.update');
        Route::post('hierarchy/positions', [HierarchyController::class, 'storePosition'])->name('hierarchy.positions.store');
        Route::put('hierarchy/positions/{position}', [HierarchyController::class, 'updatePosition'])->name('hierarchy.positions.update');
        Route::post('hierarchy/appointments', [HierarchyController::class, 'assignUser'])->name('hierarchy.appointments.store');
        Route::post('hierarchy/delegations', [HierarchyController::class, 'storeDelegation'])->name('hierarchy.delegations.store');
        Route::post('hierarchy/secretary-attachments', [HierarchyController::class, 'assignSecretary'])->name('hierarchy.secretary-attachments.store');

        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::post('departments/{department}/toggle-active', [DepartmentController::class, 'toggleActive'])->name('departments.toggle-active');
        Route::get('divisions', [DivisionController::class, 'index'])->name('divisions.index');
        Route::post('divisions', [DivisionController::class, 'store'])->name('divisions.store');
        Route::put('divisions/{division}', [DivisionController::class, 'update'])->name('divisions.update');
        Route::post('divisions/{division}/toggle-active', [DivisionController::class, 'toggle'])->name('divisions.toggle-active');

        Route::get('audit-log', AuditLogController::class)->name('audit.index');
        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('imports/template', [ImportController::class, 'template'])->name('imports.template');
        Route::get('imports/{batch}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('imports/{batch}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');

        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/purge-demo-data', [SettingsController::class, 'purgeDemoData'])
            ->middleware('password.confirm')
            ->name('settings.purge');
    });

    Route::middleware('role:ps')->prefix('executive')->name('exec.')->group(function () {
        Route::get('dashboard', ExecutiveDashboardController::class)->name('dashboard');
    });

    Route::middleware('role:commissioner|secretary')->prefix('department')->name('dept.')->group(function () {
        Route::get('dashboard', DepartmentDashboardController::class)->name('dashboard');
    });

    Route::middleware('role:officer')->prefix('my')->name('officer.')->group(function () {
        Route::get('dashboard', OfficerDashboardController::class)->name('dashboard');
    });

    Route::prefix('secretary-office')->name('secretary.')->group(function () {
        Route::get('dashboard', SecretaryOfficeDashboardController::class)->name('dashboard');
        Route::post('schedule', [SecretaryOfficeDashboardController::class, 'storeSchedule'])->name('schedule.store');
        Route::delete('schedule/{scheduleItem}', [SecretaryOfficeDashboardController::class, 'destroySchedule'])->name('schedule.destroy');
    });

    Route::middleware('role:sysadmin|ps|clerk|commissioner|secretary')->group(function () {
        Route::get('officer-lookup', OfficerLookupController::class)->name('lookup.index');
    });

    Route::middleware('role:sysadmin|ps|commissioner|secretary')->group(function () {
        Route::get('officer-performance', [OfficerPerformanceController::class, 'index'])->name('performance.index');
        Route::get('officer-performance/{user}', [OfficerPerformanceController::class, 'show'])->name('performance.show');
    });

    // Annotation feed is safe for every authenticated role because the
    // controller derives its task IDs exclusively through TaskScope.
    Route::get('correspondence', CorrespondenceController::class)->name('correspondence.index');

    Route::middleware('role:sysadmin|ps|commissioner|secretary')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
    });
});

require __DIR__.'/auth.php';
