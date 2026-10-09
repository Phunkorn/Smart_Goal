{{--
    รายการเครื่องแบบลิสต์ — ทางเลือกของผังห้อง ไม่ใช่ตัวแทนบนมือถือ

    มือถือยังได้ผังห้องเต็มรูปแบบเหมือนเดสก์ท็อป (ย่อ/ขยายได้) แท็บนี้มีไว้สำหรับ
    คนที่อยากไล่ตรวจตามรหัสเครื่องหรืออยากเห็นหมายเหตุทุกเครื่องพร้อมกัน
    การกดแถวเปิดแผงตรวจชุดเดียวกับที่ผังห้องเปิด ไม่ใช่ฟอร์มคนละใบ

    @param array $stations
--}}
@php
    use App\Support\ComputerCheckDesign;
@endphp

<div class="cc-list" data-cc-list>
    @forelse($stations as $station)
        @php $statusDesign = ComputerCheckDesign::workstationStatus($station['status']); @endphp
        <button type="button" class="cc-list__row cc-list__row--{{ $station['status'] }}"
            data-cc-station-row data-station-id="{{ $station['id'] }}">
            <span class="cc-list__code">
                <i class="bi bi-pc-display" aria-hidden="true"></i>
                {{ $station['code'] }}
            </span>
            <span class="cc-list__zone">Zone {{ $station['zone'] }}</span>

            <span class="cc-list__devices">
                @foreach(ComputerCheckDesign::DEVICES as $deviceKey => $device)
                    @php $deviceStatus = $station['devices'][$deviceKey] ?? 'pending'; @endphp
                    <span class="cc-chip-inline cc-chip-inline--{{ $deviceStatus }}"
                        title="{{ $device['label'] }} — {{ ComputerCheckDesign::deviceStatus($deviceStatus)['label'] }}">
                        <span aria-hidden="true">{{ $device['letter'] }}</span>
                        <span class="visually-hidden">{{ $device['label'] }} {{ ComputerCheckDesign::deviceStatus($deviceStatus)['label'] }}</span>
                    </span>
                @endforeach
            </span>

            <span class="cc-list__status cc-list__status--{{ $station['status'] }}">
                <i class="bi {{ $statusDesign['icon'] }}" aria-hidden="true"></i>
                {{ $statusDesign['label'] }}
            </span>

            @if($station['note'])
                <span class="cc-list__note">{{ $station['note'] }}</span>
            @endif

            <i class="bi bi-chevron-right cc-list__chevron" aria-hidden="true"></i>
        </button>
    @empty
        <p class="cc-empty">ยังไม่มีเครื่องในผังห้องนี้</p>
    @endforelse
</div>
