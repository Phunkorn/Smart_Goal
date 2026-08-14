@php
    $thaiMonths = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    $projectGroups = collect();
    foreach ($taskLists as $list) {
        $listTasks = $allTasks->where('work_order_list_id', $list->id)->values();
        if ($listTasks->isNotEmpty()) $projectGroups->push(['list' => $list, 'tasks' => $listTasks]);
    }
    $ungroupedTasks = $allTasks->whereNull('work_order_list_id')->values();
    if ($ungroupedTasks->isNotEmpty()) $projectGroups->push(['list' => null, 'tasks' => $ungroupedTasks]);
@endphp

<div class="board-reference-list" data-board-list-body>
    <div class="board-reference-columns" aria-hidden="true">
        <span>ชื่องาน</span><span>สถานะ</span><span>ความสำคัญ</span><span>ผู้รับผิดชอบ</span><span>ผู้ร่วมงาน</span><span>กำหนดส่ง</span><span>ความคืบหน้า</span><span></span>
    </div>

    @foreach($projectGroups as $group)
        @php
            $project = $group['list'];
            $projectTasks = $group['tasks'];
            $projectName = $project?->name ?? 'งานทั่วไป';
            $projectKey = $project ? 'project-'.$project->id : 'general';
            $projectPriority = [1=>['ต่ำ','low'],2=>['กลาง','medium'],3=>['สูง','high']][(int) ($project?->priority ?? 2)] ?? ['กลาง','medium'];
            $projectAttachments = $project?->attachments ?? collect();
        @endphp
            <header class="board-project-group__header" data-project-header data-project-key="{{ $projectKey }}" data-project-name="{{ $projectName }}">
                <button type="button" class="board-project-collapse" data-board-collapse aria-label="ย่อหรือขยายโปรเจกต์"><i class="bi bi-caret-down-fill"></i></button>
                <strong>{{ $projectName }}</strong>
                <span><b data-board-visible-count data-board-total-count="{{ $projectTasks->count() }}">{{ $projectTasks->count() }}</b> งาน</span>
                @if($project)<span class="board-project-priority priority-{{ $projectPriority[1] }}"><i class="bi bi-flag-fill"></i>{{ $projectPriority[0] }}</span>@endif
                @if($projectAttachments->isNotEmpty())
                    <details class="board-project-files"><summary title="ไฟล์แนบของโปรเจกต์"><i class="bi bi-paperclip"></i>{{ $projectAttachments->count() }} ไฟล์</summary><div>@foreach($projectAttachments as $attachment)<a href="{{ route('media.show', ['path' => $attachment->file_path]) }}" target="_blank" rel="noopener"><i class="bi bi-file-earmark"></i><span>{{ $attachment->original_name }}</span></a>@endforeach</div></details>
                @endif
                <i class="board-project-rule"></i>
                <div class="board-project-actions">
                    @if($project)<button type="button" class="board-project-add" data-add-in-group data-list-id="{{ $project->id }}" title="เพิ่มรายการในโปรเจกต์ {{ $projectName }}"><i class="bi bi-plus-lg"></i><span>เพิ่มรายการ</span></button>@endif
                    @if($project && auth()->user()->can('manage', $project))
                        <button type="button" class="board-project-icon" data-board-edit-project data-name="{{ $projectName }}" data-url="{{ route('mytasks.lists.update', $project) }}" title="แก้ไขชื่อโปรเจกต์"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="board-project-icon is-danger" data-board-delete-project data-name="{{ $projectName }}" data-url="{{ route('mytasks.lists.destroy', $project) }}" title="ลบโปรเจกต์"><i class="bi bi-trash3"></i></button>
                    @endif
                </div>
            </header>

                @foreach($projectTasks as $task)
                    @php
                        $taskIsLate = (int) $task->job_status !== 4 && $task->job_due_at?->isPast();
                        $taskIsSoon = ! $taskIsLate && (int) $task->job_status !== 4 && $task->job_due_at && now()->diffInDays($task->job_due_at, false) <= 3;
                        $taskStatus = [1=>['ยังไม่เริ่ม','todo'],2=>['กำลังทำ','progress'],3=>['รอตรวจสอบ','review'],4=>['เสร็จแล้ว','done'],5=>['พักงาน','paused']][(int)$task->job_status] ?? ['ยังไม่เริ่ม','todo'];
                        $taskProgress = (int) $task->progress_from_subtasks;
                        $priority = [1=>['ต่ำ','low'],2=>['กลาง','medium'],3=>['สูง','high']][(int)$task->job_priority] ?? ['กลาง','medium'];
                        $acceptedCollaborators = $task->collaborators->filter(fn ($person) => $person->pivot?->status === 'accepted')->values();
                        $pendingCollaborators = $task->collaborators->filter(fn ($person) => $person->pivot?->status !== 'accepted')->values();
                        $collaborators = $acceptedCollaborators->concat($pendingCollaborators);
                        $fileCount = (int) ($task->images_count ?? $task->images->count());
                        $dueLabel = $task->job_due_at ? $task->job_due_at->day.' '.$thaiMonths[$task->job_due_at->month].' '.($task->job_due_at->year + 543) : 'ไม่มีกำหนด';
                        $assigneeName = $task->user?->name ?? auth()->user()->name;
                    @endphp
                    <article class="board-reference-row" data-board-task data-project-key="{{ $projectKey }}" data-task-id="{{ $task->job_id }}" data-topic="{{ $task->job_topic }}" data-status="{{ $task->job_status }}" data-late="{{ $taskIsLate ? 1 : 0 }}" data-project-name="{{ $projectName }}" data-due="{{ optional($task->job_due_at)->format('Y-m-d') }}">
                        <button type="button" class="board-reference-task" data-board-open-task="{{ $task->job_id }}">
                            <strong>{{ $task->job_topic }}</strong>
                            <span>{{ $task->job_details ? Str::limit($task->job_details, 80) : 'ไม่มีรายละเอียดงาน' }}</span>
                            @if($fileCount)<small><i class="bi bi-paperclip"></i>{{ $fileCount }} ไฟล์แนบ</small>@endif
                        </button>
                        @can('update', $task)
                            <details class="board-status-menu" data-board-status-menu>
                                <summary class="board-status-pill status-{{ $taskIsLate ? 'late' : $taskStatus[1] }}"><i></i><span data-board-status-label>{{ $taskIsLate ? 'ล่าช้า' : $taskStatus[0] }}</span><i class="bi bi-chevron-down"></i></summary>
                                <div>
                                    @foreach([1=>['ยังไม่เริ่ม','todo'],2=>['กำลังทำ','progress'],3=>['รอตรวจสอบ','review'],4=>['เสร็จแล้ว','done'],5=>['พักงาน','paused']] as $value=>$meta)
                                        <button type="button" class="status-{{ $meta[1] }}" data-board-status-value="{{ $value }}"><i></i>{{ $meta[0] }}@if((int)$task->job_status === $value)<span class="bi bi-check2"></span>@endif</button>
                                    @endforeach
                                </div>
                            </details>
                            <label class="board-inline-select board-priority priority-{{ $priority[1] }}" data-board-priority-choice><i class="bi bi-flag-fill"></i><select data-board-field="priority" aria-label="เปลี่ยนความสำคัญ">@foreach([1=>'ต่ำ',2=>'กลาง',3=>'สูง'] as $value=>$label)<option value="{{ $value }}" @selected((int)$task->job_priority === $value)>{{ $label }}</option>@endforeach</select></label>
                        @else
                            <span class="board-status-pill status-{{ $taskIsLate ? 'late' : $taskStatus[1] }}"><i></i>{{ $taskIsLate ? 'ล่าช้า' : $taskStatus[0] }}</span>
                            <span class="board-priority priority-{{ $priority[1] }}"><i class="bi bi-flag-fill"></i>{{ $priority[0] }}</span>
                        @endcan
                        <span class="board-owner" title="ผู้รับผิดชอบหลัก: {{ $assigneeName }}"><i>{{ Str::substr($assigneeName, 0, 1) }}</i><strong>{{ $assigneeName }}</strong></span>
                        <span class="board-collaborators"><button type="button" data-manage-team="{{ $task->job_id }}" aria-label="เพิ่มหรือลบผู้ร่วมงาน {{ $collaborators->count() }} คน">@foreach($collaborators->take(2) as $person)<i class="{{ $person->pivot?->status === 'pending' ? 'is-pending' : '' }}" title="{{ $person->name }}{{ $person->pivot?->status === 'pending' ? ' — รอตอบรับ' : '' }}">{{ Str::substr($person->name, 0, 1) }}</i>@endforeach @if($collaborators->count() > 2)<b>+{{ $collaborators->count() - 2 }}</b>@endif<span class="board-team-add" title="เพิ่มผู้ร่วมงาน"><i class="bi bi-person-plus-fill"></i></span></button></span>
                        @can('update', $task)
                            <label class="board-due board-due-editable {{ $taskIsLate ? 'is-late' : ($taskIsSoon ? 'is-soon' : '') }}"><i class="bi {{ $taskIsLate ? 'bi-exclamation-triangle' : 'bi-calendar3' }}"></i><span data-board-due-label>{{ $dueLabel }}</span><input type="date" data-board-field="due" value="{{ optional($task->job_due_at)->format('Y-m-d') }}" aria-label="เลือกกำหนดส่ง"></label>
                        @else
                            <span class="board-due {{ $taskIsLate ? 'is-late' : ($taskIsSoon ? 'is-soon' : '') }}"><i class="bi {{ $taskIsLate ? 'bi-exclamation-triangle' : 'bi-calendar3' }}"></i>{{ $dueLabel }}</span>
                        @endcan
                        <span class="board-progress"><i><b style="width:{{ $taskProgress }}%"></b></i><strong>{{ $taskProgress }}%</strong></span>
                        <details class="task-more-menu board-reference-menu">
                            <summary aria-label="เมนูจัดการรายการ"><i class="bi bi-three-dots-vertical"></i></summary>
                            <div class="board-task-menu">
                                <button type="button" data-board-open-task="{{ $task->job_id }}"><i class="bi bi-pencil-square"></i><span><strong>แก้ไขรายการ</strong><small>แก้ไขชื่องาน รายละเอียด และข้อมูลของงาน</small></span></button>
                                @if(auth()->user()->can('update', $task) && ((int)$task->job_status !== 4 || auth()->user()->role === 'admin'))
                                    <button type="button" data-board-pick-attachment><i class="bi bi-paperclip"></i><span><strong>เพิ่มไฟล์แนบ</strong><small>JPG, PNG, Word, Excel, PowerPoint · สูงสุด 10 MB/ไฟล์ · รวมไม่เกิน 5 ไฟล์</small></span></button>
                                    <input type="file" hidden multiple data-board-attachment-input data-url="{{ route('tasks.attachments.store', $task->job_id) }}" data-existing-count="{{ $fileCount }}" accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx">
                                @endif
                                @can('deleteOwn', $task)
                                    <button type="button" class="danger" data-board-delete-task data-url="{{ route('mytasks.destroy', $task->job_id) }}"><i class="bi bi-trash3"></i><span><strong>ลบรายการ</strong><small>ลบงานนี้ออกจากโปรเจกต์</small></span></button>
                                @endcan
                            </div>
                        </details>
                    </article>
                @endforeach
    @endforeach
</div>
