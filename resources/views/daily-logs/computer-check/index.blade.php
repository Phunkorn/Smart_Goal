@extends('layouts.app')

@section('title', 'ตรวจเช็กคอมพิวเตอร์ประจำวัน')

@push('styles')
    @vite('resources/css/pages/computer-check.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/computer-check/index.js')
@endpush

@section('content')
{{--
    ตรวจเช็กคอมพิวเตอร์ประจำวัน — ผังห้อง Call Center แบบกดได้

    หน้านี้มาแทนแบบฟอร์มกระดาษ IT26-0-01 ทั้งใบ ตั้งแต่เดินตรวจตามตำแหน่งจริง
    จนได้แบบตรวจ A4 ที่เปิดย้อนหลังได้จากรายงานปฏิบัติงาน

    มือถือเป็นกรณีใช้งานหลัก เพราะคนตรวจถือโทรศัพท์เดินอยู่หน้างานจริง
    ผังบนมือถือจึงเป็นผังเดียวกับเดสก์ท็อป (ย่อ/ขยาย/เลื่อนได้) ไม่ใช่ลิสต์แทน

    หน้าเดียวใช้ได้ทั้งพนักงาน หัวหน้าแผนก และ admin ความต่างของสิทธิ์มาจาก
    $capabilities ที่คำนวณด้วย policy ฝั่ง server เท่านั้น
--}}
<div class="computer-check" data-computer-check
    data-session-id="{{ $session?->id ?? '' }}"
    data-session-status="{{ $session?->status ?? '' }}"
    data-can-edit="{{ $capabilities['canEdit'] ? '1' : '0' }}">

    {{-- ป้ายชื่อและค่าคงที่ทั้งหมดมาจาก ComputerCheckDesign ที่เดียว ฝั่ง JavaScript
         อ่านจาก island นี้แทนการเขียนข้อความไทยซ้ำในไฟล์ .js --}}
    <script type="application/json" id="computer-check-design">@json($design)</script>

    {{-- สถานะปัจจุบันของทุกเครื่อง รูปแบบเดียวกับที่ payload ของ AJAX ส่งกลับ --}}
    <script type="application/json" id="computer-check-stations">@json($stations)</script>

    {{-- URL ถูกสร้างจากฝั่งเซิร์ฟเวอร์ ฝั่ง client ไม่ประกอบเส้นทางเอง --}}
    <script type="application/json" id="computer-check-routes">@json($routes)</script>

    <nav class="cc-back">
        <a href="{{ route('daily-logs.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> กลับไปบันทึกงานประจำวัน
        </a>
    </nav>

    @include('daily-logs.computer-check.partials.session-header')

    {{-- แท็บเป็นปุ่มในหน้าเดียว ไม่ใช่การโหลดหน้าใหม่ ผู้ตรวจจึงสลับดูได้โดยไม่เสียตำแหน่งผัง --}}
    <div class="cc-tabs" role="tablist" aria-label="มุมมองการตรวจเช็ก">
        @foreach([
            'plan' => ['label' => 'ผังห้อง', 'icon' => 'bi-grid-3x3'],
            'list' => ['label' => 'รายการเครื่อง', 'icon' => 'bi-list-ul'],
            'summary' => ['label' => 'สรุปผลการตรวจ', 'icon' => 'bi-clipboard-data'],
            'history' => ['label' => 'ประวัติการตรวจ', 'icon' => 'bi-clock-history'],
        ] as $key => $tab)
            <button type="button" class="cc-tabs__tab" role="tab"
                id="ccTab-{{ $key }}"
                aria-controls="ccPanel-{{ $key }}"
                aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                data-cc-tab="{{ $key }}">
                <i class="bi {{ $tab['icon'] }}" aria-hidden="true"></i>
                <span>{{ $tab['label'] }}</span>
            </button>
        @endforeach
    </div>

    <div class="cc-body">
        <section class="cc-view" id="ccPanel-plan" role="tabpanel" aria-labelledby="ccTab-plan"
            data-cc-view="plan" @unless($activeTab === 'plan') hidden @endunless>

            <div class="cc-plan" data-cc-plan>
                <div class="cc-plan__toolbar">
                    {{-- โหมดเลือกหลายเครื่อง — ตั้งเป็นปกติพร้อมกันตอนเดินตรวจแล้วไม่เจอปัญหา --}}
                    <label class="cc-plan__multi">
                        <input type="checkbox" data-cc-multi-toggle @disabled(! $capabilities['canEdit'])>
                        <span><i class="bi bi-ui-checks" aria-hidden="true"></i> เลือกหลายเครื่อง</span>
                    </label>

                    {{-- ปุ่มย่อ/ขยายสำหรับมือถือ เดสก์ท็อปก็ใช้ได้ถ้าอยากซูมดูมุมห้อง --}}
                    <div class="cc-plan__zoom" role="group" aria-label="ย่อขยายผังห้อง">
                        <button type="button" data-cc-zoom="out" aria-label="ย่อผังห้อง"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                        <button type="button" data-cc-zoom="reset" aria-label="พอดีหน้าจอ"><i class="bi bi-arrows-angle-contract" aria-hidden="true"></i></button>
                        <button type="button" data-cc-zoom="in" aria-label="ขยายผังห้อง"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                    </div>
                </div>

                <div class="cc-plan__stage" data-cc-plan-stage>
                    <div class="cc-plan__canvas" data-cc-plan-canvas>
                        @include('daily-logs.computer-check.partials.floor-plan', [
                            'stations' => $stations,
                            'mode' => 'interactive',
                            'selectedIds' => [],
                        ])
                    </div>
                </div>

                {{-- คำอธิบายสี — สถานะถูกสื่อด้วยสี ไอคอน และข้อความพร้อมกันเสมอ --}}
                <ul class="cc-legend">
                    @foreach(\App\Support\ComputerCheckDesign::WORKSTATION_STATUSES as $key => $status)
                        <li class="cc-legend__item cc-legend__item--{{ $key }}">
                            <span class="cc-legend__swatch" aria-hidden="true"></span>
                            <i class="bi {{ $status['icon'] }}" aria-hidden="true"></i>
                            {{ $status['label'] }}
                        </li>
                    @endforeach
                </ul>
            </div>

            @include('daily-logs.computer-check.partials.checklist-panel', [
                'design' => $design,
                'canEdit' => $capabilities['canEdit'],
            ])
        </section>

        <section class="cc-view" id="ccPanel-list" role="tabpanel" aria-labelledby="ccTab-list"
            data-cc-view="list" @unless($activeTab === 'list') hidden @endunless>
            @include('daily-logs.computer-check.partials.workstation-list', ['stations' => $stations])
        </section>

        <section class="cc-view" id="ccPanel-summary" role="tabpanel" aria-labelledby="ccTab-summary"
            data-cc-view="summary" @unless($activeTab === 'summary') hidden @endunless>
            @include('daily-logs.computer-check.partials.summary')
        </section>

        <section class="cc-view" id="ccPanel-history" role="tabpanel" aria-labelledby="ccTab-history"
            data-cc-view="history" @unless($activeTab === 'history') hidden @endunless>
            @include('daily-logs.computer-check.partials.history', ['history' => $history])
        </section>
    </div>

    {{-- กล่องพรีวิวแบบตรวจ ชุดเดียวกับที่หน้ารายงานปฏิบัติงานและไทม์ไลน์ใช้ --}}
    @include('daily-logs.components.inspection-report-modal')
</div>
@endsection
