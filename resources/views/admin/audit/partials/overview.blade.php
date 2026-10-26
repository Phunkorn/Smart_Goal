@php
    use App\Support\AuditSnapshot;
    use App\Support\TodayWorkspace;

    // ตัวเลขลอย ๆ ตีความได้หลายแบบ จึงต้องมีคำขยายบอกว่านับอะไรและนับถึงเมื่อไร
    $cards = [
        [
            'label' => 'เข้าสู่ระบบสำเร็จ',
            'value' => $stats['logins_today'],
            'note' => 'วันนี้ (เวลาไทย)',
            'icon' => 'bi-box-arrow-in-right',
            'tone' => '',
        ],
        [
            'label' => 'เข้าสู่ระบบไม่สำเร็จ',
            'value' => $stats['failed_logins_today'],
            'note' => 'วันนี้ รวมที่ถูกล็อกชั่วคราว',
            'icon' => 'bi-shield-exclamation',
            'tone' => $stats['failed_logins_today'] > 0 ? 'audit-stat--warning' : '',
        ],
        [
            'label' => 'แก้ไขข้อมูล',
            'value' => $stats['changes_today'],
            'note' => 'วันนี้ นับการสร้าง แก้ไข และลบ',
            'icon' => 'bi-pencil-square',
            'tone' => '',
        ],
        [
            'label' => 'รอกู้คืนในถังขยะ',
            'value' => $stats['trash_total'],
            'note' => 'เก็บไว้ 30 วันนับจากวันที่ลบ',
            'icon' => 'bi-trash3',
            'tone' => '',
        ],
        [
            'label' => 'ใกล้ถูกลบถาวร',
            'value' => $stats['near_expiry'],
            'note' => 'ภายใน 7 วัน กู้คืนไม่ได้หลังจากนั้น',
            'icon' => 'bi-hourglass-split',
            'tone' => $stats['near_expiry'] > 0 ? 'audit-stat--danger' : '',
        ],
    ];
@endphp

{{--
    สถานะงานตามเวลา

    งานล้างข้อมูลอัตโนมัติทั้งหมดทำงานได้ก็ต่อเมื่อเซิร์ฟเวอร์ตั้ง cron ให้เรียก
    schedule:run ซึ่งเป็นสิ่งที่อยู่นอกโค้ดและมองไม่เห็นจากหน้าเว็บมาก่อน แถบนี้ทำให้
    ผู้ดูแลระบบรู้ได้ทันทีว่าต้องกดล้างเองหรือไม่ แทนที่จะเข้าใจว่าระบบล้างให้อยู่แล้ว
--}}
@if ($scheduler['is_healthy'])
    <p class="audit-scheduler audit-scheduler--ok">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <span>งานอัตโนมัติทำงานปกติ — ล้างถังขยะและบันทึกเก่าให้เองทุกคืน
            (ทำงานล่าสุด {{ $scheduler['last_run']->timezone(TodayWorkspace::BUSINESS_TIMEZONE)->format('H:i น.') }})</span>
    </p>
@else
    <p class="audit-scheduler audit-scheduler--warn">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <span>
            <strong>งานอัตโนมัติไม่ทำงาน</strong> —
            @if ($scheduler['last_run'])
                ทำงานล่าสุดเมื่อ {{ $scheduler['last_run']->timezone(TodayWorkspace::BUSINESS_TIMEZONE)->format('d/m/Y H:i น.') }}
            @else
                ยังไม่เคยทำงานเลย
            @endif
            ข้อมูลเก่าจะไม่ถูกล้างเอง ให้ใช้ปุ่มล้างในแท็บกิจกรรมและถังขยะแทน
            หรือให้ผู้ดูแลเซิร์ฟเวอร์ตั้ง cron ให้ <code>php artisan schedule:run</code> ทุกนาที
        </span>
    </p>
@endif

{{--
    คิวข้อความ Telegram — จำนวนเท่านั้น ไม่มีเนื้อความหรือผู้รับ (TelegramOutbox::health)
    แสดงเฉพาะเมื่อเปิดใช้ Telegram และมีปัญหา: ค้างส่งนานเกินเกณฑ์ หรือส่งไม่สำเร็จใน 24 ชั่วโมง
--}}
@if ($telegram['enabled'] && ($telegram['backlog'] || $telegram['failed_24h'] > 0))
    <p class="audit-scheduler audit-scheduler--warn" data-telegram-health>
        <i class="bi bi-telegram" aria-hidden="true"></i>
        <span>
            <strong>Telegram มีข้อความค้างหรือส่งไม่สำเร็จ</strong> —
            รอส่ง {{ number_format($telegram['pending'] + $telegram['sending']) }} ข้อความ
            @if ($telegram['oldest_pending_at'])
                (ค้างตั้งแต่ {{ $telegram['oldest_pending_at']->timezone(TodayWorkspace::BUSINESS_TIMEZONE)->format('d/m/Y H:i น.') }})
            @endif
            · ส่งไม่สำเร็จใน 24 ชั่วโมง {{ number_format($telegram['failed_24h']) }} ข้อความ
            — ข้อความที่ต้องลองใหม่ส่งได้เองเมื่อ cron ของ <code>php artisan schedule:run</code> ทำงาน
            (ตรวจรายละเอียดด้วย <code>php artisan telegram:status</code>)
        </span>
    </p>
@endif

<dl class="audit-overview-summary" aria-label="ตัวเลขสรุปการตรวจสอบ">
    @foreach ($cards as $card)
        <div class="audit-overview-summary__item {{ $card['tone'] }}">
            <dt>{{ $card['label'] }}</dt>
            <dd>{{ $card['value'] }}</dd>
            <small>{{ $card['note'] }}</small>
        </div>
    @endforeach
</dl>

<section class="audit-overview-records" aria-labelledby="audit-overview-records-title">
    <div class="audit-overview-records__head">
        <div>
            <h2 id="audit-overview-records-title">บันทึกล่าสุด</h2>
            <p>เลือกประเภทข้อมูลที่ต้องการตรวจสอบ</p>
        </div>
        <div class="audit-overview-picker" role="tablist" aria-label="ประเภทบันทึกล่าสุด">
            <button class="audit-overview-picker__tab active" id="audit-recent-activity-tab" type="button" role="tab"
                data-bs-toggle="tab" data-bs-target="#audit-recent-activity" aria-controls="audit-recent-activity" aria-selected="true">
                กิจกรรมล่าสุด <span>{{ $recentActivity->count() }}</span>
            </button>
            <button class="audit-overview-picker__tab" id="audit-recent-trash-tab" type="button" role="tab"
                data-bs-toggle="tab" data-bs-target="#audit-recent-trash" aria-controls="audit-recent-trash" aria-selected="false">
                รายการที่ลบ <span>{{ $recentTrash->count() }}</span>
            </button>
        </div>
    </div>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="audit-recent-activity" role="tabpanel" aria-labelledby="audit-recent-activity-tab" tabindex="0">
            @if ($recentActivity->isEmpty())
                <div class="audit-empty">
                    <i class="bi bi-inbox" aria-hidden="true"></i>
                    <strong>ยังไม่มีกิจกรรมในช่วงที่เลือก</strong>
                    <span>ลองขยายช่วงวันที่ หรือล้างตัวกรอง</span>
                </div>
            @else
                <div class="audit-overview-table-scroll">
                    <table class="audit-overview-table">
                        <thead><tr><th scope="col">วันและเวลา</th><th scope="col">ผู้ทำรายการ</th><th scope="col">การกระทำ</th><th scope="col">รายละเอียด</th></tr></thead>
                        <tbody>
                            @foreach ($recentActivity as $log)
                                @php
                                    $entry = AuditSnapshot::describe($log);
                                    $localCreatedAt = $log->created_at?->copy()->timezone(TodayWorkspace::BUSINESS_TIMEZONE);
                                @endphp
                                <tr>
                                    <td><time datetime="{{ optional($log->created_at)->toIso8601String() }}">{{ optional($localCreatedAt)->format('d/m/Y H:i') }}</time></td>
                                    <td><strong>{{ $entry['actor'] }}</strong></td>
                                    <td><span class="audit-action audit-action--{{ strtolower($log->action) }}">{{ $entry['action'] }}</span></td>
                                    <td>
                                        <p class="audit-sentence">
                                            @if ($entry['subject']) <span class="audit-sentence__subject">{{ $entry['subject'] }}</span> @endif
                                            @if ($entry['target']) <em>{{ $entry['target'] }}</em> @endif
                                        </p>
                                        @if ($entry['meta']) <small>{{ $entry['meta'] }}</small> @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <a class="audit-overview-records__more" href="{{ route('admin.audit.index', array_merge(request()->query(), ['tab' => 'activity'])) }}">ดูกิจกรรมทั้งหมด <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>

        <div class="tab-pane fade" id="audit-recent-trash" role="tabpanel" aria-labelledby="audit-recent-trash-tab" tabindex="0">
            @if ($recentTrash->isEmpty())
                <div class="audit-empty">
                    <i class="bi bi-trash3" aria-hidden="true"></i>
                    <strong>ไม่มีข้อมูลในถังขยะ</strong>
                    <span>รายการที่ถูกลบจะมาปรากฏที่นี่</span>
                </div>
            @else
                <div class="audit-overview-table-scroll">
                    <table class="audit-overview-table">
                        <thead><tr><th scope="col">วันและเวลา</th><th scope="col">ผู้ลบ</th><th scope="col">ข้อมูลที่ลบ</th><th scope="col">สถานะ</th></tr></thead>
                        <tbody>
                            @foreach ($recentTrash as $trash)
                                @php($localDeletedAt = $trash->deleted_at?->copy()->timezone(TodayWorkspace::BUSINESS_TIMEZONE))
                                <tr>
                                    <td><time datetime="{{ optional($trash->deleted_at)->toIso8601String() }}">{{ optional($localDeletedAt)->format('d/m/Y H:i') }}</time></td>
                                    <td><strong>{{ $trash->deletedBy?->name ?? 'ระบบ' }}</strong></td>
                                    <td><p class="audit-sentence"><span class="audit-sentence__subject">{{ $trash->summary['entity_label'] }}</span><em>{{ $trash->summary['name'] }}</em></p></td>
                                    <td><span @class(['is-urgent' => $trash->summary['days_left'] !== null && $trash->summary['days_left'] <= 7])>{{ AuditSnapshot::retentionNote($trash->summary['days_left'], $trash->summary['can_restore']) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <a class="audit-overview-records__more" href="{{ route('admin.audit.index', array_merge(request()->query(), ['tab' => 'trash'])) }}">ดูถังขยะทั้งหมด <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
    </div>
</section>
