{{--
    กระดานของหัวหน้าแผนก — "วันนี้ใครทำอะไรไปแล้วบ้าง"

    เป็นการ์ดต่อคน ไม่ใช่ตาราง

    ของเดิมเป็นตารางหลายคอลัมน์ แต่ข้อมูลของคนหนึ่งคนไม่ได้เป็นแถวเดียวจริง ๆ
    มันคือชื่อ ความคืบหน้าของวันนี้ รายการงานอีกหลายบรรทัด และตัวเลขสะสมของช่วง
    การยัดทั้งหมดลงในแถวเดียวทำให้ช่องหนึ่งสูงกว่าช่องอื่นมาก คอลัมน์ตัวเลขจึงลอย
    อยู่กลางอากาศ และงานของแต่ละคนก็ถูกบีบจนอ่านเป็นก้อนเดียว

    การ์ดแก้ที่ต้นเหตุ: หนึ่งคน = หนึ่งกล่องที่มีลำดับการอ่านของตัวเอง — ใครก่อน
    แล้ววันนี้ไปถึงไหน แล้วงานอะไรบ้าง แล้วที่ผ่านมาเป็นยังไง และกล่องเรียงเป็นกริด
    จอกว้างจึงได้สามคนต่อแถวโดยไม่ต้องกวาดสายตาข้ามทั้งจอ

    การ์ดต้องเตี้ยพอให้เทียบกันได้ จึงแสดงงานของวันนี้แค่ไม่กี่บรรทัด ส่วนรายละเอียด
    เต็ม (งานทั้งหมดของวันนี้ และเวลาทั้งช่วงที่หมดไปกับงานไหนบ้าง) อยู่ใน modal
    ที่กดจากการ์ดใบนั้น — ของเดิมกางอยู่ในการ์ดเอง ซึ่งดันการ์ดใบอื่นในแถวเดียวกัน
    ให้ขยับตามทุกครั้งที่กด

    คนที่ยังไม่ได้เริ่มงานของวันนี้ถูกทำเครื่องหมายไว้ที่ตัวการ์ด เพราะเป็นคนที่หัวหน้า
    ต้องการเห็นก่อนใคร
--}}
@php
    // จำนวนบรรทัดที่เห็นได้ทันทีบนการ์ด มากกว่านี้การ์ดจะสูงจนเทียบกันไม่ได้
    $visibleItems = 4;
@endphp
<section class="report-panel report-operational-people" aria-labelledby="operational-people-title">
    <div class="report-panel__heading">
        <div>
            <h2 id="operational-people-title">วันนี้ใครทำอะไรไปแล้วบ้าง</h2>
            <p>งานของวันนี้ทั้งงานประจำและงานนอกสถานที่ · ตัวเลขสะสมเป็นของช่วง {{ $filters['period_label'] }} — กดการ์ดเพื่อดูรายละเอียด</p>
        </div>
        <span class="report-panel__count">{{ $routineCompliance->count() }} คน</span>
    </div>

    @if($routineCompliance->isEmpty())
        <p class="report-empty">ยังไม่มีพนักงานในขอบเขตนี้</p>
    @else
        {{-- ตัวแบ่งหน้าตัวเดียวกับตารางอื่นทำงานกับกริดนี้ได้ เพราะมันซ่อนแถวด้วย hidden ล้วน ๆ --}}
        <div class="report-people-cards" data-operational-people-table data-page-size="10">
            @foreach($routineCompliance as $person)
                @php
                    $items = $person['today_items'];
                    $shownItems = array_slice($items, 0, $visibleItems);
                    $todayTotal = $person['today_total'];
                    $todayDone = $person['today_done'];
                    // ยังไม่ได้เริ่มเลยทั้งที่มีงานรออยู่ คือสัญญาณที่ต้องเห็นก่อนตัวเลขอื่น
                    $notStarted = $todayTotal > 0 && $todayDone === 0;
                    $allDone = $todayTotal > 0 && $todayDone === $todayTotal;
                    $progress = $todayTotal > 0 ? (int) round(($todayDone / $todayTotal) * 100) : 0;
                    $tone = $allDone ? 'green' : ($todayDone > 0 ? 'amber' : 'red');
                    // คงตัวกรองช่วงวันไว้ การกดดูรายวันจึงไม่รีเซ็ตช่วงที่เพิ่งเลือก
                    $personUrl = route('reports.operational', array_merge(request()->except(['owner', 'page']), ['owner' => $person['id']]));
                @endphp
                <article class="report-people-card @if($notStarted) is-idle @endif" data-operational-people-row
                    data-people-card
                    data-people-name="{{ $person['name'] }}"
                    data-people-department="{{ $person['department'] }}"
                    data-people-url="{{ $personUrl }}">
                    <header class="report-people-card__head">
                        {{-- ตัวอักษรแรกของชื่อ ช่วยให้จำการ์ดได้เร็วกว่าอ่านชื่อเต็มทุกครั้ง --}}
                        <span class="report-people-card__avatar" aria-hidden="true">{{ mb_substr($person['name'], 0, 1) }}</span>
                        <div class="report-people-card__identity">
                            <a href="{{ $personUrl }}">{{ $person['name'] }}</a>
                            <small>{{ $person['department'] }}</small>
                        </div>
                        <span class="report-tag report-tone-{{ $person['rate'] >= 90 ? 'green' : ($person['rate'] >= 70 ? 'amber' : 'red') }}"
                            title="อัตราการปิดรายการในช่วง {{ $filters['period_label'] }}">
                            {{ $person['rate'] }}%
                        </span>
                    </header>

                    <div class="report-people-card__today">
                        <div class="report-people-card__today-line">
                            <span class="report-people-card__today-label">วันนี้</span>
                            @if($todayTotal === 0)
                                <span class="report-people-card__today-none">ยังไม่มีงานของวันนี้</span>
                            @else
                                <strong class="report-people-card__today-count is-{{ $tone }}">{{ $todayDone }}/{{ $todayTotal }}</strong>
                                <span class="report-people-card__today-time">
                                    <i class="bi bi-clock" aria-hidden="true"></i>{{ $person['today_minutes_label'] }}
                                </span>
                            @endif
                        </div>

                        @if($todayTotal > 0)
                            {{-- แถบความคืบหน้าอ่านได้ก่อนตัวเลข จึงเทียบคนทั้งกริดได้ด้วยการกวาดสายตา --}}
                            <div class="report-people-card__bar" role="img"
                                aria-label="ทำแล้ว {{ $todayDone }} จาก {{ $todayTotal }} รายการ">
                                <span class="is-{{ $tone }}" style="width: {{ $progress }}%"></span>
                            </div>
                        @endif
                    </div>

                    @if(empty($items))
                        <p class="report-people-card__empty">ยังไม่ได้บันทึกงานของวันนี้</p>
                    @else
                        <ul class="report-people-card__items">
                            @foreach($shownItems as $item)
                                @include('reports.components.operational-people-item', ['item' => $item])
                            @endforeach
                        </ul>
                    @endif

                    <footer class="report-people-card__period">
                        <span>{{ $filters['period_label'] }}</span>
                        <strong>{{ number_format($person['period_done']) }}/{{ number_format($person['period_total']) }} รายการ</strong>
                        <span class="report-people-card__period-time">
                            <i class="bi bi-clock-history" aria-hidden="true"></i>{{ $person['period_minutes_label'] }}
                        </span>
                    </footer>

                    {{--
                        ปุ่มเป็นตัวเปิดจริง ไม่ใช่ทั้งการ์ดเป็นปุ่ม เพราะในการ์ดมีลิงก์ชื่อคนอยู่แล้ว
                        การซ้อนตัวกดสองชั้นทำให้คีย์บอร์ดและ screen reader อ่านว่ามีเป้าหมายซ้อนกัน
                        (การคลิกที่พื้นที่ว่างของการ์ดก็เปิดได้เช่นกัน ดู people-modal.js)
                    --}}
                    <button type="button" class="report-people-card__open" data-people-open>
                        <i class="bi bi-list-ul" aria-hidden="true"></i>
                        ดูรายละเอียด@if(count($items) > $visibleItems) · อีก {{ count($items) - $visibleItems }} รายการ @endif
                    </button>

                    {{--
                        เนื้อในของ modal ถูก render ไว้กับการ์ดตั้งแต่ฝั่ง server แล้ว JavaScript
                        แค่ย้ายไปวางในกล่อง ตัวเลขและสีจึงมาจาก Blade ที่เดียวกับการ์ด
                        ไม่ต้องมีโค้ดสร้าง DOM ชุดที่สองที่ต้องคอยแก้ให้ตรงกันทีหลัง
                    --}}
                    <template data-people-detail>
                        <section class="report-people-detail">
                            <h3>งานของวันนี้</h3>
                            @if(empty($items))
                                <p class="report-people-detail__empty">ยังไม่ได้บันทึกงานของวันนี้</p>
                            @else
                                <p class="report-people-detail__summary">
                                    ทำแล้ว {{ $todayDone }} จาก {{ $todayTotal }} รายการ ·
                                    ใช้เวลา {{ $person['today_minutes_label'] }}
                                </p>
                                <ul class="report-people-card__items">
                                    @foreach($items as $item)
                                        @include('reports.components.operational-people-item', ['item' => $item])
                                    @endforeach
                                </ul>
                            @endif
                        </section>

                        <section class="report-people-detail">
                            <h3>เวลาที่ใช้ใน{{ $filters['period_label'] }}</h3>
                            @if(empty($person['time_breakdown']))
                                <p class="report-people-detail__empty">ยังไม่มีบันทึกในช่วงนี้</p>
                            @else
                                <p class="report-people-detail__summary">
                                    ปิดไปแล้ว {{ number_format($person['period_done']) }} จาก {{ number_format($person['period_total']) }} รายการ ·
                                    รวม {{ $person['period_minutes_label'] }} ({{ $person['rate'] }}%)
                                </p>
                                <ul class="report-people-card__breakdown">
                                    @foreach($person['time_breakdown'] as $entry)
                                        <li>
                                            <span class="report-people-card__breakdown-title">{{ $entry['title'] }}</span>
                                            <span class="report-people-card__breakdown-times">{{ number_format($entry['times']) }} ครั้ง</span>
                                            <span class="report-people-card__breakdown-time">{{ $entry['minutes_label'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    </template>
                </article>
            @endforeach
        </div>

        <nav class="report-table-pager" data-operational-people-pager aria-label="เปลี่ยนหน้ารายชื่อพนักงาน" hidden>
            <button type="button" data-operational-people-previous aria-label="หน้าก่อนหน้า">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span>ย้อนกลับ</span>
            </button>
            <span data-operational-people-page role="status" aria-live="polite"></span>
            <button type="button" data-operational-people-next aria-label="หน้าถัดไป">
                <span>ถัดไป</span>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
        </nav>

        @include('reports.components.operational-people-modal')
    @endif
</section>
