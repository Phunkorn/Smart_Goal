@php use App\Support\WorkBoardDesign; @endphp
<div class="notion-modal completed-projects-modal" data-completed-projects-modal hidden>
    <section class="notion-modal-card completed-projects-modal__card" role="dialog" aria-modal="true" aria-labelledby="completed-projects-title">
        <header>
            <div>
                <span>PROJECT ARCHIVE</span>
                <strong id="completed-projects-title">โปรเจกต์ที่เสร็จแล้ว</strong>
                <small>เก็บงาน งานย่อย ผู้ร่วมงาน และไฟล์ของโปรเจกต์ไว้ครบถ้วน</small>
            </div>
            <button type="button" data-close-completed-projects aria-label="ปิดโปรเจกต์ที่เสร็จแล้ว"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>

        <div class="completed-projects-modal__body">
            <div class="completed-projects-modal__intro">
                <i class="bi bi-archive-fill" aria-hidden="true"></i>
                <div><strong>พื้นที่จัดเก็บโปรเจกต์</strong><span>กด “ดูข้อมูล” เพื่อดูงานที่เก็บไว้ หรือนำกลับมาเปิดเพื่อทำงานต่อได้ทุกเมื่อ</span></div>
            </div>

            <div class="completed-projects-modal__list" data-completed-projects-list>
                @forelse($archivedTaskLists as $project)
                    <article class="completed-projects-modal__item" data-completed-project="{{ $project->id }}">
                        <span class="completed-projects-modal__folder"><i class="bi bi-folder-check" aria-hidden="true"></i></span>
                        <div>
                            <strong>{{ $project->name }}</strong>
                            <span>{{ $project->work_orders_count }} งาน · {{ $project->attachments_count }} ไฟล์ · จัดเก็บ {{ $project->archived_at?->locale('th')->translatedFormat('j M Y') }}</span>
                        </div>
                        <div class="completed-projects-modal__actions">
                            {{-- ดูข้อมูลเป็นการอ่านอย่างเดียว ทุกคนที่เห็นโปรเจกต์นี้จึงเปิดดูได้ ไม่ผูกกับสิทธิ์ manage --}}
                            <button type="button" class="completed-projects-modal__ghost" data-preview-project="{{ $project->id }}"><i class="bi bi-eye" aria-hidden="true"></i> ดูข้อมูล</button>
                            @can('manage', $project)
                                <button type="button" data-restore-project data-name="{{ $project->name }}" data-url="{{ route('mytasks.lists.restore', $project) }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> เปิดอีกครั้ง</button>
                            @endcan
                        </div>
                    </article>
                @empty
                    <div class="completed-projects-modal__empty" data-completed-projects-empty>
                        <i class="bi bi-inbox" aria-hidden="true"></i><strong>ยังไม่มีโปรเจกต์ที่จัดเก็บ</strong><span>เมื่อทุกงานเสร็จ ปุ่มจัดเก็บจะปรากฏที่หัวโปรเจกต์</span>
                    </div>
                @endforelse
            </div>
        </div>
    </section>
</div>

{{--
    โมดัลอ่านอย่างเดียวสำหรับดูเนื้อหาของโปรเจกต์ที่จัดเก็บ
    เนื้อหาของทุกโปรเจกต์ถูกเรนเดอร์ไว้ล่วงหน้าและซ่อนไว้ JS เพียงสลับว่าอันไหนแสดง
    จึงไม่มีคำขอเพิ่มระหว่างใช้งาน และสิทธิ์ยังถูกตัดสินฝั่งเซิร์ฟเวอร์ตั้งแต่ตอนเรนเดอร์
--}}
<div class="notion-modal project-archive-preview" data-project-preview-modal hidden>
    <section class="notion-modal-card project-archive-preview__card" role="dialog" aria-modal="true" aria-labelledby="project-archive-preview-title">
        <header>
            <div>
                <span>PROJECT CONTENT</span>
                <strong id="project-archive-preview-title" data-project-preview-title>เนื้อหาในโปรเจกต์</strong>
                <small>ข้อมูลที่จัดเก็บไว้ ดูได้อย่างเดียว ไม่มีการแก้ไข</small>
            </div>
            <button type="button" data-close-project-preview aria-label="ปิดเนื้อหาโปรเจกต์"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>

        <div class="project-archive-preview__body">
            @foreach($archivedTaskLists as $project)
                @php $tasks = $archivedProjectTasks[$project->id] ?? collect(); @endphp
                <div class="project-archive-preview__panel" data-project-preview-content="{{ $project->id }}" data-preview-project-name="{{ $project->name }}" hidden>
                    <div class="project-archive-preview__summary">
                        <span><i class="bi bi-list-task" aria-hidden="true"></i> {{ $tasks->count() }} งาน</span>
                        <span><i class="bi bi-diagram-3" aria-hidden="true"></i> {{ $tasks->sum(fn ($task) => $task->children->count()) }} งานย่อย</span>
                        <span><i class="bi bi-paperclip" aria-hidden="true"></i> {{ $project->attachments_count }} ไฟล์ของโปรเจกต์</span>
                    </div>

                    @forelse($tasks as $task)
                        @php $status = WorkBoardDesign::status($task); @endphp
                        <article class="project-archive-preview__task">
                            <div class="project-archive-preview__task-head">
                                <strong>{{ $task->job_topic }}</strong>
                                <span class="project-archive-preview__status project-archive-preview__status--{{ $status['tone'] }}"><i class="bi {{ $status['icon'] }}" aria-hidden="true"></i> {{ $status['label'] }}</span>
                            </div>
                            <div class="project-archive-preview__meta">
                                <span><i class="bi bi-person" aria-hidden="true"></i> {{ $task->user?->name ?? 'ยังไม่ระบุผู้รับผิดชอบ' }}</span>
                                @if($task->job_due_at)
                                    <span><i class="bi bi-calendar-event" aria-hidden="true"></i> {{ $task->job_due_at->timezone('Asia/Bangkok')->locale('th')->translatedFormat('j M Y') }}</span>
                                @endif
                                @if($task->collaborators->isNotEmpty())
                                    <span><i class="bi bi-people" aria-hidden="true"></i> ผู้ร่วมงาน {{ $task->collaborators->count() }} คน</span>
                                @endif
                                @if($task->images->isNotEmpty())
                                    <span><i class="bi bi-paperclip" aria-hidden="true"></i> {{ $task->images->count() }} ไฟล์</span>
                                @endif
                            </div>

                            @if($task->children->isNotEmpty())
                                <ul class="project-archive-preview__subtasks">
                                    @foreach($task->children as $child)
                                        @php $childStatus = WorkBoardDesign::status($child); @endphp
                                        <li>
                                            <i class="bi bi-arrow-return-right" aria-hidden="true"></i>
                                            <span>{{ $child->job_topic }}</span>
                                            <em class="project-archive-preview__status project-archive-preview__status--{{ $childStatus['tone'] }}">{{ $childStatus['label'] }}</em>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if($task->images->isNotEmpty())
                                <ul class="project-archive-preview__files">
                                    @foreach($task->images as $file)
                                        <li><i class="bi bi-file-earmark" aria-hidden="true"></i> {{ $file->original_name }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </article>
                    @empty
                        <div class="project-archive-preview__empty">
                            <i class="bi bi-inbox" aria-hidden="true"></i>
                            <strong>โปรเจกต์นี้ไม่มีงานที่คุณเห็นได้</strong>
                            <span>อาจถูกจัดเก็บไว้ก่อนที่จะมีการเพิ่มงาน</span>
                        </div>
                    @endforelse
                </div>
            @endforeach
        </div>
    </section>
</div>
