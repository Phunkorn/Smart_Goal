@php
    $projectCreatorMeta = $projectCreatorMeta ?? collect();
    $showQuickAdd = $showQuickAdd ?? true;
    $taskLinkMode = $taskLinkMode ?? false;
    $workspaceContext = $workspaceContext ?? 'user';
    $thaiMonths = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    $projectGroups = collect();
    foreach ($taskLists as $list) {
        $listTasks = $allTasks->where('work_order_list_id', $list->id)->values();
        $projectGroups->push(['list' => $list, 'tasks' => $listTasks]);
    }
    $ungroupedTasks = $allTasks->whereNull('work_order_list_id')->values();
    if ($ungroupedTasks->isNotEmpty()) $projectGroups->push(['list' => null, 'tasks' => $ungroupedTasks]);
@endphp

<div class="board-reference-list" data-board-list-body>
    <div class="board-reference-columns" aria-hidden="true">
        <span>ชื่องาน</span><span>สถานะ</span><span>ความสำคัญ</span><span>วันที่เริ่ม</span><span>กำหนดส่ง</span><span>ผู้รับผิดชอบ</span><span>ผู้ร่วมงาน</span><span>ไฟล์แนบ</span><span>คอมเมนต์</span><span></span>
    </div>

    @foreach($projectGroups as $group)
        @php
            $project = $group['list'];
            $projectTasks = $group['tasks']->sortBy(fn ($task) => (int) $task->job_status === 4 ? 1 : 0)->values();
            $completedProjectTasks = $projectTasks->where('job_status', 4)->values();
            $firstCompletedId = $completedProjectTasks->first()?->job_id;
            $projectName = $project?->name ?? 'งานทั่วไป';
            $projectKey = $project ? 'project-'.$project->id : 'general';
            $projectPriority = [1=>['สำคัญ/ต่ำ','low'],2=>['สำคัญ/กลาง','medium'],3=>['สำคัญ/สูง','high']][(int) ($project?->priority ?? 2)] ?? ['สำคัญ/กลาง','medium'];
            $projectAttachments = $project?->attachments ?? collect();
            $creatorSummary = $project ? ($projectCreatorMeta->get($project->id) ?? []) : [];
            $uniformAdminName = $creatorSummary['uniform_admin_name'] ?? null;
        @endphp
            <header class="board-project-group__header project-tone-{{ $project ? $projectPriority[1] : 'neutral' }}" data-project-header data-project-key="{{ $projectKey }}" data-project-name="{{ $projectName }}">
                <button type="button" class="board-project-collapse" data-board-collapse aria-label="ย่อหรือขยายโปรเจกต์"><i class="bi bi-caret-down-fill"></i></button>
                <i class="board-project-folder bi bi-folder-fill" aria-hidden="true"></i>
                <strong class="board-project-group__title">{{ $projectName }}</strong>
                <span><b data-board-visible-count data-board-total-count="{{ $projectTasks->count() }}">{{ $projectTasks->count() }}</b> งาน</span>
                @include('tasks.partials.admin-assignment-marker', ['adminSenderName' => $uniformAdminName])
                @if($project)
                    @can('manage', $project)
                        <details class="board-status-menu board-project-priority-menu" data-project-priority-menu data-url="{{ route('mytasks.lists.update', $project) }}">
                            <summary class="board-project-priority priority-{{ $projectPriority[1] }}"><i class="bi bi-flag-fill"></i><span data-project-priority-label>{{ $projectPriority[0] }}</span><i class="bi bi-chevron-down"></i></summary>
                            <div>@foreach([1=>['สำคัญ/ต่ำ','low'],2=>['สำคัญ/กลาง','medium'],3=>['สำคัญ/สูง','high']] as $value=>$meta)<button type="button" class="priority-{{ $meta[1] }}" data-project-priority-value="{{ $value }}"><i class="bi bi-flag-fill"></i>{{ $meta[0] }}@if((int)$project->priority === $value)<span class="bi bi-check2"></span>@endif</button>@endforeach</div>
                        </details>
                    @else
                        <span class="board-project-priority priority-{{ $projectPriority[1] }}"><i class="bi bi-flag-fill"></i>{{ $projectPriority[0] }}</span>
                    @endcan
                @endif
                @if($projectAttachments->isNotEmpty() || ($workspaceContext === 'admin-member' && $project && auth()->user()->can('manage', $project)))
                    <details class="board-project-files" data-project-attachments data-upload-url="{{ $project ? route('mytasks.lists.attachments.store', $project) : '' }}"><summary title="ไฟล์แนบของโปรเจกต์"><i class="bi bi-paperclip"></i><span data-project-attachment-count>{{ $projectAttachments->count() }}</span> ไฟล์</summary><div>@foreach($projectAttachments as $attachment)<span class="board-project-file"><a href="{{ route('media.project-attachments.show', $attachment) }}" target="_blank" rel="noopener"><i class="bi bi-file-earmark"></i><span>{{ $attachment->original_name }}</span></a>@if($workspaceContext === 'admin-member' && $project && auth()->user()->can('manage', $project))<button type="button" data-project-attachment-delete="{{ route('mytasks.lists.attachments.destroy', [$project, $attachment]) }}" aria-label="ลบไฟล์ {{ $attachment->original_name }}"><i class="bi bi-x-lg"></i></button>@endif</span>@endforeach @if($workspaceContext === 'admin-member' && $project && auth()->user()->can('manage', $project))<label class="board-project-file-upload"><i class="bi bi-plus-lg"></i> เพิ่มไฟล์<input type="file" multiple data-project-attachment-input accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></label>@endif</div></details>
                @endif
                <div class="board-project-actions">
                    @if($showQuickAdd && $project && $manageableTaskLists->contains('id', $project->id))<button type="button" class="board-project-add" data-add-in-group data-list-id="{{ $project->id }}" title="เพิ่มรายการในโปรเจกต์ {{ $projectName }}"><i class="bi bi-plus-lg"></i><span>เพิ่มรายการ</span></button>@endif
                    @if($project && auth()->user()->can('requestTask', $project))
                        <button type="button" class="board-project-add board-project-request" data-open-project-task-request data-list-id="{{ $project->id }}" data-action="{{ route('mytasks.lists.task-requests.store', $project) }}" data-project-name="{{ $projectName }}"><i class="bi bi-send-plus"></i><span>ขอเพิ่มงาน</span></button>
                    @endif
                    @if($project && auth()->user()->can('manage', $project))
                        <button type="button" class="board-project-icon" data-board-edit-project data-name="{{ $projectName }}" data-url="{{ route('mytasks.lists.update', $project) }}" title="แก้ไขชื่อโปรเจกต์"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="board-project-icon is-danger" data-board-delete-project data-name="{{ $projectName }}" data-total-count="{{ $project->work_orders_count ?? $projectTasks->count() }}" data-url="{{ route('mytasks.lists.destroy', $project) }}" title="ลบโปรเจกต์"><i class="bi bi-trash3"></i></button>
                    @endif
                </div>
            </header>

            @if($project && auth()->user()->can('reviewTaskRequests', $project) && $project->taskRequests->isNotEmpty())
                <details class="project-task-requests" @if((int) request('task_request') && $project->taskRequests->contains('id', (int) request('task_request'))) open @endif>
                    <summary><i class="bi bi-inbox"></i><strong>คำขอเพิ่มงาน</strong><span>{{ $project->taskRequests->count() }} รายการรอพิจารณา</span></summary>
                    <div class="project-task-requests__list">
                        @foreach($project->taskRequests as $pendingRequest)
                            <article @if((int) request('task_request') === (int) $pendingRequest->id) class="is-highlighted" @endif>
                                <div>
                                    <strong>{{ $pendingRequest->job_topic }}</strong>
                                    <span>ขอโดย {{ $pendingRequest->requester?->name ?? 'ผู้ใช้ที่ถูกลบ' }} · กำหนดส่ง {{ $pendingRequest->job_due_at?->format('d/m/Y') }}</span>
                                    @if($pendingRequest->job_details)<p>{{ $pendingRequest->job_details }}</p>@endif
                                </div>
                                <div class="project-task-requests__actions">
                                    <form method="POST" action="{{ route('mytasks.task-requests.approve', $pendingRequest) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> อนุมัติ</button>
                                    </form>
                                    <form method="POST" action="{{ route('mytasks.task-requests.reject', $pendingRequest) }}">
                                        @csrf @method('PATCH')
                                        <label><span class="visually-hidden">เหตุผลที่ปฏิเสธ</span><input class="form-control form-control-sm" name="decision_reason" maxlength="1000" value="{{ (int) session('project_task_request_decision_id') === (int) $pendingRequest->id ? old('decision_reason') : '' }}" placeholder="เหตุผล (ถ้ามี)"></label>
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> ปฏิเสธ</button>
                                    </form>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </details>
            @endif

                @foreach($projectTasks as $task)
                    @if((int) $task->job_status === 4 && (int) $task->job_id === (int) $firstCompletedId)
                        <details class="board-completed-group" data-completed-group data-project-key="{{ $projectKey }}">
                            <summary><i class="bi bi-caret-right-fill"></i><strong>งานที่เสร็จแล้ว</strong><span>{{ $completedProjectTasks->count() }} งาน</span></summary>
                            <div class="board-completed-group__rows">
                    @endif
                    @php
                        $taskIsLate = (int) $task->job_status !== 4 && $task->job_due_at?->isPast();
                        $taskIsSoon = ! $taskIsLate && (int) $task->job_status !== 4 && $task->job_due_at && now()->diffInDays($task->job_due_at, false) <= 3;
                        $taskStatus = [2=>['กำลังทำ','progress'],3=>['รอตรวจสอบ','review'],4=>['เสร็จแล้ว','done'],5=>['พักงาน','paused'],6=>['ล่าช้า','late']][(int)$task->job_status] ?? ['สถานะไม่รองรับ','unsupported'];
                        $priority = [1=>['routine','routine'],2=>['สำคัญไม่ด่วน','important'],3=>['สำคัญด่วน','urgent'],4=>['ด่วนไม่ค่อยสำคัญ','quick'],5=>['ไม่รีบ ไม่มีกำหนด','flexible']][(int)$task->job_priority] ?? ['สำคัญไม่ด่วน','important'];
                        $acceptedCollaborators = $task->collaborators->filter(fn ($person) => $person->pivot?->status === 'accepted')->values();
                        $pendingCollaborators = $task->collaborators->filter(fn ($person) => $person->pivot?->status !== 'accepted')->values();
                        $collaborators = $acceptedCollaborators->concat($pendingCollaborators);
                        $fileCount = (int) ($task->images_count ?? $task->images->count());
                        $startLabel = $task->job_start_at ? $task->job_start_at->day.' '.$thaiMonths[$task->job_start_at->month].' '.($task->job_start_at->year + 543) : '-';
                        $dueLabel = $task->job_due_at ? $task->job_due_at->day.' '.$thaiMonths[$task->job_due_at->month].' '.($task->job_due_at->year + 543) : 'ไม่มีกำหนด';
                        $assigneeName = $task->user?->name ?? auth()->user()->name;
                        $taskAdminSenderName = ! $uniformAdminName && $task->creator?->role === 'admin' ? $task->creator->name : null;
                        $taskDeleteUrl = $workspaceContext === 'admin-member' ? route('admin.tasks.destroy', $task->job_id) : route('mytasks.destroy', $task->job_id);
                        $canDeleteTask = $workspaceContext === 'admin-member'
                            ? auth()->user()->can('delete', $task)
                            : auth()->user()->can('deleteOwn', $task);
                        $canManageTeam = auth()->user()->can('manageTeam', $task);
                        $canEditSchedule = auth()->user()->can('work', $task)
                            && ((int) $task->job_status !== 4 || auth()->user()->role === 'admin');
                        $unreadCommentCount = (int) ($unreadCommentCounts[$task->job_id] ?? 0);
                        // updates ถูก eager-load ไว้แล้วสำหรับ timeline จึงนับในหน่วยความจำ ไม่มีคิวรีเพิ่ม
                        $commentCount = $task->updates->where('is_comment', true)->count();
                        $commentLabel = $commentCount ? 'ดูคอมเมนต์ '.$commentCount.' รายการ' : 'ยังไม่มีคอมเมนต์';
                    @endphp
                    @include('tasks.partials.task-support-source', ['task' => $task, 'adminSenderName' => $taskAdminSenderName, 'taskLinkMode' => $taskLinkMode])
                    <article class="board-reference-row task-priority-{{ $priority[1] }}" data-board-task data-project-key="{{ $projectKey }}" data-task-id="{{ $task->job_id }}" data-topic="{{ $task->job_topic }}" data-status="{{ $task->job_status }}" data-late="{{ $taskIsLate ? 1 : 0 }}" data-project-name="{{ $projectName }}" data-start="{{ \App\Support\TodayWorkspace::calendarDate($task->job_start_at) }}" data-due="{{ \App\Support\TodayWorkspace::calendarDate($task->job_due_at) }}">
                        <div class="board-reference-task">
                            <button type="button" class="board-reference-task__open" data-open-task-modal data-task-id="{{ $task->job_id }}"><span class="board-reference-task__title">{{ $task->job_topic }}</span></button>
                        </div>
                        @can('work', $task)
                            <details class="board-status-menu" data-board-status-menu>
                                <summary class="board-status-pill status-{{ $taskIsLate ? 'late' : $taskStatus[1] }}"><span data-board-status-label>{{ $taskIsLate ? 'ล่าช้า' : $taskStatus[0] }}</span><i class="bi bi-chevron-down"></i></summary>
                                <div>
                                    @foreach([5=>['พักงาน','paused'],2=>['กำลังทำ','progress'],3=>['รอตรวจสอบ','review'],4=>['เสร็จแล้ว','done']] as $value=>$meta)
                                        <button type="button" class="status-{{ $meta[1] }}" data-board-status-value="{{ $value }}">{{ $meta[0] }}@if((int)$task->job_status === $value)<span class="bi bi-check2"></span>@endif</button>
                                    @endforeach
                                </div>
                            </details>
                            <details class="board-status-menu board-priority-menu" data-board-priority-menu>
                                <summary class="board-priority priority-{{ $priority[1] }}"><span data-board-priority-label>{{ $priority[0] }}</span><i class="bi bi-chevron-down"></i></summary>
                                <div>@foreach([3=>['สำคัญด่วน','urgent'],4=>['ด่วนไม่ค่อยสำคัญ','quick'],2=>['สำคัญไม่ด่วน','important'],5=>['ไม่รีบ ไม่มีกำหนด','flexible'],1=>['routine','routine']] as $value=>$meta)<button type="button" class="priority-{{ $meta[1] }}" data-board-priority-value="{{ $value }}"><i class="bi bi-flag-fill"></i>{{ $meta[0] }}@if((int)$task->job_priority === $value)<span class="bi bi-check2"></span>@endif</button>@endforeach</div>
                            </details>
                        @else
                            <span class="board-status-pill status-{{ $taskIsLate ? 'late' : $taskStatus[1] }}">{{ $taskIsLate ? 'ล่าช้า' : $taskStatus[0] }}</span>
                            <span class="board-priority priority-{{ $priority[1] }}">{{ $priority[0] }}</span>
                        @endcan
                        @if($canEditSchedule)
                            <label class="board-start board-start-editable"><i class="bi bi-calendar-plus"></i><span data-board-start-label>{{ $startLabel }}</span><input type="date" data-board-field="start" value="{{ optional($task->job_start_at)->format('Y-m-d') }}" max="{{ optional($task->job_due_at)->format('Y-m-d') }}" aria-label="เลือกวันที่เริ่ม"></label>
                        @else
                            <span class="board-start"><i class="bi bi-calendar-plus"></i>{{ $startLabel }}</span>
                        @endif
                        @if($canEditSchedule)
                            <label class="board-due board-due-editable {{ $taskIsLate ? 'is-late' : ($taskIsSoon ? 'is-soon' : '') }}"><i class="bi {{ $taskIsLate ? 'bi-exclamation-triangle' : 'bi-calendar3' }}"></i><span data-board-due-label>{{ $dueLabel }}</span><input type="date" data-board-field="due" value="{{ optional($task->job_due_at)->format('Y-m-d') }}" min="{{ optional($task->job_start_at)->format('Y-m-d') }}" aria-label="เลือกกำหนดส่ง"></label>
                        @else
                            <span class="board-due {{ $taskIsLate ? 'is-late' : ($taskIsSoon ? 'is-soon' : '') }}"><i class="bi {{ $taskIsLate ? 'bi-exclamation-triangle' : 'bi-calendar3' }}"></i>{{ $dueLabel }}</span>
                        @endif
                        <button type="button" class="board-owner" data-open-owner="{{ $task->job_id }}" title="ดูผู้รับผิดชอบ: {{ $assigneeName }}" aria-label="ดูข้อมูลผู้รับผิดชอบ {{ $assigneeName }}"><i>@if($task->user?->profile_image)<img src="{{ route('media.profile', $task->user) }}" alt="">@else{{ Str::substr($assigneeName, 0, 1) }}@endif</i></button>
                        <span class="board-collaborators"><button type="button" data-manage-team="{{ $task->job_id }}" aria-label="{{ $canManageTeam ? 'จัดการ' : 'ดู' }}ผู้ร่วมงาน {{ $collaborators->count() }} คน">@foreach($collaborators->take(2) as $person)<i class="{{ $person->pivot?->status === 'pending' ? 'is-pending' : '' }}" title="{{ $person->name }}{{ $person->pivot?->status === 'pending' ? ' — รอตอบรับ' : '' }}">{{ Str::substr($person->name, 0, 1) }}</i>@endforeach @if($collaborators->count() > 2)<b>+{{ $collaborators->count() - 2 }}</b>@endif<span class="board-team-add" title="{{ $canManageTeam ? 'เพิ่มผู้ร่วมงาน' : 'ดูผู้ร่วมงาน' }}"><i class="bi {{ $canManageTeam ? 'bi-person-plus-fill' : 'bi-people-fill' }}"></i></span></button></span>
                        <button type="button" class="board-attachments {{ $fileCount ? 'has-files' : '' }}" data-board-open-attachments="{{ $task->job_id }}" title="{{ $fileCount ? 'ดูไฟล์แนบ '.$fileCount.' ไฟล์' : 'ยังไม่มีไฟล์แนบ' }}"><i class="bi bi-paperclip"></i><strong>{{ $fileCount ?: '-' }}</strong></button>
                        <button type="button" class="board-comments{{ $commentCount ? ' has-comments' : '' }}{{ $unreadCommentCount > 0 ? ' has-unread' : '' }}" data-open-task-modal data-task-id="{{ $task->job_id }}" data-task-tab="updates" data-unread-comments="{{ $task->job_id }}" data-unread-persistent data-comment-label="{{ $commentLabel }}" title="{{ $commentLabel }}" aria-label="{{ $unreadCommentCount > 0 ? $commentLabel.' และมีคอมเมนต์ใหม่ที่ยังไม่ได้อ่าน' : $commentLabel }}"><i class="bi bi-chat-left-text" aria-hidden="true"></i><strong>{{ $commentCount ?: '-' }}</strong></button>
                        @if(auth()->user()->can('work', $task) || $canDeleteTask)
                            <details class="task-more-menu board-reference-menu">
                                <summary aria-label="เมนูจัดการรายการงาน"><i class="bi bi-three-dots-vertical"></i></summary>
                                <div class="board-task-menu">
                                    @can('work', $task)<button type="button" data-board-rename-task data-task-id="{{ $task->job_id }}" data-name="{{ $task->job_topic }}" data-url="{{ route('tasks.details.update', $task) }}"><i class="bi bi-pencil-square"></i><span><strong>แก้ไขชื่อรายการงาน</strong><small>แก้ไขชื่อของรายการนี้</small></span></button>@endcan
                                    @if($canDeleteTask)<button type="button" class="danger" data-board-delete-task data-url="{{ $taskDeleteUrl }}"><i class="bi bi-trash3"></i><span><strong>ลบรายการงาน</strong><small>นำรายการนี้ออกจากโปรเจกต์</small></span></button>@endif
                                </div>
                            </details>
                        @else
                            <span aria-hidden="true"></span>
                        @endif
                    </article>
                @endforeach
                @if($completedProjectTasks->isNotEmpty())</div></details>@endif
    @endforeach
</div>
