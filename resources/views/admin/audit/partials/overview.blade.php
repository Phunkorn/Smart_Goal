@php
    use App\Support\AuditSnapshot;

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

<section class="audit-stats audit-stats--overview">
    @foreach ($cards as $card)
        <div class="audit-stat {{ $card['tone'] }}">
            <i class="bi {{ $card['icon'] }}" aria-hidden="true"></i>
            <span>{{ $card['label'] }}</span>
            <strong>{{ $card['value'] }}</strong>
            <small>{{ $card['note'] }}</small>
        </div>
    @endforeach
</section>

<div class="audit-overview-grid">
    <section class="audit-card">
        <header class="audit-card__head">
            <div>
                <h2>สิ่งที่เกิดขึ้นล่าสุด</h2>
                <small>ใครทำอะไรกับข้อมูลบ้าง เรียงจากใหม่ไปเก่า</small>
            </div>
            <a href="{{ route('admin.audit.index', array_merge(request()->query(), ['tab' => 'activity'])) }}">
                ดูทั้งหมด <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </header>

        @if ($recentActivity->isEmpty())
            <div class="audit-empty">
                <i class="bi bi-inbox" aria-hidden="true"></i>
                <strong>ยังไม่มีกิจกรรมในช่วงที่เลือก</strong>
                <span>ลองขยายช่วงวันที่ หรือล้างตัวกรอง</span>
            </div>
        @else
            <ol class="audit-stream">
                @foreach ($recentActivity as $log)
                    @php($entry = AuditSnapshot::describe($log))
                    <li @class(['is-auth-event' => AuditSnapshot::isAuthAction($log->action)])>
                        <time datetime="{{ optional($log->created_at)->toIso8601String() }}">
                            {{ optional($log->created_at)->format('d/m') }}
                            <b>{{ optional($log->created_at)->format('H:i') }}</b>
                        </time>
                        <div>
                            {{-- อ่านเป็นประโยคเดียว: ใคร ทำอะไร กับอะไร --}}
                            <p class="audit-sentence">
                                <strong>{{ $entry['actor'] }}</strong>
                                <span class="audit-action audit-action--{{ strtolower($log->action) }}">{{ $entry['action'] }}</span>
                                @if ($entry['subject'])
                                    <span class="audit-sentence__subject">{{ $entry['subject'] }}</span>
                                @endif
                                @if ($entry['target'])
                                    <em>{{ $entry['target'] }}</em>
                                @endif
                            </p>
                            @if ($entry['meta'])
                                <small>{{ $entry['meta'] }}</small>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <section class="audit-card">
        <header class="audit-card__head">
            <div>
                <h2>ลบล่าสุด</h2>
                <small>ยังกู้คืนได้จนกว่าจะครบกำหนด</small>
            </div>
            <a href="{{ route('admin.audit.index', array_merge(request()->query(), ['tab' => 'trash'])) }}">
                ดูทั้งหมด <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </header>

        @if ($recentTrash->isEmpty())
            <div class="audit-empty">
                <i class="bi bi-trash3" aria-hidden="true"></i>
                <strong>ไม่มีข้อมูลในถังขยะ</strong>
                <span>รายการที่ถูกลบจะมาปรากฏที่นี่</span>
            </div>
        @else
            <ol class="audit-stream">
                @foreach ($recentTrash as $trash)
                    <li>
                        <time datetime="{{ optional($trash->deleted_at)->toIso8601String() }}">
                            {{ optional($trash->deleted_at)->format('d/m') }}
                            <b>{{ optional($trash->deleted_at)->format('H:i') }}</b>
                        </time>
                        <div>
                            <p class="audit-sentence">
                                <strong>{{ $trash->deletedBy?->name ?? 'ระบบ' }}</strong>
                                <span class="audit-action audit-action--deleted">ลบ</span>
                                <span class="audit-sentence__subject">{{ $trash->summary['entity_label'] }}</span>
                                <em>{{ $trash->summary['name'] }}</em>
                            </p>
                            <small @class(['is-urgent' => $trash->summary['days_left'] !== null && $trash->summary['days_left'] <= 7])>
                                {{ AuditSnapshot::retentionNote($trash->summary['days_left'], $trash->summary['can_restore']) }}
                            </small>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</div>
