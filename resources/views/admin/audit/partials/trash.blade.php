@php
    use App\Support\AuditSnapshot;
@endphp

<section class="audit-stats">
    <div class="audit-stat">
        <span>ทั้งหมดตามตัวกรอง</span><strong>{{ $stats['total'] }}</strong><small>รายการที่ยังกู้คืนได้</small>
    </div>
    <div class="audit-stat">
        <span>งานและโปรเจกต์</span><strong>{{ $stats['work_items'] }}</strong><small>กู้คืนแล้วกลับเข้าบอร์ดเดิม</small>
    </div>
    <div class="audit-stat">
        <span>บัญชีพนักงาน</span><strong>{{ $stats['users'] }}</strong><small>กู้คืนแล้วเข้าใช้งานได้อีกครั้ง</small>
    </div>
    <div class="audit-stat {{ $stats['near_expiry'] > 0 ? 'audit-stat--warning' : '' }}">
        <span>ใกล้ถูกลบถาวร</span><strong>{{ $stats['near_expiry'] }}</strong><small>ภายใน 7 วัน หลังจากนั้นกู้คืนไม่ได้</small>
    </div>
</section>

<section class="audit-card">
    @if ($trashLogs->isEmpty())
        <div class="audit-empty">
            <i class="bi bi-trash3" aria-hidden="true"></i>
            <strong>ไม่พบข้อมูลที่ถูกลบตามเงื่อนไขที่เลือก</strong>
            <span>ข้อมูลที่ถูกลบจะเก็บไว้ 30 วันก่อนลบถาวร</span>
        </div>
    @else
        <div class="audit-table-scroll">
            <table class="audit-table audit-table--trash">
                <thead>
                    <tr>
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
                            <td>
                                <div class="audit-strong">{{ $summary['name'] }}</div>
                                <div class="audit-item-meta">
                                    <span class="audit-subject">{{ $summary['entity_label'] }}</span>
                                    <span class="audit-muted">ID #{{ $trash->entity_id }}</span>
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
                                        <i class="bi bi-list-columns" aria-hidden="true"></i> ข้อมูลที่บันทึกไว้
                                    </button>

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
