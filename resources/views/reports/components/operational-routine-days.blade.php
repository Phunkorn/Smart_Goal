{{--
    งานประจำรายวันของคนที่ถูกเลือก

    ตอบคำถามเดียวในบรรทัดเดียว: วันนี้เขาทำงานประจำครบไหม
    เรียงวันล่าสุดขึ้นก่อน เพราะคำถามที่ถูกถามบ่อยที่สุดคือ "เมื่อวานเป็นยังไง"

    แสดงเฉพาะวันที่มีงานประจำจริง ไม่เติมวันว่างให้ครบช่วง เพราะตารางแบ่งหน้าละสิบแถว
    วันหยุดที่ไม่มีงานประจำเลยจะกินโควตาหน้าไปโดยไม่ได้ตอบอะไร
--}}
<section class="report-panel report-operational-days" aria-labelledby="operational-days-title">
    <div class="report-panel__heading">
        <div>
            <h2 id="operational-days-title">งานประจำรายวัน</h2>
            <p>{{ $ownerName }} — {{ $filters['period_label'] }}</p>
        </div>
        <span class="report-panel__count">{{ $routineDays->count() }} วัน</span>
    </div>

    @if($routineDays->isEmpty())
        <p class="report-empty">ยังไม่มีงานประจำของคนนี้ในช่วงเวลาที่เลือก</p>
    @else
        <div class="report-department-scroll">
            <table class="report-department-table report-operational-days__table"
                data-operational-days-table data-page-size="10">
                <caption class="visually-hidden">
                    งานประจำรายวันของ {{ $ownerName }} ในช่วง {{ $filters['period_label'] }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">วัน</th>
                        <th scope="col" class="report-operational-table__number">งานประจำ</th>
                        <th scope="col" class="report-operational-table__number">ทำแล้ว</th>
                        <th scope="col" class="report-operational-table__number">ข้าม</th>
                        <th scope="col" class="report-operational-table__number">ค้าง</th>
                        <th scope="col" class="report-operational-table__number">เวลาที่ใช้</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($routineDays as $day)
                        <tr data-operational-days-row>
                            <th scope="row" data-label="วัน">
                                <time datetime="{{ $day['date'] }}">{{ $day['label'] }}</time>
                            </th>
                            <td data-label="งานประจำ" class="report-operational-table__number">{{ number_format($day['total']) }}</td>
                            <td data-label="ทำแล้ว" class="report-operational-table__number">{{ number_format($day['done']) }}</td>
                            <td data-label="ข้าม" class="report-operational-table__number">{{ number_format($day['skipped']) }}</td>
                            <td data-label="ค้าง" class="report-operational-table__number">
                                {{-- วันที่ยังค้างคือวันที่ต้องถาม จึงต้องเห็นได้โดยไม่ต้องเทียบตัวเลขเอง --}}
                                @if($day['pending'] > 0)
                                    <span class="report-tag report-tone-red">{{ number_format($day['pending']) }}</span>
                                @else
                                    {{ 0 }}
                                @endif
                            </td>
                            <td data-label="เวลาที่ใช้" class="report-operational-table__number">{{ $day['minutes_label'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <nav class="report-table-pager" data-operational-days-pager aria-label="เปลี่ยนหน้าตารางรายวัน" hidden>
            <button type="button" data-operational-days-previous aria-label="หน้าก่อนหน้า">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span>ย้อนกลับ</span>
            </button>
            <span data-operational-days-page role="status" aria-live="polite"></span>
            <button type="button" data-operational-days-next aria-label="หน้าถัดไป">
                <span>ถัดไป</span>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
        </nav>
    @endif
</section>
