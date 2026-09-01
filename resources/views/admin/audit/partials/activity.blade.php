@php
    use App\Support\AuditSnapshot;
@endphp

<section class="audit-card">
    @if ($logs->isEmpty())
        <div class="audit-empty">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <strong>ไม่พบกิจกรรมตามเงื่อนไขที่เลือก</strong>
            <span>ลองขยายช่วงวันที่ หรือล้างตัวกรองแล้วค้นหาใหม่</span>
        </div>
    @else
        <div class="audit-table-scroll">
            <table class="audit-table audit-table--activity">
                <thead>
                    <tr>
                        <th>เวลา</th>
                        <th>ผู้ทำรายการ</th>
                        <th>สิ่งที่เกิดขึ้น</th>
                        <th>คำอธิบายจากระบบ</th>
                        <th><span class="visually-hidden">เปิดดูข้อมูลก่อนและหลัง</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        @php
                            $sets = AuditSnapshot::changeSets($log);
                            $before = $sets['before'];
                            $after = $sets['after'];
                            $actionKey = strtolower($log->action);
                            $entry = AuditSnapshot::describe($log);
                            $actionLabel = $entry['action'];
                            $subjectLabel = AuditSnapshot::subjectLabel($log->subject_type);
                            $targetName = $entry['target'];
                            $isAuth = AuditSnapshot::isAuthAction($log->action);
                            $displayFields = collect(array_unique(array_merge(array_keys($before), array_keys($after))))
                                ->reject(fn ($field) => in_array($field, AuditSnapshot::HIDDEN_FIELDS, true));
                        @endphp
                        <tr @class(['is-auth-event' => $isAuth])>
                            <td>
                                <time class="audit-time" datetime="{{ optional($log->created_at)->toIso8601String() }}">
                                    {{ optional($log->created_at)->format('d/m/Y H:i') }}
                                </time>
                            </td>
                            <td>
                                <div class="audit-strong">{{ $entry['actor'] }}</div>
                                <div class="audit-muted">{{ $entry['meta'] ?: 'ไม่ทราบหมายเลข IP' }}</div>
                            </td>
                            <td>
                                {{-- รวมการกระทำกับเป้าหมายเป็นประโยคเดียว เพราะแยกคอลัมน์แล้วผู้อ่านต้องประกอบความหมายเอง --}}
                                <p class="audit-sentence">
                                    <span class="audit-action audit-action--{{ $actionKey }}">{{ $actionLabel }}</span>
                                    @if ($entry['subject'])
                                        <span class="audit-sentence__subject">{{ $entry['subject'] }}</span>
                                    @endif
                                    @if ($targetName)
                                        <em>{{ $targetName }}</em>
                                    @endif
                                </p>
                                @if ($log->subject_id)
                                    <div class="audit-muted">รหัสรายการ #{{ $log->subject_id }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="audit-summary">{{ $log->description ?: 'ระบบไม่ได้บันทึกคำอธิบายไว้' }}</div>
                            </td>
                            <td class="audit-detail-cell">
                                @if ($displayFields->isNotEmpty())
                                    <button type="button" class="audit-btn audit-btn--ghost"
                                            data-bs-toggle="modal" data-bs-target="#auditLog{{ $log->id }}">
                                        <i class="bi bi-eye" aria-hidden="true"></i> รายละเอียด
                                    </button>

                                    <div class="modal fade audit-modal" id="auditLog{{ $log->id }}" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-scrollable modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">{{ $actionLabel }} {{ $targetName ?: $subjectLabel }}</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="audit-change-list">
                                                        @foreach ($displayFields as $field)
                                                            @php
                                                                $oldValue = $before[$field] ?? null;
                                                                $newValue = $after[$field] ?? null;
                                                                $formattedOld = AuditSnapshot::formatValue($field, $oldValue);
                                                                $formattedNew = AuditSnapshot::formatValue($field, $newValue);
                                                                $fieldLabel = AuditSnapshot::fieldLabels()[$field] ?? str_replace('_', ' ', ucfirst($field));
                                                                $isImageField = $field === 'profile_image';
                                                            @endphp
                                                            @if (in_array($actionKey, ['deleted', 'delete']))
                                                                @if (array_key_exists($field, $before))
                                                                    <div class="audit-change-row">
                                                                        <div class="audit-change-field">{{ $fieldLabel }}</div>
                                                                        <div class="audit-change-value">
                                                                            @if ($isImageField && $oldValue)
                                                                                @include('admin.audit.components.profile-image', ['path' => $oldValue, 'label' => 'รูปโปรไฟล์'])
                                                                            @else
                                                                                {{ $formattedOld }}
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                @endif
                                                            @elseif (in_array($actionKey, ['created', 'create']))
                                                                @if (array_key_exists($field, $after))
                                                                    <div class="audit-change-row">
                                                                        <div class="audit-change-field">{{ $fieldLabel }}</div>
                                                                        <div class="audit-change-value">
                                                                            @if ($isImageField && $newValue)
                                                                                @include('admin.audit.components.profile-image', ['path' => $newValue, 'label' => 'รูปโปรไฟล์'])
                                                                            @else
                                                                                {{ $formattedNew }}
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                @endif
                                                            @elseif ($formattedOld !== $formattedNew)
                                                                <div class="audit-change-row">
                                                                    <div class="audit-change-field">{{ $fieldLabel }}</div>
                                                                    <div class="audit-change-value">
                                                                        @if ($isImageField)
                                                                            <div class="audit-avatar-group">
                                                                                @if ($oldValue)
                                                                                    @include('admin.audit.components.profile-image', ['path' => $oldValue, 'label' => 'รูปโปรไฟล์เดิม', 'extraClass' => 'audit-avatar-old'])
                                                                                @else
                                                                                    <span class="audit-value-old">{{ $formattedOld }}</span>
                                                                                @endif
                                                                                <i class="bi bi-arrow-right audit-change-arrow" aria-hidden="true"></i>
                                                                                @if ($newValue)
                                                                                    @include('admin.audit.components.profile-image', ['path' => $newValue, 'label' => 'รูปโปรไฟล์ใหม่', 'extraClass' => 'audit-avatar-new'])
                                                                                @else
                                                                                    <span class="audit-value-new">{{ $formattedNew }}</span>
                                                                                @endif
                                                                            </div>
                                                                        @else
                                                                            <span class="audit-value-old">{{ $formattedOld }}</span>
                                                                            <span class="audit-change-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                                                                            <span class="audit-value-new">{{ $formattedNew }}</span>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <span class="audit-muted">ไม่มีรายละเอียดเพิ่มเติม</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="audit-pagination">{{ $logs->links() }}</div>
    @endif
</section>
