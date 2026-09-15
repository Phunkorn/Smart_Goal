{{--
    ช่องไอคอนนับไฟล์ของแถว — เอกสารกับรูปแยกจำนวน กดแล้วเปิดกล่องรายละเอียดของงานใบนั้น

    @param int    $jobId
    @param string $topic
    @param string $label      "ไฟล์แนบงาน" หรือ "ไฟล์แนบงานย่อย"
    @param int    $documents
    @param int    $images
    @param bool   $locked     ผู้ดูไม่มีสิทธิ์ดูไฟล์ของงาน (หรือของงานย่อยบางใบ)
--}}
@if($documents + $images > 0)
    <button type="button" class="project-report__chip project-report__chip--files" data-evidence-open="{{ $jobId }}"
        aria-label="{{ $label }} เอกสาร {{ $documents }} รูป {{ $images }} ของ {{ $topic }}">
        @if($documents > 0)<span><i class="bi bi-file-earmark-text" aria-hidden="true"></i>{{ $documents }}</span>@endif
        @if($images > 0)<span><i class="bi bi-image" aria-hidden="true"></i>{{ $images }}</span>@endif
        @if($locked)<span class="project-report__chip-lock" title="มีบางรายการที่ไม่มีสิทธิ์ดูไฟล์"><i class="bi bi-lock" aria-hidden="true"></i></span>@endif
    </button>
@elseif($locked)
    <span class="project-report__muted" title="ไม่มีสิทธิ์ดูไฟล์"><i class="bi bi-lock" aria-hidden="true"></i><span class="visually-hidden">ไม่มีสิทธิ์ดูไฟล์</span></span>
@else
    <span class="project-report__muted">—</span>
@endif
