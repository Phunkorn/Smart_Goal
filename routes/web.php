<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MyTaskController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkBoardController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

    // หน้าหลักและบอร์ดภาพรวม
    Route::redirect('/dashboard', '/board')
        ->name('dashboard');

    Route::get('/board', [TaskController::class, 'index'])
        ->name('board.index');

    // การจัดการพนักงาน
    Route::get('/employees', [UserController::class, 'index'])
        ->name('employees.index');

    Route::get('/employees/{user}', [UserController::class, 'show'])
        ->name('employees.show');

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

    // การอนุมัติและจัดการงานโดยผู้ดูแลระบบ
    Route::patch('/admin/tasks/{id}/approval', [TaskController::class, 'updateApproval'])
        ->middleware('admin')
        ->name('admin.tasks.approval');

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

    Route::patch('/tasks/{id}/invitation', [TaskController::class, 'respondInvitation'])
        ->name('tasks.invitation.respond');

    // การตั้งค่าส่วนตัว
    Route::get('/settings', [SettingsController::class, 'index'])
        ->name('settings.index');

    Route::patch('/settings', [SettingsController::class, 'update'])
        ->name('settings.update');

    // รายงานของผู้ใช้ปัจจุบัน
    Route::get('/my-reports', [ReportController::class, 'myReport'])
        ->name('reports.my');

    Route::get('/my-reports/export.csv', [ReportController::class, 'exportMyCsv'])
        ->name('reports.myExportCsv');

    // เปิดไฟล์จากพื้นที่ public storage ผ่านเส้นทางที่ตรวจสอบแล้ว
    Route::get('/media/{path}', function (string $path) {
        abort_unless(Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path));
    })->where('path', '.*')->name('media.show');
    // รายงานภาพรวมและรายงานรายพนักงาน
    Route::get('/reports', [ReportController::class, 'index'])
        ->name('reports.index');

    Route::get('/reports/export.csv', [ReportController::class, 'exportCsv'])
        ->name('reports.exportCsv');

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

    Route::get('/my-tasks', [MyTaskController::class, 'index'])
        ->name('mytasks.index');

    // หน้าตัวอย่างสำหรับตรวจสอบรูปแบบ UI ของงาน
    Route::get('/tasks/demo-notion', function () {
        return view('tasks.demo-notion');
    })->name('tasks.demoNotion');

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

    Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus'])
        ->name('tasks.updateStatus');

    Route::post('/tasks/{id}/attachments', [TaskController::class, 'uploadAttachments'])
        ->name('tasks.attachments.store');

    Route::delete('/tasks/{id}/attachments/{attachment}', [TaskController::class, 'destroyAttachment'])
        ->name('tasks.attachments.destroy');

    Route::post('/tasks/{id}/collaborators', [TaskController::class, 'addCollaborators'])
        ->name('tasks.collaborators.store');

    Route::delete('/tasks/{id}/collaborators/{user}', [TaskController::class, 'removeCollaborator'])
        ->name('tasks.collaborators.destroy');

    Route::post('/tasks/{id}/progress', [TaskController::class, 'updateProgress'])
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
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
});
