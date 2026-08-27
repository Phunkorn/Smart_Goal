@php
    $projectTaskRequestErrors = $errors->getBag('projectTaskRequest');
    $projectTaskRequestDecisionErrors = $errors->getBag('projectTaskRequestDecision');
    $projectTaskRequestFeedback = [
        'success' => session('project_task_request_success'),
        'error' => session('project_task_request_error') ?: $projectTaskRequestDecisionErrors->first(),
        'open_modal' => $projectTaskRequestErrors->isNotEmpty(),
        'list_id' => session('project_task_request_list_id'),
        'errors' => $projectTaskRequestErrors->getMessages(),
        'old' => [
            'job_topic' => old('job_topic'),
            'request_details' => old('request_details'),
            'job_priority' => old('job_priority'),
            'job_start_at' => old('job_start_at'),
            'job_due_at' => old('job_due_at'),
        ],
    ];
@endphp
<script type="application/json" data-project-task-request-feedback>@json($projectTaskRequestFeedback)</script>
<div class="notion-modal project-task-request-modal sg-task-theme" data-project-task-request-modal hidden>
    <section class="project-task-request-modal__card" role="dialog" aria-modal="true" aria-labelledby="project-task-request-title">
        <header>
            <div><span>PROJECT REQUEST</span><strong id="project-task-request-title">ขอเพิ่มงาน</strong><small data-project-task-request-name></small></div>
            <button type="button" class="task-modal-close" data-close-project-task-request aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </header>
        <form method="POST" data-project-task-request-form>
            @csrf
            <div class="project-task-request-modal__body">
                <div class="alert alert-danger py-2 mb-0" role="alert" data-project-task-request-general-error hidden></div>
                <label><span>ชื่องาน</span><input class="form-control" name="job_topic" maxlength="255" required><div class="invalid-feedback" data-project-task-request-error="job_topic"></div></label>
                <label><span>รายละเอียดโดยย่อ</span><input class="form-control" name="request_details" maxlength="5000"><div class="invalid-feedback" data-project-task-request-error="request_details"></div></label>
                <div class="project-task-request-modal__grid">
                    <label><span>ความสำคัญ</span><select class="form-select" name="job_priority" required><option value="1">Routine</option><option value="2" selected>สำคัญไม่ด่วน</option><option value="3">สำคัญด่วน</option><option value="4">ด่วนไม่สำคัญ</option><option value="5">ไม่รีบ</option></select><div class="invalid-feedback" data-project-task-request-error="job_priority"></div></label>
                    <label><span>วันที่เริ่ม</span><input class="form-control" type="date" name="job_start_at" required><div class="invalid-feedback" data-project-task-request-error="job_start_at"></div></label>
                    <label><span>กำหนดส่ง</span><input class="form-control" type="date" name="job_due_at" required><div class="invalid-feedback" data-project-task-request-error="job_due_at"></div></label>
                </div>
                <p><i class="bi bi-info-circle"></i> ระบบจะสร้างงานเมื่อเจ้าของโปรเจกต์อนุมัติเท่านั้น</p>
            </div>
            <footer><button type="button" class="btn btn-light" data-close-project-task-request>ยกเลิก</button><button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> ส่งคำขอ</button></footer>
        </form>
    </section>
</div>
