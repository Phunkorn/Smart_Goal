{{--
    ผังห้อง Call Center — ที่เดียวในระบบที่วาดตำแหน่งโต๊ะ

    ใช้ร่วมกันสามที่: หน้าตรวจบนเดสก์ท็อป, หน้าตรวจบนมือถือ (ผังเดียวกัน ย่อ/ขยายได้)
    และแบบตรวจ A4 ที่พิมพ์ลงกระดาษ ถ้าผังสองที่ไม่ตรงกันเมื่อไหร่แปลว่ามีคนทำ
    ผังชุดที่สองขึ้นมา ซึ่งเป็นสิ่งที่ไฟล์นี้มีไว้เพื่อป้องกัน

    พิกัดทั้งหมดมาจากตาราง computer_check_workstations ผ่าน
    ComputerCheckReportService::stations() ไม่มีตัวเลขตำแหน่งเขียนตายไว้ในไฟล์นี้
    ยกเว้นกรอบห้อง โซน และสิ่งปลูกสร้าง ซึ่งเป็นรูปร่างของตัวอาคารใน ComputerCheckDesign

    ใช้ SVG + viewBox แทน Bootstrap grid เพราะ grid จะจัดโต๊ะใหม่เมื่อจอแคบลง
    ทำให้รูปทรงห้องเปลี่ยนไปจากของจริง ส่วน viewBox ย่อขยายทั้งผังพร้อมกัน
    สัดส่วนจึงคงที่ทุกขนาดจอ

    @param array $stations  ผลจาก ComputerCheckReportService::stations()
    @param string $mode     'interactive' = กดเลือกได้ | 'print' = ช่องอุปกรณ์ 5 ช่องบนโต๊ะ
    @param array $selectedIds  (interactive) id ของเครื่องที่กำลังถูกเลือกอยู่
--}}
@php
    use App\Support\ComputerCheckDesign;

    $mode = $mode ?? 'interactive';
    $selectedIds = array_map('intval', $selectedIds ?? []);
    $isPrint = $mode === 'print';
    $devices = ComputerCheckDesign::DEVICES;
    $deviceCount = count($devices);
@endphp

<svg class="cc-plan__svg cc-plan__svg--{{ $mode }}"
    viewBox="0 0 {{ ComputerCheckDesign::ROOM_WIDTH }} {{ ComputerCheckDesign::ROOM_HEIGHT }}"
    role="group"
    aria-label="ผังห้อง {{ ComputerCheckDesign::ROOM_NAME }} จำนวน {{ count($stations) }} เครื่อง"
    preserveAspectRatio="xMidYMid meet"
    @unless($isPrint) data-cc-plan-svg @endunless>

    <rect class="cc-plan__floor" x="0" y="0"
        width="{{ ComputerCheckDesign::ROOM_WIDTH }}" height="{{ ComputerCheckDesign::ROOM_HEIGHT }}" rx="14" />

    {{-- ป้ายโซน: B ซ้าย A ขวา ตามกระดาษจริง ไม่เรียงตามตัวอักษร --}}
    @foreach(ComputerCheckDesign::ZONE_BANDS as $band)
        <g class="cc-plan__zone">
            <rect x="{{ $band['x'] }}" y="{{ $band['y'] }}"
                width="{{ $band['width'] }}" height="{{ $band['height'] }}" rx="10" />
            <text x="{{ $band['x'] + $band['width'] / 2 }}" y="{{ $band['y'] + $band['height'] / 2 }}"
                text-anchor="middle" dominant-baseline="central">Zone {{ $band['zone'] }}</text>
        </g>
    @endforeach

    {{-- สิ่งที่ไม่ใช่โต๊ะคอมพิวเตอร์ แต่ช่วยให้ผู้ตรวจรู้ว่ายืนอยู่ตรงไหนของห้อง --}}
    @foreach(ComputerCheckDesign::FIXTURES as $fixture)
        <g class="cc-plan__fixture cc-plan__fixture--{{ $fixture['kind'] }}">
            <rect x="{{ $fixture['x'] }}" y="{{ $fixture['y'] }}"
                width="{{ $fixture['width'] }}" height="{{ $fixture['height'] }}" rx="6" />
            @if($fixture['kind'] === 'stairs')
                {{-- ขั้นบันได วาดเป็นเส้นขวางเพื่อให้อ่านออกว่าเป็นบันได ไม่ใช่กล่องเปล่า --}}
                @for($step = 1; $step <= 6; $step++)
                    @php $stepY = $fixture['y'] + ($fixture['height'] / 7) * $step; @endphp
                    <line x1="{{ $fixture['x'] + 14 }}" y1="{{ $stepY }}"
                        x2="{{ $fixture['x'] + $fixture['width'] - 14 }}" y2="{{ $stepY }}" />
                @endfor
            @endif
            <text x="{{ $fixture['x'] + $fixture['width'] / 2 }}" y="{{ $fixture['y'] + $fixture['height'] - 14 }}"
                text-anchor="middle">{{ $fixture['label'] }}</text>
        </g>
    @endforeach

    @foreach($stations as $station)
        @php
            $x = $station['x'];
            $y = $station['y'];
            $w = $station['width'];
            $h = $station['height'];
            // โต๊ะถูกวาดเป็นจอ + พื้นโต๊ะ + เก้าอี้ เพื่อให้ดูออกว่าเป็นที่นั่งทำงาน
            // สัดส่วนอิงความสูงของโต๊ะ ไม่ใช่ค่าคงที่ เผื่อผังมีโต๊ะขนาดต่างกัน
            $monitorW = min(58, $w * 0.34);
            $monitorH = $h * 0.21;
            $deskY = $y + $monitorH + 3;
            $deskH = $h * 0.52;
            $chairW = min(44, $w * 0.26);
            $chairH = $h * 0.17;
            $chairY = $deskY + $deskH + 3;
            $statusDesign = ComputerCheckDesign::workstationStatus($station['status']);
            $isSelected = in_array((int) $station['id'], $selectedIds, true);
        @endphp

        <g class="cc-station cc-station--{{ $station['status'] }}@if($isSelected) is-selected @endif"
            data-cc-station
            data-station-id="{{ $station['id'] }}"
            data-station-code="{{ $station['code'] }}"
            data-status="{{ $station['status'] }}"
            @unless($isPrint)
                role="button"
                tabindex="0"
                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
            @endunless
            aria-label="เครื่อง {{ $station['code'] }} โซน {{ $station['zone'] }} — {{ $statusDesign['label'] }}">
            <title>{{ $station['code'] }} — {{ $statusDesign['label'] }}@if($station['note']) ({{ $station['note'] }})@endif</title>

            {{-- จอคอมพิวเตอร์ --}}
            <rect class="cc-station__monitor"
                x="{{ round($x + ($w - $monitorW) / 2, 2) }}" y="{{ $y }}"
                width="{{ round($monitorW, 2) }}" height="{{ round($monitorH, 2) }}" rx="3" />

            {{-- พื้นโต๊ะ — ตัวที่เปลี่ยนสีตามสถานะ --}}
            <rect class="cc-station__desk"
                x="{{ $x }}" y="{{ round($deskY, 2) }}"
                width="{{ $w }}" height="{{ round($deskH, 2) }}" rx="7" />

            {{-- เก้าอี้ --}}
            <rect class="cc-station__chair"
                x="{{ round($x + ($w - $chairW) / 2, 2) }}" y="{{ round($chairY, 2) }}"
                width="{{ round($chairW, 2) }}" height="{{ round($chairH, 2) }}" rx="6" />

            @if($isPrint)
                {{--
                    แบบตรวจ A4: รหัสเครื่องกับช่องอุปกรณ์ห้าช่องเรียงบนโต๊ะ
                    เหมือนแบบฟอร์มกระดาษ แต่ละช่องมีตัวอักษรย่อกับสัญลักษณ์ผล
                    จึงอ่านออกแม้พิมพ์ขาวดำ (ดู legend ด้านบนของแบบตรวจ)
                --}}
                <text class="cc-station__code cc-station__code--print"
                    x="{{ $x + 4 }}" y="{{ round($deskY + $deskH * 0.4, 2) }}">{{ $station['code'] }}</text>

                @php
                    $boxGap = 2;
                    $boxW = ($w - 8 - $boxGap * ($deviceCount - 1)) / $deviceCount;
                    $boxH = min(17, $deskH * 0.44);
                    $boxY = $deskY + $deskH - $boxH - 3;
                @endphp
                @foreach($devices as $deviceKey => $device)
                    @php
                        $deviceStatus = ComputerCheckDesign::deviceStatus($station['devices'][$deviceKey] ?? 'pending');
                        $boxX = $x + 4 + ($boxW + $boxGap) * $loop->index;
                    @endphp
                    <g class="cc-chip cc-chip--{{ $station['devices'][$deviceKey] ?? 'pending' }}">
                        <rect x="{{ round($boxX, 2) }}" y="{{ round($boxY, 2) }}"
                            width="{{ round($boxW, 2) }}" height="{{ round($boxH, 2) }}" rx="2" />
                        <text x="{{ round($boxX + $boxW / 2, 2) }}" y="{{ round($boxY + $boxH / 2, 2) }}"
                            text-anchor="middle" dominant-baseline="central">{{ $device['letter'] }}{{ $deviceStatus['mark'] }}</text>
                    </g>
                @endforeach
            @else
                {{--
                    หน้าจอ: รหัสเครื่องกับสถานะเป็นข้อความ
                    สถานะถูกสื่อด้วยสี + สัญลักษณ์ + ข้อความพร้อมกันเสมอ
                    ห้ามเหลือแค่สี เพราะคนตาบอดสีและกระดาษขาวดำจะอ่านไม่ออก
                --}}
                <text class="cc-station__code"
                    x="{{ $x + 10 }}" y="{{ round($deskY + $deskH * 0.45, 2) }}"
                    dominant-baseline="central">{{ $station['code'] }}</text>
                <text class="cc-station__state"
                    x="{{ $x + $w - 10 }}" y="{{ round($deskY + $deskH * 0.45, 2) }}"
                    text-anchor="end" dominant-baseline="central">{{ $statusDesign['mark'] }} {{ $statusDesign['label'] }}</text>
            @endif
        </g>
    @endforeach
</svg>
