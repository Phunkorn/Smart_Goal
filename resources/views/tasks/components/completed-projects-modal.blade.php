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
                <div><strong>พื้นที่จัดเก็บโปรเจกต์</strong><span>นำกลับมาเปิดเพื่อเพิ่มงานหรือทำงานต่อได้ทุกเมื่อ</span></div>
            </div>

            <div class="completed-projects-modal__list" data-completed-projects-list>
                @forelse($archivedTaskLists as $project)
                    <article class="completed-projects-modal__item" data-completed-project="{{ $project->id }}">
                        <span class="completed-projects-modal__folder"><i class="bi bi-folder-check" aria-hidden="true"></i></span>
                        <div>
                            <strong>{{ $project->name }}</strong>
                            <span>{{ $project->work_orders_count }} งาน · {{ $project->attachments_count }} ไฟล์ · จัดเก็บ {{ $project->archived_at?->locale('th')->translatedFormat('j M Y') }}</span>
                        </div>
                        @can('manage', $project)
                            <button type="button" data-restore-project data-name="{{ $project->name }}" data-url="{{ route('mytasks.lists.restore', $project) }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> เปิดอีกครั้ง</button>
                        @endcan
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
