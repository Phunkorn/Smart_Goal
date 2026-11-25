<div class="work-board-page work-board-department-page wb-dept-{{ $departmentTone }}"
    data-work-board-directory
    data-work-board-mode="{{ $isAdmin ? 'admin' : 'user' }}">
    <nav class="wb-breadcrumb" aria-label="breadcrumb">
        <a href="{{ $breadcrumbUrl }}">{{ $breadcrumbLabel }}</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <strong>{{ $department->department_name }}</strong>
    </nav>

    <header class="wb-directory-header">
        <div>
            <h1>ทีม {{ $department->department_name }}</h1>
            <p>ดูว่าสมาชิกแต่ละคนกำลังรับผิดชอบงานอะไรอยู่ และติดตามข้อมูลล่าสุดได้จากที่นี่</p>
        </div>
    </header>

    <section class="wb-member-directory" aria-labelledby="workBoardMemberHeading">
        <div class="wb-member-directory__heading">
        </div>

        <div class="wb-member-card-grid" data-member-card-grid>
            @forelse($members as $person)
                @include('work-board.components.member-card', [
                    'person' => $person,
                    'priorityCover' => $loop->first,
                    'previewUrl' => route($previewRouteName, [$department, $person]),
                ])
            @empty
                <div class="wb-directory-empty" data-directory-empty>
                    <span class="wb-directory-empty__icon"><i class="bi bi-person-x" aria-hidden="true"></i></span>
                    <h3>ไม่พบสมาชิก</h3>
                    <p>ลองเปลี่ยนคำค้นหาหรือตัวกรอง แล้วค้นหาอีกครั้ง</p>
                    <a href="{{ $resetUrl }}">ล้างตัวกรอง</a>
                </div>
            @endforelse
        </div>
    </section>

    <x-account-modal id="workBoardMemberPreview" title="ภาพรวมงานสมาชิก" class="wb-member-preview-modal" data-member-preview-panel>
        <x-slot:header>
            <span class="wb-spotlight__avatar" data-preview-header-avatar hidden></span>
            <div class="wb-spotlight__identity">
                <h2 class="modal-title wb-spotlight__name" id="workBoardMemberPreviewTitle" data-preview-panel-title>ภาพรวมงานสมาชิก</h2>
                <div data-preview-header-meta hidden></div>
            </div>
            <div data-preview-header-action hidden></div>
            <button type="button" class="wb-spotlight__icon-button" data-bs-dismiss="modal" aria-label="ปิดภาพรวมงานสมาชิก">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </x-slot:header>

        <div class="modal-body wb-spotlight__body">
            <div class="wb-preview-state" role="status" data-preview-loading hidden>
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <p>กำลังโหลดรายการงาน...</p>
            </div>

            <div class="wb-preview-state" data-preview-error hidden>
                <span class="wb-preview-state__icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
                <h3>โหลดรายการงานไม่สำเร็จ</h3>
                <p>กรุณาลองใหม่อีกครั้ง</p>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-preview-retry>
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                    ลองใหม่
                </button>
            </div>

            <div data-preview-body hidden></div>
        </div>
        <div class="modal-footer wb-spotlight__footer">
            <span><i class="bi bi-person-lines-fill" aria-hidden="true"></i> ภาพรวมงานสมาชิก</span>
            <span><kbd>Esc</kbd> ปิดหน้าต่าง</span>
        </div>
    </x-account-modal>
</div>
