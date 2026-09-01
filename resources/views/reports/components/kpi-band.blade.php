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
        <article class="report-kpi report-kpi--{{ $isAlert ? $tone : 'neutral' }}">
            <span class="report-kpi__label">
                @if (!empty($card['icon']))
                    <i class="bi {{ $card['icon'] }}" aria-hidden="true"></i>
                @endif
                {{ $card['label'] }}
            </span>
            <strong class="report-kpi__value">{{ $card['value'] }}{{ $card['unit'] ?? '' }}</strong>
            <small class="report-kpi__note">
                @if ($isAlert)
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                @endif
                {{ $card['note'] }}
            </small>
        </article>
    @endforeach
</section>
