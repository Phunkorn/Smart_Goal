@php
    $isCompletedBoard = $isCompletedBoard ?? false;
    $allListTasks = $listTasks;
    $dueDates = $allListTasks->pluck('job_due_at')->filter()->sort();
    $projectTask = $allListTasks->sortByDesc('job_priority')->first();
    $priorityClass = match ((int) ($projectTask?->job_priority ?? 1)) {
        3 => 'project-priority-high',
        2 => 'project-priority-medium',
        default => 'project-priority-low',
    };
    $thaiMonths = [
        1 => 'ม.ค.',
        2 => 'ก.พ.',
        3 => 'มี.ค.',
        4 => 'เม.ย.',
        5 => 'พ.ค.',
        6 => 'มิ.ย.',
        7 => 'ก.ค.',
        8 => 'ส.ค.',
        9 => 'ก.ย.',
        10 => 'ต.ค.',
        11 => 'พ.ย.',
        12 => 'ธ.ค.',
    ];
    $formatThaiDate = fn($date) => $date->format('d') .
        ' ' .
        $thaiMonths[(int) $date->format('n')] .
        ' ' .
        ((int) $date->format('Y') + 543);
    $dueRange = $dueDates->isEmpty()
        ? 'ยังไม่มีกำหนดส่ง'
        : $formatThaiDate($dueDates->first()) .
            ($dueDates->count() > 1 ? ' – ' . $formatThaiDate($dueDates->last()) : '');
    $lastDueDate = $dueDates->last();
    $remainingDays = $lastDueDate
        ? now()
            ->startOfDay()
            ->diffInDays($lastDueDate->copy()->startOfDay(), false)
        : null;
    $remainingLabel = match (true) {
        $remainingDays === null => null,
        $remainingDays < 0 => 'เกินกำหนด ' . abs($remainingDays) . ' วัน',
        $remainingDays === 0 => 'ครบกำหนดวันนี้',
        default => 'เหลืออีก ' . $remainingDays . ' วัน',
    };
    $assigneeName = $projectTask?->user?->name;
    $adminSenderName = $projectTask?->creator?->role === 'admin' ? $projectTask->creator->name : null;
    $currentUser = auth()->user();
    $realListId = is_numeric($listId) ? (int) $listId : null;
    $listOwnerId = $realListId ? optional($taskLists->firstWhere('id', $realListId))->user_id : null;
    $canQuickAddToProject = $currentUser && (
        ($isVirtual && ($manageableTaskLists ?? collect())->isNotEmpty())
        || ($realListId && (int) $listOwnerId === (int) $currentUser->id)
    );
    $projectCompleted = $allListTasks->isNotEmpty() && $allListTasks->every(fn ($task) => (int) $task->job_status === 4);
    $projectLeader = $projectTask?->leader ?: $projectTask?->user;
    $projectMembers = $allListTasks
        ->flatMap(fn ($task) => collect([$task->leader, $task->user])->merge($task->collaborators))
        ->filter()
        ->unique('id')
        ->values();
    $avatarColors = ['#0073EA', '#E2445C', '#00C875', '#FDAB3D', '#7C4DFF', '#00A9A5'];
    $canManageProject = $realListId && $currentUser && (
        $currentUser->role === 'admin' || (int) $listOwnerId === (int) $currentUser->id
    ) && (! $projectCompleted || $currentUser->role === 'admin');
    $canManageProjectTeam = $projectTask && $currentUser && (! $projectCompleted || $currentUser->role === 'admin') && (
        $currentUser->role === 'admin'
        || (int) $projectTask->created_by === (int) $currentUser->id
        || (int) $projectTask->leader_user_id === (int) $currentUser->id
    );
@endphp

<article class="task-group {{ $isVisible ? '' : 'is-hidden' }}" data-list-lane="{{ $listId }}">
    <div class="group-head {{ $priorityClass }}">
        <div class="group-primary-row">
            <div class="group-title">
                <button type="button" class="group-toggle" data-collapse-group aria-label="พับกลุ่ม">
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="group-title-copy">
                    <div class="group-title-line">
                        <h2 class="group-name">{{ $listName }}</h2>
                        <span class="group-count">{{ $listTasks->count() }} งาน</span>
                    </div>
                    <span class="group-title-hint">โปรเจกต์และรายการงานย่อยทั้งหมดในกลุ่มนี้</span>
                </div>
                @if ($canManageProject)
                    <div class="group-actions">
                        <button type="button"
                            class="group-action-btn"
                            data-edit-list
                            data-list-id="{{ $realListId }}"
                            aria-label="แก้ไขชื่อโปรเจกต์">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button"
                            class="group-action-btn danger"
                            data-delete-list
                            data-list-id="{{ $realListId }}"
                            data-list-name="{{ $listName }}"
                            data-url="{{ route('mytasks.lists.destroy', $realListId) }}"
                            aria-label="ลบโปรเจกต์">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </div>
                @endif
            </div>

            @if ($projectLeader)
                <div class="group-team group-team-primary">
                    <div class="group-team-copy">
                        <span class="group-info-label">หัวหน้าโปรเจกต์</span>
                        <strong>{{ $projectLeader->name }}</strong>
                    </div>
                    <div class="avatar-stack group-member-stack" aria-label="สมาชิกโปรเจกต์">
                        @foreach ($projectMembers->take(6) as $index => $person)
                            <button type="button"
                                class="avatar-dot member-avatar-btn {{ $projectLeader && (int) $person->id === (int) $projectLeader->id ? 'is-leader' : '' }}"
                                style="background:{{ $avatarColors[$index % count($avatarColors)] }}"
                                title="{{ $person->name }}{{ $projectLeader && (int) $person->id === (int) $projectLeader->id ? ' - หัวหน้าโปรเจกต์' : ' - ผู้ร่วมโปรเจกต์' }}"
                                data-open-member-modal="project-{{ $listId }}-{{ $person->id }}">
                                {{ Str::of($person->name ?? 'U')->substr(0, 2)->upper() }}
                            </button>
                        @endforeach
                        @if ($projectMembers->count() > 6)
                            <span class="avatar-more">+{{ $projectMembers->count() - 6 }}</span>
                        @endif
                        @if ($canManageProjectTeam)
                            <button type="button"
                                class="avatar-add"
                                data-open-collaborator-modal
                                data-task-id="{{ $projectTask->job_id }}"
                                data-task-title="{{ $projectTask->job_topic }}"
                                data-existing-users="{{ collect([$projectTask->user_id, $projectTask->leader_user_id])->merge($projectTask->collaborators->pluck('id'))->filter()->unique()->values()->join(',') }}"
                                aria-label="เพิ่มผู้ร่วมโปรเจกต์">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        @if ($canManageProject)
            <form class="group-rename-form" data-list-rename-form="{{ $realListId }}" action="{{ route('mytasks.lists.update', $realListId) }}" method="POST" hidden>
                @csrf
                @method('PATCH')
                <input type="text" name="name" maxlength="80" required value="{{ $listName }}" aria-label="ชื่อโปรเจกต์">
                <button type="submit"><i class="bi bi-check-lg"></i></button>
                <button type="button" data-cancel-list-rename><i class="bi bi-x-lg"></i></button>
            </form>
        @endif

        <div class="group-summary">
            <div class="group-info-card">
                <span class="group-info-icon"><i class="bi bi-calendar3"></i></span>
                <div>
                    <span class="group-info-label">ช่วงเวลาดำเนินงาน</span>
                    <strong>{{ $dueRange }}</strong>
                </div>
            </div>

            @if ($remainingLabel)
                <div class="group-info-card group-info-card-warning">
                    <span class="group-info-icon"><i class="bi bi-hourglass-split"></i></span>
                    <div>
                        <span class="group-info-label">เวลาคงเหลือ</span>
                        <strong>{{ $remainingLabel }}</strong>
                    </div>
                </div>
            @endif

            @if ($assigneeName)
                <div class="group-info-card">
                    <span class="group-info-icon"><i class="bi bi-person-check"></i></span>
                    <div>
                        <span class="group-info-label">ผู้รับผิดชอบ</span>
                        <strong>{{ $assigneeName }}</strong>
                    </div>
                </div>
            @endif

            @if ($adminSenderName)
                <div class="group-info-card">
                    <span class="group-info-icon"><i class="bi bi-person-badge"></i></span>
                    <div>
                        <span class="group-info-label">มอบหมายโดย</span>
                        <strong>{{ $adminSenderName }}</strong>
                    </div>
                </div>
            @endif
        </div>
    </div>
    @foreach ($projectMembers as $index => $person)
        <div class="simple-modal member-info-modal" data-member-modal="project-{{ $listId }}-{{ $person->id }}" hidden>
            <div class="simple-modal-card member-info-card" role="dialog" aria-modal="true">
                <button type="button" class="simple-modal-close member-modal-close" data-close-inline-modal aria-label="ปิด">&times;</button>
                <div class="member-profile">
                    <div class="member-profile-avatar" style="background:{{ $avatarColors[$index % count($avatarColors)] }}">
                        @if ($person->profile_image)
                            <img src="{{ route('media.profile', $person) }}" alt="{{ $person->name }}">
                        @else
                            {{ Str::of($person->name ?? 'U')->substr(0, 2)->upper() }}
                        @endif
                    </div>
                    <div>
                        <h2>{{ $person->name }}</h2>
                        <p>{{ $projectLeader && (int) $person->id === (int) $projectLeader->id ? 'หัวหน้าโปรเจกต์' : 'ผู้ร่วมโปรเจกต์' }}</p>
                        <p>{{ optional($person->department)->department_name ?: 'ไม่ระบุแผนก' }}</p>
                        <p>{{ $person->phone ?: 'ไม่ระบุเบอร์โทร' }}</p>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="group-body">
        <div class="task-table-wrap">
            <table class="task-table">
                <colgroup>
                    <col class="col-check">
                    <col class="col-name">
                    <col class="col-priority">
                    <col class="col-progress">
                    <col class="col-due">
                    <col class="col-status">
                    <col class="col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th class="check-col"></th>
                        <th class="name-col">รายการงานย่อย</th>
                        <th>ความสำคัญ</th>
                        <th>ความคืบหน้า</th>
                        <th>กำหนดส่ง</th>
                        <th>สถานะ</th>
                        <th class="row-actions"></th>
                    </tr>
                </thead>
                <tbody data-group-body="{{ $listId }}">
                    @forelse ($listTasks as $task)
                        @include('tasks.partials.google-task-item', ['task' => $task])
                    @empty
                        <tr class="empty-row">
                            <td colspan="7">
                                <div class="empty-row-message">ยังไม่มีงานในรายการนี้</div>
                            </td>
                        </tr>
                    @endforelse
                    @if (! $isCompletedBoard && $canQuickAddToProject)
                        <tr class="add-row">
                            <td></td>
                            <td colspan="6">
                                <form class="add-task-inline" action="{{ route('mytasks.store') }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="work_order_list_id"
                                        value="{{ $isVirtual ? '' : $listId }}">
                                    <input type="text" name="job_topic" maxlength="255" required
                                        placeholder="+ เพิ่มงานย่อยในโปรเจกต์นี้">
                                    <button type="submit">เพิ่มงานย่อย</button>
                                </form>
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</article>
