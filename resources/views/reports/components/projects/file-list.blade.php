{{--
    รายชื่อไฟล์แนบของงานหนึ่งใบ — ใช้ในกล่องรายละเอียดหลักฐานผลงาน

    ลิงก์ชี้ไป MediaController ซึ่งตรวจสิทธิ์ซ้ำทุกครั้งที่เปิดไฟล์ ไม่มี path ของไฟล์หลุดออกมา
    งานที่ผู้ดูไม่มีสิทธิ์ (locked) ไม่แสดงแม้ชื่อไฟล์ ไฟล์รูปแสดงเป็นไอคอน ไม่โหลดภาพย่อ

    @param array $evidence  ['files' => [...], 'locked' => bool]
--}}
@if($evidence['locked'])
    <span class="project-report__muted"><i class="bi bi-lock" aria-hidden="true"></i> ไม่มีสิทธิ์ดูไฟล์</span>
@elseif($evidence['files'] === [])
    <span class="project-report__muted">ไม่มีไฟล์แนบ</span>
@else
    <ul class="project-report__files">
        @foreach($evidence['files'] as $file)
            <li>
                <a href="{{ $file['url'] }}" target="_blank" rel="noopener" title="{{ $file['name'] }}">
                    <i class="bi {{ $file['is_image'] ? 'bi-image' : 'bi-file-earmark-text' }}" aria-hidden="true"></i>
                    <span>{{ $file['name'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
