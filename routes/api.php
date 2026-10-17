<?php


use App\Http\Controllers\API\DashboardController;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\NotificationPreferenceController;
use App\Http\Controllers\API\ProfileManagementController;
use App\Http\Controllers\API\UserRoleController;
use App\Http\Controllers\API\PermissionController;
use App\Http\Controllers\API\admin\ProjectController;
use App\Http\Controllers\API\admin\ProjectTaskController;
use App\Http\Controllers\API\admin\TaskAssigneeController;
use App\Http\Controllers\API\admin\TaskCommentController;
use App\Http\Controllers\API\admin\UserManagementController;
use App\Http\Controllers\API\admin\ProjectBriefController;
use App\Http\Controllers\API\admin\WorkSessionController;
use App\Http\Controllers\API\admin\SummaryController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\SubscriptionController;
use App\Http\Controllers\API\SlackController;
use App\Http\Controllers\OneDriveAuthController;
use App\Http\Controllers\API\ContractTemplateController;
use App\Http\Controllers\API\ContractController;
use App\Http\Controllers\API\PublicContractController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Manual cron trigger (for servers without shell/artisan access)
|--------------------------------------------------------------------------
| Lets a scheduled job be run on demand from a browser instead of the CLI.
| Guarded by CRON_TRIGGER_TOKEN in .env — if that variable is missing or
| empty the route 404s, so it stays completely closed until you opt in.
| Only an explicit allow-list of commands can be run.
*/
Route::get('/run-task/{job}', function (Request $request, string $job) {
    $expected = (string) env('CRON_TRIGGER_TOKEN', '');
    $given = (string) $request->query('token', '');

    if ($expected === '' || !hash_equals($expected, $given)) {
        abort(404);
    }

    $allowed = [
        'internee-grading' => 'internee:auto-zero-grading',
        'probation-reminders' => 'notify:probation-reminders',
        'due-reminders' => 'notify:due-reminders',
    ];

    if (!isset($allowed[$job])) {
        return response()->json(['ok' => false, 'message' => 'Unknown job.'], 404);
    }

    $params = [];
    if ($request->boolean('dry_run')) {
        $params['--dry-run'] = true;
    }
    if ($request->filled('internee')) {
        $params['--internee'] = (int) $request->query('internee');
    }

    @set_time_limit(300);
    $exit = Artisan::call($allowed[$job], $params);

    return response()->json([
        'ok' => $exit === 0,
        'job' => $allowed[$job],
        'dry_run' => $request->boolean('dry_run'),
        'output' => trim(Artisan::output()),
    ]);
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

// Public, NO-LOGIN contract view + accept (access is guarded solely by the
// unguessable token in the link — the recipient never logs in).
Route::get('/contracts/public/{token}', [PublicContractController::class, 'show']);
Route::post('/contracts/public/{token}/accept', [PublicContractController::class, 'accept']);

Route::post('/slack/interactions', [SlackController::class, 'handle']);

Route::post('/slack/jobs', [SlackController::class, 'jobsList']);

Route::get('/slack/tasks', [SlackController::class, 'tasksList']);

Route::post('/slack/options', [SlackController::class, 'optionsLoader']);


Route::get('/auth/onedrive', [OneDriveAuthController::class, 'redirect']);
Route::get('/auth/onedrive/callback', [OneDriveAuthController::class, 'callback']);



Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/my-notifications', [AuthController::class, 'myNotifications']);

    Route::post('/update-notification-read-status', [AuthController::class, 'updateReadStatusNotifications']);

    // Per-user email notification preferences (available to every authenticated user)
    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show']);
    Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update']);

    // Contracts module — fixed paths shared by Admins (role 2) and Executives
    // (role 3 + employee_type 'Executive'); authorization is enforced inside the
    // controllers (see AuthorizesContractManagers), not by route middleware.
    Route::get('/contract-variables', [ContractTemplateController::class, 'variables']);
    Route::apiResource('contract-templates', ContractTemplateController::class);
    Route::get('/contracts', [ContractController::class, 'index']);
    Route::post('/contracts', [ContractController::class, 'store']);
    Route::get('/contracts/{id}', [ContractController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/contracts/{id}', [ContractController::class, 'update'])->whereNumber('id');
    Route::patch('/contracts/{id}/status', [ContractController::class, 'updateStatus'])->whereNumber('id');
    Route::post('/contracts/{id}/resend', [ContractController::class, 'resend'])->whereNumber('id');
    Route::delete('/contracts/{id}', [ContractController::class, 'destroy'])->whereNumber('id');

    Route::post('/update-profile', [ProfileManagementController::class, 'updateProfile']);
    Route::post('/update-password', [ProfileManagementController::class, 'updatePassword']);
    Route::post('/update-profile-pic', [ProfileManagementController::class, 'updateProfilePic']);

    Route::resource('user-roles', UserRoleController::class);
    Route::resource('permissions', PermissionController::class);

    Route::post('/update-fcm-token', [AuthController::class, 'updateFCMToken']);


    Route::prefix('admin')->middleware('role:2')->group(function () {

        Route::post('/pin-task-comment', [TaskCommentController::class, 'pinComment']);
        Route::post('/unpin-task-comment', [TaskCommentController::class, 'unpinComment']);

        //Project with tasks and comments Module

        Route::resource('project', ProjectController::class);
        Route::get('/projects-with-tasks', [ProjectController::class, 'projectsWithTasks']);
        Route::resource('project-task', ProjectTaskController::class);
        Route::resource('task-assignee', TaskAssigneeController::class);
        Route::post('/bulk-assign', [TaskAssigneeController::class, 'bulkAssign']);
        Route::resource('task-comment', TaskCommentController::class);
        Route::get('/task-comment-with-customers', [App\Http\Controllers\API\admin\TaskCommentController::class, 'indexWithCustomer']);
        Route::post('/mark-comments-read', [TaskCommentController::class, 'markAllAsRead']);
        Route::post('/mark-reply-comments-read', [TaskCommentController::class, 'markAllRepliesAsRead']);
        Route::post('/change-project-task-order', [ProjectTaskController::class, 'changeOrder']);

        Route::resource('project-brief', ProjectBriefController::class);
        Route::resource('/task-brief', App\Http\Controllers\API\admin\TaskBriefController::class);

        //ending Project Module

        //User management
        Route::resource('employee-user', UserManagementController::class);
        Route::resource('supervisor-user', UserManagementController::class);
        Route::resource('customer-user', UserManagementController::class);
        Route::resource('admin-user', UserManagementController::class);
        //Ending user management

        //work session 
        Route::resource('/work-session', WorkSessionController::class);
        Route::post('/other-manual-time', [WorkSessionController::class, 'manualSession']);
        //ending work session

        Route::resource('/project-chat', App\Http\Controllers\API\admin\ProjectChatController::class);

        Route::get('/project-chat-with-customers/{id}', [App\Http\Controllers\API\admin\ProjectChatController::class, 'showWithCustomer']);

        //leaves 
        Route::resource('/leave-request', App\Http\Controllers\API\admin\LeaveRequestController::class);

        //dashboard
        Route::get('/dashboard', [App\Http\Controllers\API\DashboardController::class, 'adminStats']);

        Route::get('/all_subscriptions', [App\Http\Controllers\API\customer\SubscriptionController::class, 'adminAllSubscriptions']);

        //project assignees

        Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);

        Route::post('/update-project-status', [App\Http\Controllers\API\admin\ProjectController::class, 'updateStatus']);

        //customer team
        Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);


        //
        Route::get('/projects-with-members', [ProjectController::class, 'projectsWithMember']);


        Route::post('/delete-idle-time', [App\Http\Controllers\API\employee\ScreenshotController::class, 'deleteIdleTime']);


        //working hours
        Route::resource('/working-hours', App\Http\Controllers\API\admin\WorkingHourController::class);

        //checklist notes
        Route::resource('/notes', App\Http\Controllers\API\admin\NoteController::class);

        Route::post('/create-bulk-notes', [App\Http\Controllers\API\admin\NoteController::class, 'bulkStore']);

        Route::post('/update-note-status', [App\Http\Controllers\API\admin\NoteController::class, 'updateStatus']);


        Route::get('/all-tasks-chats', [App\Http\Controllers\API\admin\TaskCommentController::class, 'allTasksChats']);


        Route::post('/update-joining-date', [UserManagementController::class, 'updateJoiningDate']);

        Route::get('/fetch-activity-logs/{id}', [App\Http\Controllers\API\employee\WorkSessionController::class, 'fetchActivityLogs']);

        Route::get('/project-tasks-by-dates', [ProjectController::class, 'projectsWithTasksCalendar']);


        Route::get('/deleted-screenshots/{session_id}', [App\Http\Controllers\API\employee\ScreenshotController::class, 'deletedScreenshots']);

        Route::post('/delete-screenshot/{id}', [App\Http\Controllers\API\employee\ScreenshotController::class, 'destroy']);


        /*
         | Payroll & Salaries  (ADDITIVE — no existing route is modified, so an
         | older client that knows nothing about these paths is unaffected.)
         | The identical block is registered for Executives further down; see the
         | note there for why both prefixes are needed.
         */
        Route::get('/salary', [App\Http\Controllers\API\admin\SalaryController::class, 'index']);
        Route::get('/salary/{userId}', [App\Http\Controllers\API\admin\SalaryController::class, 'show']);
        Route::post('/salary/{userId}', [App\Http\Controllers\API\admin\SalaryController::class, 'setSalary']);

        Route::get('/payroll', [App\Http\Controllers\API\admin\PayrollController::class, 'index']);
        Route::get('/payroll-report', [App\Http\Controllers\API\admin\PayrollController::class, 'report']);
        Route::post('/payroll/generate', [App\Http\Controllers\API\admin\PayrollController::class, 'generate']);
        Route::get('/payroll/{id}', [App\Http\Controllers\API\admin\PayrollController::class, 'show']);
        Route::post('/payroll/{id}/submit', [App\Http\Controllers\API\admin\PayrollController::class, 'submit']);
        Route::post('/payroll/{id}/approve', [App\Http\Controllers\API\admin\PayrollController::class, 'approve']);
        Route::post('/payroll/{id}/reopen', [App\Http\Controllers\API\admin\PayrollController::class, 'reopen']);
        Route::post('/payroll/{id}/cancel', [App\Http\Controllers\API\admin\PayrollController::class, 'cancel']);
        Route::post('/payroll/{id}/pay-all', [App\Http\Controllers\API\admin\PayrollController::class, 'markRunPaid']);

        Route::get('/payslip/{payslipId}', [App\Http\Controllers\API\admin\PayrollController::class, 'showPayslip']);
        Route::post('/payslip/{payslipId}', [App\Http\Controllers\API\admin\PayrollController::class, 'updatePayslip']);
        Route::post('/payslip/{payslipId}/item', [App\Http\Controllers\API\admin\PayrollController::class, 'addItem']);
        Route::delete('/payslip/{payslipId}/item/{itemId}', [App\Http\Controllers\API\admin\PayrollController::class, 'deleteItem']);
        Route::post('/payslip/{payslipId}/pay', [App\Http\Controllers\API\admin\PayrollController::class, 'markPaid']);
        Route::post('/payslip/{payslipId}/unpay', [App\Http\Controllers\API\admin\PayrollController::class, 'unmarkPaid']);

        /*
         | Summary / reports page — company-wide hours, workload, task-status and
         | leave aggregates for one shared date range. Admin-only (role:2, this
         | block's middleware) for now.
         */
        Route::get('/summary', [SummaryController::class, 'index']);
        Route::get('/summary/employee/{employeeId}/average', [SummaryController::class, 'employeeAverage']);

        // Weekly employee evaluations — read-only company-wide view (filters: manager, employee, dates).
        Route::get('/evaluations/filters', [App\Http\Controllers\API\employee\EmployeeEvaluationController::class, 'filters']);
        Route::get('/evaluations', [App\Http\Controllers\API\employee\EmployeeEvaluationController::class, 'index']);

        // Org chart (admins + executives only - enforced in the controller).
        Route::get('/org-chart/people', [App\Http\Controllers\API\OrgChartController::class, 'people']);
        Route::get('/org-chart/photo/{id}', [App\Http\Controllers\API\OrgChartController::class, 'photo']);
        Route::get('/org-charts', [App\Http\Controllers\API\OrgChartController::class, 'index']);
        Route::post('/org-charts', [App\Http\Controllers\API\OrgChartController::class, 'store']);
        Route::get('/org-charts/{id}', [App\Http\Controllers\API\OrgChartController::class, 'show']);
        Route::delete('/org-charts/{id}', [App\Http\Controllers\API\OrgChartController::class, 'destroy']);

        // Project managers: which assignees manage a first-level task (a "project" in the UI); several allowed.
        Route::get('/task-managers', [App\Http\Controllers\API\employee\TaskManagerController::class, 'index']);
        Route::post('/task-managers', [App\Http\Controllers\API\employee\TaskManagerController::class, 'update']);

        // Monthly manager/executive ratings — admin sees every rating and who gave it.
        Route::get('/manager-evaluations', [App\Http\Controllers\API\employee\ManagerEvaluationController::class, 'adminIndex']);

    });


    Route::prefix('supervisor')->middleware('role:6')->group(function () {

        //Project with tasks and comments Module

        Route::resource('project', ProjectController::class);
        Route::get('/projects-with-tasks', [ProjectController::class, 'projectsWithTasks']);
        Route::resource('project-task', ProjectTaskController::class);
        Route::resource('task-assignee', TaskAssigneeController::class);
        Route::post('/bulk-assign', [TaskAssigneeController::class, 'bulkAssign']);
        Route::resource('task-comment', TaskCommentController::class);
        Route::get('/task-comment-with-customers', [App\Http\Controllers\API\admin\TaskCommentController::class, 'indexWithCustomer']);

        Route::post('/mark-comments-read', [TaskCommentController::class, 'markAllAsRead']);
        Route::post('/mark-reply-comments-read', [TaskCommentController::class, 'markAllRepliesAsRead']);
        Route::post('/change-project-task-order', [ProjectTaskController::class, 'changeOrder']);

        Route::resource('project-brief', ProjectBriefController::class);
        Route::resource('/task-brief', App\Http\Controllers\API\admin\TaskBriefController::class);

        //ending Project Module

        //User management
        Route::resource('employee-user', UserManagementController::class);
        Route::resource('supervisor-user', UserManagementController::class);
        Route::resource('customer-user', UserManagementController::class);
        Route::resource('admin-user', UserManagementController::class);
        //Ending user management

        //work session 
        Route::resource('/work-session', WorkSessionController::class);
        Route::post('/other-manual-time', [WorkSessionController::class, 'manualSession']);
        //ending work session

        Route::resource('/project-chat', App\Http\Controllers\API\admin\ProjectChatController::class);

        //leaves 
        Route::resource('/leave-request', App\Http\Controllers\API\admin\LeaveRequestController::class);

        //dashboard
        Route::get('/dashboard', [App\Http\Controllers\API\DashboardController::class, 'adminStats']);

        Route::get('/all_subscriptions', [App\Http\Controllers\API\customer\SubscriptionController::class, 'adminAllSubscriptions']);

        //project assignees

        Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);

        Route::post('/update-project-status', [App\Http\Controllers\API\admin\ProjectController::class, 'updateStatus']);

        //customer team
        Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);


        //
        Route::get('/projects-with-members', [ProjectController::class, 'projectsWithMember']);


        //checklist notes
        Route::resource('/notes', App\Http\Controllers\API\admin\NoteController::class);
        Route::post('/create-bulk-notes', [App\Http\Controllers\API\admin\NoteController::class, 'bulkStore']);
        Route::post('/update-note-status', [App\Http\Controllers\API\admin\NoteController::class, 'updateStatus']);


    });


    Route::prefix('employee')->middleware('role:3')->group(function () {

        /*
         | Average hours/day for the employee's OWN work diary.
         |
         | Plain role:3 on purpose — NOT inside any employeeType group. Every
         | employee needs their own average; the controller rejects a request
         | for anyone else's id unless the caller is an admin or executive,
         | so widening the route doesn't widen what anyone can see.
         */
        Route::get('/summary/employee/{employeeId}/average', [SummaryController::class, 'employeeAverage']);


        Route::post('/pin-task-comment', [TaskCommentController::class, 'pinComment']);
        Route::post('/unpin-task-comment', [TaskCommentController::class, 'unpinComment']);

        Route::get('/stats', [App\Http\Controllers\API\DashboardController::class, 'employeeStats']);


        //Project with tasks and comments Module

        Route::resource('/project', App\Http\Controllers\API\employee\ProjectController::class);

        Route::get('/projects-with-tasks', [App\Http\Controllers\API\employee\ProjectController::class, 'projectsWithTasks']);


        // Project module
        Route::resource('/project-task', App\Http\Controllers\API\employee\ProjectTaskController::class);
        Route::resource('/task-assignee', App\Http\Controllers\API\employee\TaskAssigneeController::class);
        Route::post('/bulk-assign', [App\Http\Controllers\API\employee\TaskAssigneeController::class, 'bulkAssign']);
        Route::resource('/task-comment', App\Http\Controllers\API\employee\TaskCommentController::class);
        Route::get('/task-comment-with-customers', [App\Http\Controllers\API\employee\TaskCommentController::class, 'indexWithCustomer']);

        Route::post('/mark-comments-read', [App\Http\Controllers\API\employee\TaskCommentController::class, 'markAllAsRead']);
        Route::post('/mark-reply-comments-read', [App\Http\Controllers\API\employee\TaskCommentController::class, 'markAllRepliesAsRead']);
        Route::post('/change-project-task-order', [App\Http\Controllers\API\employee\ProjectTaskController::class, 'changeOrder']);
        Route::resource('/project-brief', App\Http\Controllers\API\employee\ProjectBriefController::class);
        Route::resource('/task-brief', App\Http\Controllers\API\employee\TaskBriefController::class);

        // User management
        Route::resource('/employee-user', App\Http\Controllers\API\employee\UserManagementController::class);
        Route::resource('/customer-user', App\Http\Controllers\API\employee\UserManagementController::class);
        Route::resource('/admin-user', App\Http\Controllers\API\employee\UserManagementController::class);

        //work session
        Route::resource('/work-session', App\Http\Controllers\API\employee\WorkSessionController::class);

        Route::resource('/other-work-session', App\Http\Controllers\API\admin\WorkSessionController::class);

        Route::resource('/screenshot', App\Http\Controllers\API\employee\ScreenshotController::class);
        Route::post('/manual-time', [App\Http\Controllers\API\employee\WorkSessionController::class, 'manualSession']);
        Route::post('/other-manual-time', [WorkSessionController::class, 'manualSession']);
        Route::post('/stop-session', [App\Http\Controllers\API\employee\WorkSessionController::class, 'stop']);

        Route::post('/update-idle-time', [App\Http\Controllers\API\employee\ScreenshotController::class, 'upsertIdleTime']);

        //leaves 
        Route::resource('/leave-request', App\Http\Controllers\API\employee\LeaveRequestController::class);

        //other leaves 
        Route::resource('/other-leave-request', App\Http\Controllers\API\admin\LeaveRequestController::class);

        //project chats
        Route::resource('/project-chat', App\Http\Controllers\API\employee\ProjectChatController::class);

        Route::get('/project-chat-with-customers/{id}', [App\Http\Controllers\API\employee\ProjectChatController::class, 'showWithCustomer']);


        Route::post('/update-project-status', [App\Http\Controllers\API\admin\ProjectController::class, 'updateStatus']);

        //dashboard

        Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);

        Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);

        Route::middleware('employeeType:Manager')->group(function () {
            //project assignees

            //  Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);

            //customer team
            // Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);


            Route::post('/session-heartbeat', [App\Http\Controllers\API\employee\WorkSessionController::class, 'sessionHeartBeat']);



        });


        Route::middleware('employeeType:Supervisor')->group(function () {
            //project assignees

            // Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);

            //customer team
            // Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);

            Route::post('/session-heartbeat', [App\Http\Controllers\API\employee\WorkSessionController::class, 'sessionHeartBeat']);
        });

        Route::middleware('employeeType:Executive')->group(function () {
            Route::post('/session-heartbeat', [App\Http\Controllers\API\employee\WorkSessionController::class, 'sessionHeartBeat']);

            /*
             | Summary / reports for Executives. Same controller as the admin
             | routes — executives are user_role = 3, so they can only reach
             | it through the `employee` prefix (see the payroll note below).
             */
            Route::get('/summary', [SummaryController::class, 'index']);

            /*
             | NOTE: /summary/employee/{id}/average is deliberately NOT
             | registered here. It already exists under the plain role:3
             | group above, and Laravel matches the FIRST registration for a
             | URI — so a copy here would shadow it and apply
             | employeeType:Executive to every employee, 403-ing anyone who
             | isn't an executive. Executives are role:3, so the route above
             | already covers them.
             */

            /*
             | Payroll & Salaries for Executives.
             |
             | Executives are user_role = 3 (there are no role 6/7 accounts in this
             | system), so they reach the API through the `employee` prefix, not
             | `admin`. This mirrors the same controller methods behind
             | /api/employee/* and is gated to employee_type = Executive, so no
             | ordinary employee, manager or internee can see company payroll.
             |
             | Employees' own payslips are further down, outside this group.
             */
            Route::get('/salary', [App\Http\Controllers\API\admin\SalaryController::class, 'index']);
            Route::get('/salary/{userId}', [App\Http\Controllers\API\admin\SalaryController::class, 'show']);
            Route::post('/salary/{userId}', [App\Http\Controllers\API\admin\SalaryController::class, 'setSalary']);

            Route::get('/payroll', [App\Http\Controllers\API\admin\PayrollController::class, 'index']);
            Route::get('/payroll-report', [App\Http\Controllers\API\admin\PayrollController::class, 'report']);
            Route::post('/payroll/generate', [App\Http\Controllers\API\admin\PayrollController::class, 'generate']);
            Route::get('/payroll/{id}', [App\Http\Controllers\API\admin\PayrollController::class, 'show']);
            Route::post('/payroll/{id}/submit', [App\Http\Controllers\API\admin\PayrollController::class, 'submit']);
            Route::post('/payroll/{id}/approve', [App\Http\Controllers\API\admin\PayrollController::class, 'approve']);
            Route::post('/payroll/{id}/reopen', [App\Http\Controllers\API\admin\PayrollController::class, 'reopen']);
            Route::post('/payroll/{id}/cancel', [App\Http\Controllers\API\admin\PayrollController::class, 'cancel']);
            Route::post('/payroll/{id}/pay-all', [App\Http\Controllers\API\admin\PayrollController::class, 'markRunPaid']);

            Route::get('/payslip/{payslipId}', [App\Http\Controllers\API\admin\PayrollController::class, 'showPayslip']);
            Route::post('/payslip/{payslipId}', [App\Http\Controllers\API\admin\PayrollController::class, 'updatePayslip']);
            Route::post('/payslip/{payslipId}/item', [App\Http\Controllers\API\admin\PayrollController::class, 'addItem']);
            Route::delete('/payslip/{payslipId}/item/{itemId}', [App\Http\Controllers\API\admin\PayrollController::class, 'deleteItem']);
            Route::post('/payslip/{payslipId}/pay', [App\Http\Controllers\API\admin\PayrollController::class, 'markPaid']);
            Route::post('/payslip/{payslipId}/unpay', [App\Http\Controllers\API\admin\PayrollController::class, 'unmarkPaid']);
        });

        // Every employee can read THEIR OWN payslips (scoped to Auth::id() in the
        // controller). Deliberately named differently from the management routes
        // above so the two can never be confused.
        Route::get('/my-payslips', [App\Http\Controllers\API\employee\MyPayslipController::class, 'index']);
        Route::get('/my-payslips/{id}', [App\Http\Controllers\API\employee\MyPayslipController::class, 'show']);


        Route::get('/projects-with-members', [ProjectController::class, 'projectsWithMember']);


        Route::resource('/track-window', App\Http\Controllers\API\employee\TrackWindowController::class);

        //working hours
        Route::resource('/working-hours', App\Http\Controllers\API\admin\WorkingHourController::class);



        //checklist notes
        Route::resource('/notes', App\Http\Controllers\API\admin\NoteController::class);

        Route::post('/create-bulk-notes', [App\Http\Controllers\API\admin\NoteController::class, 'bulkStore']);

        Route::post('/update-note-status', [App\Http\Controllers\API\admin\NoteController::class, 'updateStatus']);

        Route::post('/session-heartbeat', [App\Http\Controllers\API\employee\WorkSessionController::class, 'sessionHeartBeat']);


        Route::get('/all-project-tasks', [App\Http\Controllers\API\employee\ProjectTaskController::class, 'allTasks']);

        Route::get('/all-tasks-chats', [App\Http\Controllers\API\employee\TaskCommentController::class, 'allTasksChats']);

        Route::get('/fetch-activity-logs/{id}', [App\Http\Controllers\API\employee\WorkSessionController::class, 'fetchActivityLogs']);

        Route::get('/all-tasks-chats', [App\Http\Controllers\API\employee\TaskCommentController::class, 'allTasksChats']);

        Route::get('/fetch-activity-logs/{id}', [App\Http\Controllers\API\employee\WorkSessionController::class, 'fetchActivityLogs']);

        // Internee rating module
        Route::get('/my-internees', [App\Http\Controllers\API\employee\InterneeRatingController::class, 'myInternees']);
        Route::get('/internee-rating-pending-check', [App\Http\Controllers\API\employee\InterneeRatingController::class, 'pendingCheck']);
        Route::resource('/internee-rating', App\Http\Controllers\API\employee\InterneeRatingController::class);

        // Weekly employee evaluations (reporting manager grades their team, per project).
        // Fixed paths only (no {id} route exists for this resource), so nothing can shadow them.
        Route::get('/evaluations/pending', [App\Http\Controllers\API\employee\EmployeeEvaluationController::class, 'pending']);
        Route::get('/evaluations/reportees', [App\Http\Controllers\API\employee\EmployeeEvaluationController::class, 'reportees']);
        Route::get('/evaluations/filters', [App\Http\Controllers\API\employee\EmployeeEvaluationController::class, 'filters']);
        Route::get('/evaluations', [App\Http\Controllers\API\employee\EmployeeEvaluationController::class, 'index']);
        Route::post('/evaluations', [App\Http\Controllers\API\employee\EmployeeEvaluationController::class, 'store']);

        // Tracker "week pulse" banner data (self only).
        Route::get('/work-pulse', [App\Http\Controllers\API\employee\WorkPulseController::class, 'show']);

        // Home dashboard: month leaderboard, team, team leaves, evaluations (read-only).
        Route::get('/home-stats', [App\Http\Controllers\API\employee\HomeStatsController::class, 'show']);

        // Org chart (admins + executives only - enforced in the controller).
        Route::get('/org-chart/people', [App\Http\Controllers\API\OrgChartController::class, 'people']);
        Route::get('/org-chart/photo/{id}', [App\Http\Controllers\API\OrgChartController::class, 'photo']);
        Route::get('/org-charts', [App\Http\Controllers\API\OrgChartController::class, 'index']);
        Route::post('/org-charts', [App\Http\Controllers\API\OrgChartController::class, 'store']);
        Route::get('/org-charts/{id}', [App\Http\Controllers\API\OrgChartController::class, 'show']);
        Route::delete('/org-charts/{id}', [App\Http\Controllers\API\OrgChartController::class, 'destroy']);

        // Project managers of a first-level task (read for everyone; set by admin/executive — enforced in the controller).
        Route::get('/task-managers', [App\Http\Controllers\API\employee\TaskManagerController::class, 'index']);
        Route::post('/task-managers', [App\Http\Controllers\API\employee\TaskManagerController::class, 'update']);

        // Monthly manager/executive ratings: what I owe, submit, and my own (anonymous) result.
        Route::get('/manager-evaluations/pending', [App\Http\Controllers\API\employee\ManagerEvaluationController::class, 'pending']);
        Route::get('/manager-evaluations/my-score', [App\Http\Controllers\API\employee\ManagerEvaluationController::class, 'myScore']);
        Route::post('/manager-evaluations', [App\Http\Controllers\API\employee\ManagerEvaluationController::class, 'store']);

    });


    Route::prefix('customer')->middleware('role:4')->group(function () {

        Route::post('/pin-task-comment', [TaskCommentController::class, 'pinComment']);
        Route::post('/unpin-task-comment', [TaskCommentController::class, 'unpinComment']);


        Route::resource('/project-chat', App\Http\Controllers\API\customer\ProjectChatController::class);

        Route::resource('/project', App\Http\Controllers\API\customer\ProjectController::class);

        Route::get('/projects-with-tasks', [App\Http\Controllers\API\customer\ProjectController::class, 'projectsWithTasks']);

        Route::resource('/work-session', App\Http\Controllers\API\customer\WorkSessionController::class);
        Route::resource('/screenshot', App\Http\Controllers\API\customer\ScreenshotController::class);
        Route::resource('/project-task', App\Http\Controllers\API\customer\ProjectTaskController::class);

        Route::middleware('auth:sanctum')->prefix('subscription')->group(function () {
            Route::get('/', [App\Http\Controllers\API\customer\SubscriptionController::class, 'index']);
            Route::get('/active', [App\Http\Controllers\API\customer\SubscriptionController::class, 'getActive']);
            Route::post('/create', [App\Http\Controllers\API\customer\SubscriptionController::class, 'store']);
            Route::post('/cancel/{id}', [App\Http\Controllers\API\customer\SubscriptionController::class, 'cancel']);

            Route::post('/payment-intent', [App\Http\Controllers\API\customer\SubscriptionController::class, 'reatePaymentIntent']);

            Route::get('/billing-detail', [App\Http\Controllers\API\customer\SubscriptionController::class, 'getBillingDetail']);

            Route::post('/update-billing-detail', [App\Http\Controllers\API\customer\SubscriptionController::class, 'updateBillingDetail']);
        });


        Route::middleware('auth:sanctum')->prefix('payment-method')->group(function () {
            Route::post('/attach', [App\Http\Controllers\API\customer\PaymentMethodController::class, 'attach']);
            Route::get('/list', [App\Http\Controllers\API\customer\PaymentMethodController::class, 'list']);
            Route::post('/set-default', [App\Http\Controllers\API\customer\PaymentMethodController::class, 'setDefault']);
            Route::delete('/delete/{paymentMethodId}', [App\Http\Controllers\API\customer\PaymentMethodController::class, 'delete']);

        });

        //dashboard
        Route::get('/dashboard', [App\Http\Controllers\API\DashboardController::class, 'customerStats']);

        //customer team
        Route::resource('/customer-team', App\Http\Controllers\API\customer\CustomerTeamController::class);


        Route::resource('/task-brief', App\Http\Controllers\API\employee\TaskBriefController::class);

        Route::resource('/task-comment', App\Http\Controllers\API\employee\TaskCommentController::class);

        Route::get('/task-comment-with-customers', [App\Http\Controllers\API\employee\TaskCommentController::class, 'indexWithCustomer']);


        //checklist notes
        Route::resource('/notes', App\Http\Controllers\API\admin\NoteController::class);
        Route::post('/create-bulk-notes', [App\Http\Controllers\API\admin\NoteController::class, 'bulkStore']);
        Route::post('/update-note-status', [App\Http\Controllers\API\admin\NoteController::class, 'updateStatus']);

    });


    Route::prefix('member')->middleware('role:5')->group(function () {

        Route::resource('/project-chat', App\Http\Controllers\API\customer\ProjectChatController::class);
        Route::resource('/project', App\Http\Controllers\API\customer\ProjectController::class);

        Route::get('/projects-with-tasks', [App\Http\Controllers\API\customer\ProjectController::class, 'projectsWithTasks']);

        Route::resource('/work-session', App\Http\Controllers\API\customer\WorkSessionController::class);
        Route::resource('/screenshot', App\Http\Controllers\API\customer\ScreenshotController::class);
        Route::resource('/project-task', App\Http\Controllers\API\customer\ProjectTaskController::class);

        //customer team
        Route::resource('/customer-team', App\Http\Controllers\API\customerteam\CustomerTeamController::class);
        Route::post('/update-team-status', [App\Http\Controllers\API\customerteam\CustomerTeamController::class, 'updateTeamStatus']);


        //checklist notes
        Route::resource('/notes', App\Http\Controllers\API\admin\NoteController::class);
        Route::post('/create-bulk-notes', [App\Http\Controllers\API\admin\NoteController::class, 'bulkStore']);
        Route::post('/update-note-status', [App\Http\Controllers\API\admin\NoteController::class, 'updateStatus']);


    });



});







Route::prefix('chat')->middleware('auth:sanctum')->group(function () {
    Route::post('send', [ChatController::class, 'store']);
    Route::post('update/{id}', [ChatController::class, 'update']);
    Route::post('reaction', [ChatController::class, 'addReaction']);
    Route::post('remove-reaction', [ChatController::class, 'removeReaction']);
    Route::post('read-status', [ChatController::class, 'markRead']);
    Route::post('bulk-read-status', [ChatController::class, 'bulkMarkRead']);
    Route::get('conversations/{userId}', [ChatController::class, 'getConversation']);
    Route::get('unread-count', [ChatController::class, 'unreadCount']);
    Route::get('users-list', [ChatController::class, 'getChatUsersList']);
    Route::delete('delete/{id}', [ChatController::class, 'deleteMessage']);
});


//Route::get('/logout-other/{id}', [App\Http\Controllers\API\AuthController::class, 'logoutOtherUser']);


Route::get('/test-data', [ProjectController::class, 'testData']);

Route::get('/tracker-version', [App\Http\Controllers\API\DashboardController::class, 'trackerVersion']);

