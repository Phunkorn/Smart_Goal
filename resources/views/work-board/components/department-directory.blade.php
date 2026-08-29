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

    <aside class="offcanvas offcanvas-end wb-member-preview-panel"
        tabindex="-1"
        id="workBoardMemberPreview"
        aria-labelledby="workBoardMemberPreviewTitle"
        data-member-preview-panel>
        <div class="offcanvas-header wb-member-preview-panel__header">
            <div>
                <span>ภาพรวมงาน</span>
                <h2 class="offcanvas-title" id="workBoardMemberPreviewTitle" data-preview-panel-title>งานของสมาชิก</h2>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="ปิดแผงงานของสมาชิก"></button>
        </div>

        <div class="offcanvas-body wb-member-preview-panel__body" aria-live="polite">
            <div class="wb-preview-state wb-preview-state--loading" data-preview-loading hidden>
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <p>กำลังโหลดรายการงาน...</p>
            </div>

            <div class="wb-preview-state wb-preview-state--error" data-preview-error hidden>
                <span class="wb-preview-state__icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
                <h3>โหลดรายการงานไม่สำเร็จ</h3>
                <p>กรุณาลองใหม่อีกครั้ง</p>
                <button type="button" data-preview-retry>
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                    ลองใหม่
                </button>
            </div>

            <div data-preview-body hidden></div>
        </div>
    </aside>
</div>
