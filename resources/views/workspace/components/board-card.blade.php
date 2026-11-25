{{--
    การ์ดกระดานหนึ่งใบในหน้ารายการ

    ปุ่มตั้งค่าและลบตัดสินจาก Gate ทีละกระดาน ไม่ใช่จากตัวแปรของทั้งหน้า เพราะ
    ในแผนกเดียวกันคนสร้างกับหัวหน้าจัดการได้ แต่เพื่อนร่วมแผนกคนอื่นทำได้แค่วาด
    การซ่อนปุ่มนี้เป็นเรื่องของหน้าจอเท่านั้น การบังคับใช้จริงอยู่ที่ policy
    ในฝั่งเซิร์ฟเวอร์
--}}
@php
    $visibility = \App\Support\WorkspaceDesign::visibility($board->visibility);
    $canManage = \Illuminate\Support\Facades\Gate::allows('manageSettings', $board);
    $canDelete = \Illuminate\Support\Facades\Gate::allows('delete', $board);
@endphp

<article class="ws-board-card" data-workspace-board-card data-board-id="{{ $board->id }}">
    <a class="ws-board-card__link" href="{{ route('workspace.boards.show', $board) }}">
        {{-- ภาพประกอบเป็นของตกแต่งชุดเดียวกันทุกกระดาน ไม่โหลดเนื้อหาผืนผ้าใบมาทำตัวอย่าง --}}
        <span class="ws-board-card__visual" aria-hidden="true"></span>

        <span class="ws-board-card__body">
            <span class="ws-board-card__title" data-board-title>{{ $board->title }}</span>

            <span class="ws-board-card__meta">
                <span class="ws-chip ws-chip--{{ $visibility['tone'] }}" data-board-visibility-label>
                    <i class="bi {{ $visibility['icon'] }}" aria-hidden="true"></i>
                    {{ $visibility['label'] }}
                </span>
                <i class="bi bi-arrow-up-right ws-board-card__arrow" aria-hidden="true"></i>
            </span>
        </span>

        <span class="ws-board-card__footer">
            @if ($board->last_edited_at)
                <span>แก้ไขล่าสุดโดย {{ $board->lastEditor?->name ?? 'ไม่ทราบผู้แก้ไข' }}</span>
                <span>{{ \App\Support\TodayWorkspace::businessNow($board->last_edited_at)->format('d/m/Y · H:i') }}</span>
            @else
                <span>ยังไม่มีใครวาดบนกระดานนี้</span>
            @endif
        </span>
    </a>

    @if ($canManage || $canDelete)
        <div class="ws-board-card__actions">
            @if ($canManage)
                <button type="button" class="ws-icon-btn"
                    data-workspace-settings
                    data-board-id="{{ $board->id }}"
                    data-board-title="{{ $board->title }}"
                    data-board-visibility="{{ $board->visibility }}"
                    data-update-url="{{ route('workspace.boards.update', $board) }}"
                    aria-label="ตั้งค่ากระดาน {{ $board->title }}">
                    <i class="bi bi-gear" aria-hidden="true"></i>
                </button>
            @endif

            @if ($canDelete)
                <button type="button" class="ws-icon-btn ws-icon-btn--danger"
                    data-workspace-delete
                    data-board-id="{{ $board->id }}"
                    data-board-title="{{ $board->title }}"
                    data-delete-url="{{ route('workspace.boards.destroy', $board) }}"
                    aria-label="ลบกระดาน {{ $board->title }}">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
            @endif
        </div>
    @endif
</article>
