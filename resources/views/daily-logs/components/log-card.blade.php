{{--
    บันทึกงานหนึ่งรายการในไทม์ไลน์

    ไฟล์นี้ถูก render สองทาง: ตอนโหลดหน้า และตอน controller ส่ง HTML กลับมา
    หลังบันทึกสำเร็จ จึงเป็นเทมเพลตแถวเพียงชุดเดียวของระบบ ฝั่ง JavaScript
    ต้องไม่ประกอบ markup ของแถวเอง ไม่งั้นดีไซน์จะเพี้ยนออกจากกันทันที
--}}
<article class="log-card log-card--{{ $presented['kind_tone'] }}"
    data-log-card
    data-log-id="{{ $presented['id'] }}"
    data-log-kind="{{ $presented['kind'] }}">

    <div class="log-card__time">
        <span class="log-card__clock">{{ $presented['started_time'] ?? '—' }}</span>
        <span class="log-card__duration">{{ $presented['duration_label'] }}</span>
    </div>

    <div class="log-card__body">
        <div class="log-card__chips">
            <span class="log-chip log-chip--{{ $presented['kind_tone'] }}">
                <i class="bi {{ $presented['kind_icon'] }}" aria-hidden="true"></i>
                {{ $presented['kind_label'] }}
            </span>

            @if($presented['category'])
                <span class="log-chip log-chip--{{ $presented['category']['tone'] }}">
                    @if($presented['category']['icon'])
                        <i class="bi {{ $presented['category']['icon'] }}" aria-hidden="true"></i>
                    @endif
                    {{ $presented['category']['name'] }}
                </span>
            @endif

            @if($presented['is_running'])
                <span class="log-chip log-chip--live">
                    <i class="bi bi-record-circle" aria-hidden="true"></i> กำลังทำ
                </span>
            @endif

            @if($presented['auto_closed'])
                {{-- ผู้ใช้ลืมกดจบงานข้ามคืน ระบบปิดให้แล้วแต่ตัวเลขอาจไม่ตรงจริง --}}
                <span class="log-chip log-chip--warning" title="ระบบปิดให้อัตโนมัติ โปรดตรวจสอบเวลา">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ตรวจสอบเวลา
                </span>
            @endif
        </div>

        <h3 class="log-card__title">{{ $presented['title'] }}</h3>

        <p class="log-card__meta">
            <span class="log-card__range">{{ $presented['time_range_label'] }}</span>

            @if($presented['location'])
                <span class="log-card__meta-item">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $presented['location'] }}
                </span>
            @endif

            @if($presented['requester_name'])
                <span class="log-card__meta-item">
                    <i class="bi bi-person" aria-hidden="true"></i> แจ้งโดย {{ $presented['requester_name'] }}
                </span>
            @endif

            @if($presented['project'])
                <span class="log-card__meta-item">
                    <i class="bi bi-folder2" aria-hidden="true"></i> {{ $presented['project']['name'] }}
                </span>
            @endif

            @if($presented['task'])
                <span class="log-card__meta-item @if($presented['task']['is_deleted']) log-card__meta-item--muted @endif">
                    <i class="bi bi-briefcase" aria-hidden="true"></i> {{ $presented['task']['topic'] }}
                </span>
            @endif

            @if(count($presented['participants']) > 0)
                {{-- คนที่ทำงานชิ้นนี้ด้วยกัน แสดงเป็นชื่อย่อเพื่อไม่ให้แถวยาวเกินไป --}}
                <span class="log-card__meta-item log-card__people"
                    title="ทำด้วยกันกับ {{ collect($presented['participants'])->pluck('name')->join(', ') }}">
                    <i class="bi bi-people" aria-hidden="true"></i>
                    @foreach($presented['participants'] as $person)
                        <span class="log-card__person" aria-hidden="true">{{ $person['initials'] }}</span>
                    @endforeach
                    <span class="visually-hidden">ทำด้วยกันกับ {{ collect($presented['participants'])->pluck('name')->join(', ') }}</span>
                </span>
            @endif

            @if(count($presented['attachments']) > 0)
                <span class="log-card__meta-item">
                    <i class="bi bi-paperclip" aria-hidden="true"></i> {{ count($presented['attachments']) }} ไฟล์
                </span>
            @endif
        </p>

        @if($presented['details'])
            <p class="log-card__details">{{ $presented['details'] }}</p>
        @endif
    </div>

    @if(! ($capabilities['isReadOnly'] ?? true))
        <div class="log-card__actions">
            {{-- ปุ่มจับเวลาอยู่ในแถวโดยตรง เพราะเป็นการกระทำที่ใช้บ่อยที่สุด
                 การซ่อนไว้ในเมนู "⋯" จะทำให้ต้องกดสองครั้งทุกครั้งที่จบงาน --}}
            @if($presented['is_running'])
                <button type="button" class="log-card__timer log-card__timer--stop" data-row-timer-stop>
                    <i class="bi bi-stop-circle" aria-hidden="true"></i>
                    <span>เสร็จสิ้น</span>
                </button>
            @elseif(($capabilities['canUseTimer'] ?? false) && $presented['status'] === 'open')
                <button type="button" class="log-card__timer log-card__timer--start" data-row-timer-start>
                    <i class="bi bi-play-fill" aria-hidden="true"></i>
                    <span>เริ่มงาน</span>
                </button>
            @endif

            <button type="button"
                class="log-card__menu-trigger"
                aria-haspopup="true"
                aria-expanded="false"
                aria-label="จัดการบันทึก {{ $presented['title'] }}"
                data-log-menu-trigger>
                <i class="bi bi-three-dots" aria-hidden="true"></i>
            </button>
        </div>
    @endif
</article>
