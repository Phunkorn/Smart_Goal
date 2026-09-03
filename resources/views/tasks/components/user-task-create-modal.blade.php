@php
    $projectOptions = collect($projectOptions ?? [])->unique('id')->values();
    $initialStart = now()->format('Y-m-d\TH:i');
    $initialDue = now()->addDay()->format('Y-m-d\TH:i');
@endphp

<div class='notion-modal user-task-create' data-user-task-create-modal hidden>
    <form class='notion-modal-card user-task-create__card' action='{{ route('mytasks.create') }}' method='POST' data-user-task-create-form>
        @csrf
        <header class='user-task-create__header'>
            <div>
                <span class='task-edit-kicker'>CREATE TASK</span>
                <strong>สร้างงาน</strong>
                <small>เลือกโปรเจกต์เดิม หรือสร้างโปรเจกต์ใหม่พร้อมงานได้ในครั้งเดียว</small>
            </div>
            <button type='button' class='task-modal-close' data-close-user-task-create aria-label='ปิด'><i class='bi bi-x-lg' aria-hidden='true'></i></button>
        </header>

        <div class='user-task-create__body'>
            <p class='user-task-create__error' data-user-task-create-error role='alert' hidden></p>
            <section class='user-task-create__section' aria-labelledby='user-task-project-heading'>
                <div class='user-task-create__section-heading'>
                    <span><i class='bi bi-folder2-open' aria-hidden='true'></i></span>
                    <div><strong id='user-task-project-heading'>โปรเจกต์</strong><small>งานจะอยู่ภายในโปรเจกต์ที่เลือก</small></div>
                </div>
                <label>
                    <span>เลือกโปรเจกต์</span>
                    <select name='work_order_list_id' data-user-task-project>
                        <option value=''>+ สร้างโปรเจกต์ใหม่</option>
                        @foreach($projectOptions as $project)
                            <option value='{{ $project->id }}' @selected($loop->first)>{{ $project->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class='user-task-create__new-project' data-user-task-new-project>
                    <label><span>ชื่อโปรเจกต์ <b aria-hidden='true'>*</b></span><input name='project_name' maxlength='80' placeholder='เช่น ปรับปรุงเว็บไซต์บริษัท'></label>
                    <label>
                        <span>ความสำคัญของโปรเจกต์</span>
                        <select name='project_priority'>
                            @foreach(\App\Support\WorkBoardDesign::PROJECT_PRIORITIES as $value => $meta)
                                <option value='{{ $value }}' @selected($value === 2)>{{ $meta['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </section>

            <section class='user-task-create__section' aria-labelledby='user-task-detail-heading'>
                <div class='user-task-create__section-heading'>
                    <span><i class='bi bi-check2-square' aria-hidden='true'></i></span>
                    <div><strong id='user-task-detail-heading'>ข้อมูลงาน</strong><small>ระบบจะมอบหมายงานนี้ให้คุณโดยอัตโนมัติ</small></div>
                </div>
                <label><span>ชื่องาน <b aria-hidden='true'>*</b></span><input name='job_topic' maxlength='255' required placeholder='ระบุสิ่งที่ต้องทำ'></label>
                <div class='user-task-create__details' data-user-task-details>
                    <div class='user-task-create__details-head'>
                        <div>
                            <strong>รายละเอียดงาน</strong>
                            <small>แยกสิ่งที่ต้องทำใต้ชื่องาน เพิ่มได้หลายรายการ</small>
                        </div>
                        <button type='button' data-add-user-task-detail><i class='bi bi-plus-lg' aria-hidden='true'></i> เพิ่มรายละเอียด</button>
                    </div>
                    <div class='user-task-create__details-list' data-user-task-details-list>
                        <div class='user-task-create__detail-row' data-user-task-detail-row>
                            <i class='bi bi-diagram-3' aria-hidden='true'></i>
                            <input name='subtasks[]' maxlength='255' aria-label='รายละเอียดงานรายการที่ 1' placeholder='เช่น ซื้ออุปกรณ์'>
                            <button type='button' data-remove-user-task-detail aria-label='ลบรายละเอียดงานรายการนี้'><i class='bi bi-x-lg' aria-hidden='true'></i></button>
                        </div>
                    </div>
                    <template data-user-task-detail-template>
                        <div class='user-task-create__detail-row' data-user-task-detail-row>
                            <i class='bi bi-diagram-3' aria-hidden='true'></i>
                            <input name='subtasks[]' maxlength='255' aria-label='รายละเอียดงาน' placeholder='เพิ่มรายละเอียดงาน'>
                            <button type='button' data-remove-user-task-detail aria-label='ลบรายละเอียดงานรายการนี้'><i class='bi bi-x-lg' aria-hidden='true'></i></button>
                        </div>
                    </template>
                </div>
                <div class='user-task-create__grid'>
                    <label><span>วันที่เริ่ม <b aria-hidden='true'>*</b></span><input type='datetime-local' name='job_start_at' value='{{ $initialStart }}' required></label>
                    <label><span>กำหนดส่ง <b aria-hidden='true'>*</b></span><input type='datetime-local' name='job_due_at' value='{{ $initialDue }}' required></label>
                    <label>
                        <span>ความสำคัญของงาน</span>
                        <select name='job_priority'>
                            @foreach(\App\Support\WorkBoardDesign::TASK_PRIORITIES as $value => $meta)
                                <option value='{{ $value }}' @selected($value === 2)>{{ $meta['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </section>
        </div>

        <footer>
            <p><i class='bi bi-info-circle' aria-hidden='true'></i> ยังใช้ปุ่ม “เพิ่มรายการ” ภายในโปรเจกต์ได้ตามเดิม</p>
            <div>
                <button type='button' class='task-secondary' data-close-user-task-create>ยกเลิก</button>
                <button type='submit' class='notion-primary'><i class='bi bi-plus-lg' aria-hidden='true'></i> สร้างงาน</button>
            </div>
        </footer>
    </form>
</div>
