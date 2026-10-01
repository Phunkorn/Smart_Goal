{{--
    ตารางประกาศในศูนย์ประกาศ

    แถวเดียวกันใช้ทั้งจอใหญ่และจอเล็ก (บนจอเล็ก CSS จัดเซลล์เป็นการ์ด โดยอ่านป้ายจาก
    data-label) จึงไม่มี markup ชุดที่สองซ่อนไว้

    เมนู ⋮ แสดงเฉพาะแถวที่ AnnouncementPolicy อนุญาต — ซ่อนเมนูเป็นเพียง UX
    การยิง PATCH/DELETE ตรง ๆ ยังถูก policy ตรวจซ้ำที่ controller
--}}
@if($announcements->isEmpty())
    <div class="announcements-page__empty">
        <i class="bi bi-megaphone" aria-hidden="true"></i>
        <p>ยังไม่มีประกาศในมุมมองนี้</p>
    </div>
@else
    <div class="announcements-table__scroll">
        <table class="table-clean announcements-table">
            <thead>
                <tr>
                    <th scope="col">หัวข้อประกาศ</th>
                    <th scope="col">ผู้ประกาศ</th>
                    <th scope="col">กลุ่มผู้รับ</th>
                    <th scope="col">วันที่แสดง</th>
                    <th scope="col">สถานะ</th>
                    <th scope="col"><span class="visually-hidden">การจัดการ</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($announcements as $announcement)
                    @php
                        $audience = \App\Support\AnnouncementDesign::audience($announcement->audience);
                        $status = \App\Support\AnnouncementDesign::status(\App\Support\AnnouncementDesign::statusFor($announcement, $today));
                        $author = $announcement->author;
                        $canUpdate = auth()->user()->can('update', $announcement);
                        $canDelete = auth()->user()->can('delete', $announcement);
                        $startsOn = \App\Support\AnnouncementDesign::shortDate($announcement->starts_on);
                        $endsOn = \App\Support\AnnouncementDesign::shortDate($announcement->ends_on);
                        // directive json ของ Blade แยกอาร์กิวเมนต์ด้วยเครื่องหมายจุลภาค จึงประกอบ array ไว้ก่อนแล้วค่อย encode
                        $editPayload = [
                            'id' => $announcement->id,
                            'title' => $announcement->title,
                            'body' => $announcement->body,
                            'audience' => $announcement->audience,
                            'starts_on' => $announcement->starts_on?->format('Y-m-d'),
                            'ends_on' => $announcement->ends_on?->format('Y-m-d'),
                            'update_url' => route('announcements.update', $announcement),
                        ];
                    @endphp
                    <tr data-announcement-row="{{ $announcement->id }}">
                        <td data-label="หัวข้อประกาศ" class="announcements-table__title">
                            <span class="announcements-table__audience-icon announcements-table__audience-icon--{{ $announcement->audience }}" aria-hidden="true">
                                <i class="bi {{ $audience['icon'] }}"></i>
                            </span>
                            <span class="announcements-table__title-text">
                                <strong>{{ $announcement->title }}</strong>
                                <small>{{ \Illuminate\Support\Str::limit($announcement->body, 90) }}</small>
                            </span>
                        </td>
                        <td data-label="ผู้ประกาศ">
                            <span class="announcements-table__author">
                                @if($author)
                                    @include('work-board.partials.avatar', ['user' => $author, 'size' => 'md'])
                                @endif
                                <span>
                                    <strong>{{ $author?->name ?? 'ไม่ทราบผู้ประกาศ' }}</strong>
                                    <small>{{ $author ? \App\Support\RoleLabel::withDepartment($author) : '' }}</small>
                                </span>
                            </span>
                        </td>
                        <td data-label="กลุ่มผู้รับ">
                            <span class="badge-soft {{ $audience['tone'] }}">{{ $audience['label'] }}</span>
                        </td>
                        <td data-label="วันที่แสดง" class="announcements-table__dates">
                            {{ $startsOn }}
                            <small>{{ $endsOn !== '' ? 'ถึง '.$endsOn : 'ไม่กำหนดวันสิ้นสุด' }}</small>
                        </td>
                        <td data-label="สถานะ">
                            <span class="badge-soft {{ $status['tone'] }}">{{ $status['label'] }}</span>
                        </td>
                        <td class="announcements-table__actions">
                            @if($canUpdate || $canDelete)
                                <div class="dropdown">
                                    {{-- ตารางอยู่ในกล่องเลื่อนแนวนอน (overflow-x) ซึ่งตัดเมนูที่ยื่นออกนอกกล่อง
                                         strategy fixed ให้ Popper วางเมนูเทียบกับหน้าจอ เมนูจึงลอยทับการ์ดได้ --}}
                                    <button class="announcements-table__menu" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport"
                                        data-bs-popper-config='{"strategy":"fixed"}'
                                        aria-expanded="false" aria-label="จัดการประกาศ: {{ $announcement->title }}">
                                        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        @if($canUpdate)
                                            <li>
                                                <button class="dropdown-item" type="button" data-announcement-edit
                                                    data-announcement="{{ json_encode($editPayload, JSON_UNESCAPED_UNICODE) }}">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i> แก้ไข
                                                </button>
                                            </li>
                                        @endif
                                        @if($canDelete)
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" action="{{ route('announcements.destroy', $announcement) }}"
                                                    data-announcement-delete data-announcement-title="{{ $announcement->title }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="dropdown-item text-danger" type="submit">
                                                        <i class="bi bi-trash3" aria-hidden="true"></i> ลบ
                                                    </button>
                                                </form>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($announcements->hasPages())
        <div class="announcements-page__pagination">{{ $announcements->links() }}</div>
    @endif
@endif
