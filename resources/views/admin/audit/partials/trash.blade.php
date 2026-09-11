@php
    use App\Support\AuditSnapshot;

    /*
     * ตัวกรองลัดตอบคำถามที่ผู้ดูแลระบบถามบ่อยที่สุด
     *
     * "อะไรกำลังจะหาย" กับ "อะไรกู้ไม่ได้แล้ว" เดิมต้องเดาเอาจากคอลัมน์วันที่
     * เพราะตัวกรองมีแต่ประเภทข้อมูลกับแผนก
     *
     * กดซ้ำที่ชิปเดิมคือการยกเลิก จึงไม่ต้องมีปุ่ม "ล้าง" แยกอีกตัวสำหรับกลุ่มนี้
     */
    $activeShortcut = request('shortcut');
    $shortcutLink = fn (?string $key) => route('admin.audit.index', array_merge(
        request()->except(['shortcut', 'page']),
        ['tab' => 'trash'],
        $key === null || $key === $activeShortcut ? [] : ['shortcut' => $key],
    ));

    $shortcuts = [
        'expiring' => ['label' => 'ใกล้หมดเวลา 7 วัน', 'icon' => 'bi-alarm', 'count' => $stats['near_expiry']],
        'expired' => ['label' => 'กู้คืนไม่ได้แล้ว', 'icon' => 'bi-x-octagon', 'count' => null],
        'files' => ['label' => 'เฉพาะไฟล์และรูป', 'icon' => 'bi-paperclip', 'count' => $stats['files']],
    ];
@endphp

<section class="audit-stats">
    <div class="audit-stat">
        <span>ทั้งหมดตามตัวกรอง</span><strong>{{ $stats['total'] }}</strong><small>รายการที่ยังกู้คืนได้</small>
    </div>
    <div class="audit-stat">
        <span>งานและโปรเจกต์</span><strong>{{ $stats['work_items'] }}</strong><small>กู้คืนแล้วกลับเข้าบอร์ดเดิม</small>
    </div>
    <div class="audit-stat">
        <span>ไฟล์และรูป</span><strong>{{ $stats['files'] }}</strong><small>ไฟล์จริงยังอยู่ กู้คืนแล้วเปิดได้</small>
    </div>
    <div class="audit-stat {{ $stats['near_expiry'] > 0 ? 'audit-stat--warning' : '' }}">
        <span>ใกล้ถูกลบถาวร</span><strong>{{ $stats['near_expiry'] }}</strong><small>ภายใน 7 วัน หลังจากนั้นกู้คืนไม่ได้</small>
    </div>
</section>

{{--
    แถบเครื่องมือของถังขยะ: ตัวกรองลัดอยู่ซ้าย การกระทำที่ทำลายข้อมูลอยู่ขวาสุด
    ห่างจากชิปที่กดบ่อย เพื่อไม่ให้มือไปโดนโดยไม่ตั้งใจ
--}}
<div class="audit-trashbar">
    <div class="audit-trashbar__shortcuts" role="group" aria-label="ตัวกรองลัด">
        <a class="audit-chip {{ $activeShortcut ? '' : 'is-active' }}" href="{{ $shortcutLink(null) }}">ทั้งหมด</a>
        @foreach ($shortcuts as $key => $shortcut)
            <a class="audit-chip {{ $activeShortcut === $key ? 'is-active' : '' }}"
               href="{{ $shortcutLink($key) }}"
               @if($activeShortcut === $key) aria-current="true" @endif>
                <i class="bi {{ $shortcut['icon'] }}" aria-hidden="true"></i>
                {{ $shortcut['label'] }}
                @if ($shortcut['count'] !== null)
                    <b>{{ $shortcut['count'] }}</b>
                @endif
            </a>
        @endforeach
    </div>

    @if ($expiredCount > 0)
        {{-- การยืนยันเป็นหน้าที่ของ audit.js ผ่าน SweetAlert ไม่ใช่ native confirm --}}
        <form method="POST" action="{{ route('admin.trash.purge-expired') }}"
              data-audit-purge-expired data-count="{{ $expiredCount }}">
            @csrf
            @method('DELETE')
            <button class="audit-btn audit-btn--danger" type="submit">
                <i class="bi bi-trash3-fill" aria-hidden="true"></i>
                ล้างของหมดอายุ ({{ $expiredCount }})
            </button>
        </form>
    @endif
</div>

{{--
    แถบจัดการหลายรายการ

    ปุ่มลบรายแถวมีอยู่แล้ว แต่ผู้ดูแลระบบที่มีของค้างเป็นร้อยรายการไม่มีทางกดทีละแถว
    พร้อมพิมพ์ชื่อยืนยันจนครบ เมื่อไม่มีทางทำได้จริง ข้อมูลก็ค้างต่อไปเรื่อย ๆ

    ฟอร์มนี้อยู่ "นอก" ตาราง เพราะในตารางมีฟอร์มกู้คืนและลบรายแถวอยู่แล้ว การซ้อนฟอร์ม
    ในฟอร์มไม่ถูกต้องตาม HTML ช่องติ๊กในตารางจึงผูกกับฟอร์มนี้ด้วยแอตทริบิวต์ form
    แทนการอยู่ข้างใน

    ปุ่มทั้งสองใช้ formaction กับ name="_method" ของตัวปุ่มเอง ฟอร์มเดียวจึงยิงได้สอง
    ปลายทางโดยไม่ต้องพึ่ง JavaScript ตั้งค่าให้ก่อนส่ง

    formaction ต้องพาตัวกรองปัจจุบันไปด้วยเสมอ เพราะ "เลือกทั้งหมดที่กรองอยู่" อ่าน
    เงื่อนไขจาก query string ถ้าตกหล่นไป การลบจะกวาดทั้งถังขยะแทนที่จะเป็นเฉพาะที่กรอง
--}}
@if ($trashLogs->isNotEmpty())
    <form id="auditBulkForm" method="POST" class="audit-bulkbar" data-audit-bulk hidden>
        @csrf
        <input type="hidden" name="scope" value="" data-audit-scope>

        <p class="audit-bulkbar__count">
            เลือกแล้ว <strong data-audit-selected-count>0</strong> รายการ
        </p>

        @if ($stats['total'] > $trashLogs->count())
            <label class="audit-bulkbar__scope">
                <input type="checkbox" data-audit-scope-toggle data-count="{{ $stats['total'] }}">
                เลือกทั้งหมดที่กรองอยู่ ({{ $stats['total'] }} รายการ)
            </label>
        @endif

        <div class="audit-bulkbar__actions">
            <button class="audit-btn audit-btn--primary" type="submit"
                    formaction="{{ route('admin.trash.bulk-restore', request()->query()) }}"
                    name="_method" value="PATCH" data-audit-bulk-restore>
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> กู้คืนที่เลือก
            </button>

            <button class="audit-btn audit-btn--danger" type="submit"
                    formaction="{{ route('admin.trash.bulk-purge', request()->query()) }}"
                    name="_method" value="DELETE" data-audit-bulk-purge>
                <i class="bi bi-trash3-fill" aria-hidden="true"></i> ลบถาวรที่เลือก
            </button>
        </div>
    </form>
@endif

<section class="audit-card">
    @if ($trashLogs->isEmpty())
        <div class="audit-empty">
            <i class="bi bi-trash3" aria-hidden="true"></i>
            <strong>ไม่พบข้อมูลที่ถูกลบตามเงื่อนไขที่เลือก</strong>
            <span>ข้อมูลที่ถูกลบจะเก็บไว้ 30 วันก่อนลบถาวร รวมถึงไฟล์แนบและรูปภาพ</span>
        </div>
    @else
        <div class="audit-table-scroll">
            <table class="audit-table audit-table--trash">
                <thead>
                    <tr>
                        <th class="audit-col-select">
                            <input type="checkbox" data-audit-select-all
                                   aria-label="เลือกทุกรายการในหน้านี้">
                        </th>
                        <th scope="col">&#xE25;&#xE33;&#xE14;&#xE31;&#xE1A;</th>
                        <th>ข้อมูลที่ถูกลบ</th>
                        <th>แผนก</th>
                        <th>ใครเป็นคนลบ</th>
                        <th>ลบเมื่อ</th>
                        <th>เหลือเวลากู้คืน</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($trashLogs as $trash)
                        @php($summary = $trash->summary)
                        <tr>
                            <td class="audit-col-select">
                                {{-- ผูกกับฟอร์มด้านบนด้วย form= เพราะช่องนี้อยู่ในตารางที่มีฟอร์มอื่นอยู่แล้ว --}}
                                <input type="checkbox" form="auditBulkForm" name="ids[]"
                                       value="{{ $trash->id }}" data-audit-select
                                       aria-label="เลือก {{ $summary['name'] }}">
                            </td>
                            <td class="audit-row-number">{{ ($trashLogs->firstItem() ?? 1) + $loop->index }}</td>
                            <td>
                                <div class="audit-item">
                                    {{--
                                        ไฟล์แสดงไอคอนนำหน้าเพื่อให้กวาดตาหาเจอในตารางยาว ๆ
                                        ไม่แสดงภาพตัวอย่างจริง เพราะไฟล์ที่ถูกลบยังไม่ควรถูกเสิร์ฟ
                                        ออกจากระบบ การเปิดเส้นทางสื่อให้ไฟล์ในถังขยะคือการเพิ่ม
                                        ช่องทางเข้าถึงข้อมูลที่ตั้งใจเอาออกไปแล้ว
                                    --}}
                                    @if ($summary['is_file'])
                                        <span class="audit-item__icon" aria-hidden="true"><i class="bi bi-paperclip"></i></span>
                                    @endif
                                    <div>
                                        <div class="audit-strong">{{ $summary['name'] }}</div>
                                        <div class="audit-item-meta">
                                            <span class="audit-subject">{{ $summary['entity_label'] }}</span>
                                            <span class="audit-muted">ID #{{ $trash->entity_id }}</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="audit-context">{{ $summary['department'] }}</span></td>
                            <td>
                                <div class="audit-strong">{{ $trash->deletedBy?->name ?? 'ระบบ' }}</div>
                                <div class="audit-muted">{{ $trash->deletedBy?->email ?: ($trash->deletedBy?->username ? '@'.$trash->deletedBy->username : '') }}</div>
                            </td>
                            <td>
                                <time class="audit-time" datetime="{{ optional($trash->deleted_at)->toIso8601String() }}">
                                    {{ optional($trash->deleted_at)->format('d/m/Y') }}
                                </time>
                                <div class="audit-muted">{{ optional($trash->deleted_at)->format('H:i') }} น.</div>
                            </td>
                            <td>
                                {{-- บอกผลลัพธ์ ไม่ใช่ตัวเลขลอย ๆ ผู้อ่านต้องรู้ทันทีว่ายังกู้คืนทันไหม --}}
                                @if ($summary['days_left'] === null)
                                    <span class="audit-pill audit-pill--neutral">ไม่มีกำหนด</span>
                                @elseif ($summary['days_left'] <= 0)
                                    <span class="audit-pill audit-pill--danger">หมดเวลากู้คืน</span>
                                @elseif ($summary['days_left'] <= 7)
                                    <span class="audit-pill audit-pill--warning">อีก {{ $summary['days_left'] }} วัน</span>
                                @else
                                    <span class="audit-pill audit-pill--neutral">อีก {{ $summary['days_left'] }} วัน</span>
                                @endif
                                @if ($trash->purge_after)
                                    <div class="audit-muted">ลบถาวร {{ optional($trash->purge_after)->format('d/m/Y') }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="audit-row-actions">
                                    @if ($summary['can_restore'])
                                        {{-- การยืนยันเป็นหน้าที่ของ audit.js ผ่าน SweetAlert ไม่ใช่ native confirm --}}
                                        <form method="POST" action="{{ route('admin.trash.restore', $trash) }}"
                                              data-audit-restore data-name="{{ $summary['name'] }}">
                                            @csrf
                                            @method('PATCH')
                                            <button class="audit-btn audit-btn--primary" type="submit">
                                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> กู้คืน
                                            </button>
                                        </form>
                                    @endif

                                    <button type="button" class="audit-btn audit-btn--ghost"
                                            data-bs-toggle="modal" data-bs-target="#auditTrash{{ $trash->id }}">
                                        <i class="bi bi-list-columns" aria-hidden="true"></i> ข้อมูล
                                    </button>

                                    {{--
                                        ลบถาวรอยู่ท้ายสุดของแถวเสมอ ห่างจากปุ่มกู้คืนที่กดบ่อย
                                        และ audit.js บังคับให้พิมพ์ชื่อรายการให้ตรงก่อนยืนยัน
                                    --}}
                                    <form method="POST" action="{{ route('admin.trash.purge', $trash) }}"
                                          data-audit-purge data-name="{{ $summary['name'] }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="audit-btn audit-btn--danger" type="submit">
                                            <i class="bi bi-trash3" aria-hidden="true"></i> ลบถาวร
                                        </button>
                                    </form>

                                    <div class="modal fade audit-modal" id="auditTrash{{ $trash->id }}" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-scrollable modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">{{ $summary['entity_label'] }}: {{ $summary['name'] }}</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                                                </div>
                                                <div class="modal-body">
                                                    @if (empty($trash->readable))
                                                        <p class="audit-muted">ไม่มีข้อมูลที่แสดงเป็นตารางได้ ดูข้อมูลดิบด้านล่างแทน</p>
                                                    @else
                                                        <div class="audit-change-list">
                                                            @foreach ($trash->readable as $entry)
                                                                <div class="audit-change-row">
                                                                    <div class="audit-change-field">{{ $entry['label'] }}</div>
                                                                    <div class="audit-change-value">{{ $entry['value'] }}</div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endif

                                                    {{-- ข้อมูลดิบยังเปิดดูได้สำหรับการตรวจสอบเชิงลึก แต่ไม่ใช่มุมมองหลักอีกต่อไป --}}
                                                    <details class="audit-raw">
                                                        <summary>ดูข้อมูลดิบ (JSON)</summary>
                                                        <pre>{{ json_encode(AuditSnapshot::redact($trash->payload_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                    </details>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="audit-pagination">{{ $trashLogs->links() }}</div>
    @endif
</section>
