{{--
    แถบตัวเลขสรุปที่ใช้ร่วมกันทั้งสามหน้ารายงาน

    ตัวเลขลอย ๆ ตีความได้หลายแบบ ทุกใบจึงต้องมี note บอกว่านับอะไรและนับถึงเมื่อไร
    ค่าที่เป็นสัญญาณเตือนสื่อด้วยไอคอนและข้อความควบคู่กับสีเสมอ ไม่ใช้สีอย่างเดียว

    @param array $cards  แต่ละใบมี label, value, note และ tone/icon/alert ที่ไม่บังคับ
                         alert=false ทำให้การ์ดโทนเตือนกลับไปเป็นโทนปกติเมื่อค่ายังไม่ถึงเกณฑ์
    @param string $ariaLabel  ป้ายกำกับของแถบสำหรับ screen reader
--}}
@php
    $ariaLabel = $ariaLabel ?? 'สรุปตัวเลขสำคัญ';
@endphp

<section class="report-kpi-band" aria-label="{{ $ariaLabel }}">
    @foreach ($cards as $card)
        @php
            $tone = $card['tone'] ?? 'neutral';
            $isAlert = $tone !== 'neutral' && ($card['alert'] ?? true);
        @endphp
        @php
            // การ์ดที่มี href กลายเป็นทางเข้าไปกรองข้อมูลทั้งหน้า
            // ตัวเลขจึงเชื่อมกับกราฟด้านล่าง ไม่ใช่เลขลอยที่อ่านแล้วทำอะไรต่อไม่ได้
            $tag = empty($card['href']) ? 'article' : 'a';
        @endphp
        <{{ $tag }}
            class="report-kpi report-kpi--{{ $isAlert ? $tone : 'neutral' }} {{ empty($card['href']) ? '' : 'report-kpi--linked' }} {{ ($card['current'] ?? false) ? 'is-current' : '' }}"
            @if (!empty($card['href'])) href="{{ $card['href'] }}" @endif
            @if ($card['current'] ?? false) aria-current="true" @endif
        >
            <span class="report-kpi__label">
                @if (!empty($card['icon']))
                    <i class="bi {{ $card['icon'] }}" aria-hidden="true"></i>
                @endif
                {{ $card['label'] }}
                @if (!empty($card['href']))
                    <i class="bi bi-funnel report-kpi__filter-hint" aria-hidden="true" title="กดเพื่อกรองทั้งหน้า"></i>
                @endif
            </span>
            <strong class="report-kpi__value">{{ $card['value'] }}{{ $card['unit'] ?? '' }}</strong>
            <small class="report-kpi__note">
                @if ($isAlert)
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                @endif
                {{ $card['note'] }}
            </small>
        </{{ $tag }}>
    @endforeach
</section>
