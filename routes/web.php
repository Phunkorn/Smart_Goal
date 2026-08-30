<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AdminApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MyTaskController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectTaskRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskAttachmentController;
use App\Http\Controllers\TaskCollaboratorController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskProgressController;
use App\Http\Controllers\TaskStatusController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkBoardController;
use App\Models\WorkOrderListTaskRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| หน้าเริ่มต้นของระบบ
|--------------------------------------------------------------------------
*/

Route::redirect('/', '/login');

/*
|--------------------------------------------------------------------------
| การเข้าสู่ระบบและออกจากระบบ
|--------------------------------------------------------------------------
*/

Route::get('/login', [AuthController::class, 'showLogin'])
    ->name('login');

Route::post('/login', [AuthController::class, 'login'])
    ->name('login.submit');

Route::post('/logout', [AuthController::class, 'logout'])
    ->name('logout');

/*
|--------------------------------------------------------------------------
| การตั้งค่าบัญชีเมื่อเข้าสู่ระบบครั้งแรก
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active', 'password.changed'])->group(function () {

    Route::get('/setup-password', function () {
        return view('auth.setup-password');
    })->name('password.setup');

    Route::post('/setup-password', [
        AuthController::class,
        'updateFirstPassword',
    ])->name('password.update.first');

    Route::get('/welcome', [AuthController::class, 'welcome'])
        ->name('welcome');
});

/*
|--------------------------------------------------------------------------
| เส้นทางภายในระบบสำหรับผู้ใช้ที่ผ่านการยืนยันตัวตน
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active', 'password.changed'])->group(function () {

    Route::resource('meetings', MeetingController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    // หน้าหลักและบอร์ดภาพรวม
    Route::redirect('/dashboard', '/board')
        ->name('dashboard');

    Route::get('/board', [TaskController::class, 'index'])
        ->name('board.index');

    // การจัดการพนักงาน
    Route::get('/employees', [UserController::class, 'index'])
        ->name('employees.index');

    Route::post('/employees', [UserController::class, 'store'])
        ->middleware('admin')
        ->name('employees.store');

    Route::patch('/employees/{user}', [UserController::class, 'update'])
        ->middleware('admin')
        ->name('employees.update');

    Route::delete('/employees/{user}', [UserController::class, 'destroy'])
        ->middleware('admin')
        ->name('employees.destroy');

    Route::patch('/employees/{user}/reset-password', [UserController::class, 'resetPassword'])
        ->middleware('admin')
        ->name('employees.resetPassword');

    Route::prefix('admin/departments')->name('admin.departments.')->middleware('admin')->group(function () {
        Route::get('/', [DepartmentController::class, 'index'])->name('index');
        Route::post('/', [DepartmentController::class, 'store'])->name('store');
        Route::patch('/{department}', [DepartmentController::class, 'update'])->name('update');
        Route::delete('/{department}', [DepartmentController::class, 'destroy'])->name('destroy');
    });

    // การอนุมัติและจัดการงานโดยผู้ดูแลระบบ
    Route::get('/admin/approvals', [AdminApprovalController::class, 'index'])
        ->middleware('admin')
        ->name('admin.approvals.index');

    Route::patch('/admin/tasks/{id}/approval', [TaskStatusController::class, 'updateApproval'])
        ->middleware('admin')
        ->name('admin.tasks.approval');

    Route::patch('/admin/tasks/{id}/collaborators/{user}/approval', [TaskCollaboratorController::class, 'decideCollaborator'])
        ->middleware('admin')
        ->name('admin.tasks.collaborators.approval');

    Route::delete('/admin/tasks/{id}', [TaskController::class, 'destroy'])
        ->middleware('admin')
        ->name('admin.tasks.destroy');

    Route::patch('/admin/tasks/{id}/delete-request', [TaskController::class, 'approveDeleteRequest'])
        ->middleware('admin')
        ->name('admin.tasks.deleteRequest.approve');

    Route::patch('/admin/tasks/{id}/delete-request/reject', [TaskController::class, 'rejectDeleteRequest'])
        ->middleware('admin')
        ->name('admin.tasks.deleteRequest.reject');

    // ประวัติการใช้งานและถังขยะของผู้ดูแลระบบ
    Route::get('/admin/activity-logs', [ActivityLogController::class, 'index'])
        ->middleware('admin')
        ->name('admin.activity-logs.index');

    Route::get('/admin/trash', [TrashController::class, 'index'])
        ->middleware('admin')
        ->name('admin.trash.index');

    Route::get('/admin/trash/export', [TrashController::class, 'export'])
        ->middleware('admin')
        ->name('admin.trash.export');

    Route::patch('/admin/trash/{trash}/restore', [TrashController::class, 'restore'])
        ->middleware('admin')
        ->name('admin.trash.restore');

    Route::patch('/tasks/{id}/invitation', [TaskCollaboratorController::class, 'respondInvitation'])
        ->name('tasks.invitation.respond');

    // การตั้งค่าส่วนตัว
    Route::get('/settings', [SettingsController::class, 'index'])
        ->name('settings.index');

    Route::patch('/settings', [SettingsController::class, 'update'])
        ->name('settings.update');

    Route::patch('/settings/password', [SettingsController::class, 'updatePassword'])
        ->name('settings.password.update');

    // รายงานของผู้ใช้ปัจจุบัน
    Route::get('/my-reports', [ReportController::class, 'myReport'])
        ->name('reports.my');

    Route::get('/my-reports/export.csv', [ReportController::class, 'exportMyCsv'])
        ->name('reports.myExportCsv');

    Route::get('/media/profile-images/{user}', [MediaController::class, 'profile'])
        ->name('media.profile');
    Route::get('/media/task-attachments/{attachment}', [MediaController::class, 'taskAttachment'])
        ->name('media.task-attachments.show');
    Route::get('/media/project-attachments/{attachment}', [MediaController::class, 'projectAttachment'])
        ->name('media.project-attachments.show');
    Route::get('/media/{path}', [MediaController::class, 'legacy'])
        ->where('path', '.*')
        ->name('media.show');
    // รายงานภาพรวมและรายงานรายพนักงาน
    Route::get('/reports', [ReportController::class, 'index'])
        ->name('reports.index');

    Route::get('/reports/organization', [ReportController::class, 'organization'])
        ->name('reports.organization');

    Route::get('/reports/organization/export.csv', [ReportController::class, 'exportCsv'])
        ->name('reports.exportCsv');

    Route::get('/reports/export.csv', [ReportController::class, 'exportCsv'])
        ->name('reports.legacyExportCsv');

    Route::get('/reports/employees', [ReportController::class, 'employees'])
        ->name('reports.employees.index');

    Route::get('/reports/employees/{user}', [ReportController::class, 'employeeReport'])
        ->name('reports.employee');

    Route::get('/reports/employees/{user}/export.csv', [ReportController::class, 'exportEmployeeCsv'])
        ->name('reports.employeeExportCsv');

    // หน้าจัดการงานของผู้ดูแลระบบ
    Route::get('/admin/tasks', [TaskController::class, 'index'])
        ->middleware('admin')
        ->name('admin.tasks.index');

    Route::post('/admin/tasks', [TaskController::class, 'store'])
        ->middleware('admin')
        ->name('admin.tasks.store');

    Route::get('/admin/tasks/{id}', [TaskController::class, 'show'])
        ->middleware('admin')
        ->name('admin.tasks.show');

    Route::prefix('admin/work-board')->name('admin.work-board.')->middleware('admin')->group(function () {
        Route::get('/departments/{department}', [WorkBoardController::class, 'adminDepartment'])->name('department');
        Route::get('/departments/{department}/members/{user}/preview', [WorkBoardController::class, 'adminMemberPreview'])->name('member.preview');
        Route::get('/departments/{department}/members/{user}', [WorkBoardController::class, 'adminMember'])->name('member');
        Route::post('/departments/{department}/members/{user}/projects/{list}/tasks', [TaskController::class, 'storeForAdminMember'])
            ->name('member.tasks.store');
    });
    // บอร์ดติดตามงานสำหรับพนักงาน
    Route::prefix('work-board')->name('work-board.')->middleware('role:user')->group(function () {
        Route::get('/', [WorkBoardController::class, 'index'])->name('index');
        Route::get('/departments/{department}', [WorkBoardController::class, 'department'])->name('department');
        Route::get('/departments/{department}/members/{user}', [WorkBoardController::class, 'member'])->name('member');
    });

    // งานและโปรเจกต์ในหน้า "งานของฉัน"
    Route::post('/my-tasks/{job_id}/status', [MyTaskController::class, 'updateStatus'])
        ->name('mytasks.updateStatus');

    Route::post('/my-tasks/{job_id}/priority', [MyTaskController::class, 'updatePriority'])
        ->name('mytasks.updatePriority');

    Route::post('/my-tasks', [MyTaskController::class, 'storeQuickTask'])
        ->name('mytasks.store');

    Route::post('/my-tasks/new-task', [MyTaskController::class, 'store'])
        ->name('mytasks.create');

    Route::post('/my-tasks/lists', [MyTaskController::class, 'storeList'])
        ->name('mytasks.lists.store');

    Route::patch('/my-tasks/lists/{list}', [MyTaskController::class, 'toggleList'])
        ->name('mytasks.lists.toggle');

    Route::patch('/my-tasks/lists/{list}/name', [MyTaskController::class, 'updateList'])
        ->name('mytasks.lists.update');

    Route::delete('/my-tasks/lists/{list}', [MyTaskController::class, 'destroyList'])
        ->name('mytasks.lists.destroy');

    Route::post('/my-tasks/lists/{list}/attachments', [MyTaskController::class, 'storeListAttachments'])
        ->name('mytasks.lists.attachments.store');

    Route::delete('/my-tasks/lists/{list}/attachments/{attachment}', [MyTaskController::class, 'destroyListAttachment'])
        ->name('mytasks.lists.attachments.destroy');

    Route::post('/my-tasks/lists/{list}/task-requests', [ProjectTaskRequestController::class, 'store'])
        ->middleware('throttle:'.WorkOrderListTaskRequest::SUBMIT_RATE_LIMITER)
        ->name('mytasks.lists.task-requests.store');
    Route::patch('/my-tasks/task-requests/{taskRequest}/approve', [ProjectTaskRequestController::class, 'approve'])
        ->name('mytasks.task-requests.approve');
    Route::patch('/my-tasks/task-requests/{taskRequest}/reject', [ProjectTaskRequestController::class, 'reject'])
        ->name('mytasks.task-requests.reject');

    Route::patch('/my-tasks/{job_id}/complete', [MyTaskController::class, 'toggleComplete'])
        ->name('mytasks.complete');

    Route::delete('/my-tasks/{job_id}', [MyTaskController::class, 'destroy'])
        ->name('mytasks.destroy');

    Route::post('/my-tasks/{job_id}/subtasks', [MyTaskController::class, 'storeSubtask'])
        ->name('mytasks.subtasks.store');

    Route::patch('/my-tasks/subtasks/{subtask}', [MyTaskController::class, 'toggleSubtask'])
        ->name('mytasks.subtasks.toggle');

    Route::patch('/my-tasks/subtasks/{subtask}/details', [MyTaskController::class, 'updateSubtask'])
        ->name('mytasks.subtasks.update');

    Route::match(['post', 'patch'], '/my-tasks/{job_id}/due-date', [MyTaskController::class, 'updateDueDate'])
        ->name('mytasks.updateDueDate');

    // ปฏิทินขอประชุมทีละช่วงเดือน สิทธิ์ถูกบังคับที่ SQL ผ่าน MeetingQueryService::visibleQuery()
    Route::get('/my-tasks/calendar/meetings', [MyTaskController::class, 'calendarMeetings'])
        ->name('mytasks.calendar.meetings');

    // Quick View ของปฏิทิน — โหลดตอนคลิกเท่านั้น และตรวจสิทธิ์ด้วย Policy เดิมทุกครั้ง
    Route::get('/my-tasks/calendar/quick-view/task/{id}', [MyTaskController::class, 'taskQuickView'])
        ->name('mytasks.quickview.task');

    Route::get('/my-tasks/calendar/quick-view/meeting/{meeting}', [MyTaskController::class, 'meetingQuickView'])
        ->name('mytasks.quickview.meeting');

    Route::get('/my-tasks', [MyTaskController::class, 'index'])
        ->name('mytasks.index');

    // งานที่ใช้ร่วมกันระหว่าง Admin และ User
    // การสร้างและแก้รายละเอียดจำกัด role ที่ระดับ route ส่วนสิทธิ์รายงานตรวจซ้ำใน Policy
    Route::post('/tasks', [TaskController::class, 'store'])
        ->middleware('role:admin,user')
        ->name('tasks.store');

    Route::patch('/tasks/{id}/details', [TaskController::class, 'updateDetails'])
        ->middleware('role:admin,user')
        ->name('tasks.details.update');

    Route::patch('/tasks/{id}/schedule', [TaskController::class, 'updateSchedule'])
        ->middleware('role:admin,user')
        ->name('tasks.schedule.update');

    Route::patch('/tasks/{id}/status', [TaskStatusController::class, 'updateStatus'])
        ->name('tasks.updateStatus');

    Route::post('/tasks/{id}/attachments', [TaskAttachmentController::class, 'uploadAttachments'])
        ->name('tasks.attachments.store');

    Route::delete('/tasks/{id}/attachments/{attachment}', [TaskAttachmentController::class, 'destroyAttachment'])
        ->name('tasks.attachments.destroy');

    Route::post('/tasks/{id}/collaborators', [TaskCollaboratorController::class, 'addCollaborators'])
        ->name('tasks.collaborators.store');

    Route::delete('/tasks/{id}/collaborators/{user}', [TaskCollaboratorController::class, 'removeCollaborator'])
        ->name('tasks.collaborators.destroy');

    Route::post('/tasks/{id}/progress', [TaskProgressController::class, 'updateProgress'])
        ->name('tasks.progress.store');

    Route::post('/tasks/{task}/comments', [TaskCommentController::class, 'store'])->name('tasks.comments.store');
    Route::post('/tasks/{task}/comments/read', [TaskCommentController::class, 'markRead'])->name('tasks.comments.read');

    Route::post('/tasks/{id}/delete-request', [TaskController::class, 'requestDelete'])
        ->name('tasks.deleteRequest.store');

    Route::get('/tasks/{id}', [TaskController::class, 'show'])
        ->name('tasks.show');

    // การแจ้งเตือนของผู้ใช้ปัจจุบัน
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::patch('/notifications/{notification}/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::delete('/notifications/read', [NotificationController::class, 'destroyRead'])->name('notifications.destroy-read');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
});
