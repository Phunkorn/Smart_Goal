{{--
    บันทึกงานหนึ่งรายการในไทม์ไลน์

    ไฟล์นี้ถูก render สองทาง: ตอนโหลดหน้า และตอน controller ส่ง HTML กลับมา
    หลังบันทึกสำเร็จ จึงเป็นเทมเพลตแถวเพียงชุดเดียวของระบบ ฝั่ง JavaScript
    ต้องไม่ประกอบ markup ของแถวเอง ไม่งั้นดีไซน์จะเพี้ยนออกจากกันทันที
--}}
<article class="log-card log-card--{{ $presented['kind_tone'] }} log-card--status-{{ $presented['display_status'] }} @if($presented['is_done'] || $presented['is_skipped']) log-card--done @endif"
    id="work-log-{{ $presented['id'] }}"
    data-log-card
    data-log-id="{{ $presented['id'] }}"
    data-log-kind="{{ $presented['kind'] }}"
    @if($presented['planned_end_at']) data-planned-end-at="{{ $presented['planned_end_at'] }}" @endif
    {{-- สถานะอยู่บนการ์ดเอง เพราะ JavaScript ต้องรู้ว่าจะวางแถวไว้กลุ่มไหน
         หลังบันทึกสำเร็จ โดยไม่ต้องอ่าน payload ซ้ำอีกชั้น --}}
    data-log-status="{{ $presented['status'] }}">

    <div class="log-card__time">
        <span class="log-card__clock">{{ $presented['started_time'] ?? $presented['planned_start_time'] ?? '—' }}</span>
        @if($presented['is_in_progress'] && $presented['started_at'])
            <span class="log-card__duration log-card__elapsed"
                data-routine-elapsed
                data-started-at="{{ $presented['started_at'] }}">กำลังทำ 0 นาที</span>
        @else
            <span class="log-card__duration">{{ $presented['duration_label'] }}</span>
        @endif
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

            @if($presented['routine'] && $presented['routine']['window'])
                {{-- ช่วงเวลาที่ตั้งไว้ว่าต้องเข้าไปทำ ไม่ใช่เวลาที่ทำจริง
                     รายการที่ยังค้างอยู่ยังไม่มีเวลาจริงให้แสดงเลย --}}
                <span class="log-chip log-chip--plan">
                    <i class="bi bi-alarm" aria-hidden="true"></i> {{ $presented['routine']['window'] }}
                </span>
            @endif

            @if($presented['routine'] && $presented['routine']['is_shared'])
                <span class="log-chip log-chip--gray" title="งานประจำที่ {{ $presented['routine']['owner_name'] }} ตั้งไว้">
                    <i class="bi bi-person-plus" aria-hidden="true"></i> จาก {{ $presented['routine']['owner_name'] }}
                </span>
            @endif

            <span class="log-chip log-chip--{{ $presented['status_tone'] }}" data-log-status-chip>
                <i class="bi {{ \App\Support\WorkLogDesign::status($presented['display_status'])['icon'] }}" aria-hidden="true"></i>
                {{ $presented['status_label'] }}
            </span>

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

        @if($presented['late_start_reason'])
            <p class="log-card__reason"><strong>เริ่มช้า:</strong> {{ $presented['late_start_reason'] }}</p>
        @endif
        @if($presented['late_completion_reason'])
            <p class="log-card__reason"><strong>เสร็จเกินเวลา:</strong> {{ $presented['late_completion_reason'] }}</p>
        @endif
        @if($presented['skip_reason'])
            <p class="log-card__reason"><strong>เหตุผลที่ไม่ได้ทำ:</strong> {{ $presented['skip_reason'] }}</p>
        @endif
    </div>

    @if(! ($capabilities['isReadOnly'] ?? true))
        <div class="log-card__actions">
            {{-- งานประจำบันทึกเวลาเริ่มจริงและเวลาจบจริง ส่วนงานครั้งเดียวคงการยืนยันแบบเดิม

                 วันที่ผ่านไปแล้วไม่มีปุ่มเริ่มงานและปุ่มเสร็จงานเลย เพราะการกดย้อนหลัง
                 จะบันทึกเวลาของวันนี้ลงในรายการของเมื่อวาน เหลือปุ่มเดียวคือระบุเหตุผล --}}
            @if($presented['is_missed'])
                <button type="button" class="log-card__action log-card__action--reason" data-row-skip data-missed-reason>
                    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                    <span>ระบุเหตุผลที่ไม่ได้ทำ</span>
                </button>
            @elseif($presented['routine'] && $presented['status'] === 'open')
                <button type="button" class="log-card__action log-card__action--start" data-row-start
                    @if($presented['planned_start_at']) data-planned-start-at="{{ $presented['planned_start_at'] }}" @endif
                    @unless($presented['can_start']) disabled title="เริ่มได้เมื่อถึงเวลาที่กำหนด" @endunless>
                    <i class="bi bi-play-fill" aria-hidden="true"></i>
                    <span>{{ $presented['can_start'] ? 'เริ่มงาน' : 'ยังไม่ถึงเวลา' }}</span>
                </button>
                <button type="button" class="log-card__action log-card__action--skip" data-row-skip>
                    <i class="bi bi-calendar-x" aria-hidden="true"></i><span>ไม่ได้ทำวันนี้</span>
                </button>
            @elseif($presented['routine'] && $presented['status'] === 'in_progress')
                <button type="button" class="log-card__action log-card__action--done" data-row-complete>
                    <i class="bi bi-check2" aria-hidden="true"></i><span>เสร็จงาน</span>
                </button>
                <button type="button" class="log-card__action log-card__action--skip" data-row-skip>
                    <i class="bi bi-calendar-x" aria-hidden="true"></i><span>ไม่ได้ทำวันนี้</span>
                </button>
            @elseif(! $presented['is_done'] && ! $presented['is_skipped'])
                <button type="button" class="log-card__action log-card__action--done" data-row-complete>
                    <i class="bi bi-check2" aria-hidden="true"></i>
                    <span>ทำเสร็จแล้ว</span>
                </button>
            @else
                <button type="button" class="log-card__action log-card__action--undo" data-row-reopen>
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                    <span>ยังไม่เสร็จ</span>
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
