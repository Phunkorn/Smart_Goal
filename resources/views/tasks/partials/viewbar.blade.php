@php
    /**
     * แถบสลับมุมมองของ Task Workspace ใช้ร่วมกันระหว่างหน้า "งานของฉัน"
     * และ Admin Member Workspace เพื่อไม่ให้ต้องคัดลอกโครงสร้าง tablist ซ้ำ
     *
     * $views      รายการมุมมองที่หน้านั้น "มีจริง" — หน้าไหนไม่มี panel ก็ต้องไม่มีปุ่ม
     * $activeView มุมมองที่ server ตัดสินแล้ว เพื่อให้ render active state ได้ตั้งแต่ HTML แรก
     *
     * รายการที่มี key 'href' จะ render เป็น <a> เพราะต้อง navigate จริง
     * (panel ของมุมมองนั้นถูก render เฉพาะเมื่อ server เลือกมุมมองนั้น)
     */
    $activeView = $activeView ?? 'table';
    $views = $views ?? [
        ['view' => 'table', 'icon' => 'bi-table', 'label' => 'ตาราง'],
        ['view' => 'board', 'icon' => 'bi-layout-three-columns', 'label' => 'บอร์ด'],
        ['view' => 'calendar', 'icon' => 'bi-calendar3', 'label' => 'ปฏิทิน', 'controls' => 'mytasks-calendar'],
    ];
@endphp

<nav class="notion-viewbar" role="tablist" aria-label="รูปแบบการแสดงงาน">
    @foreach ($views as $item)
        @php($isActive = $activeView === $item['view'])

        @if (! empty($item['href']))
            <a class="{{ $isActive ? 'active' : '' }}"
                href="{{ $item['href'] }}"
                data-view="{{ $item['view'] }}"
                data-view-navigate
                role="tab"
                aria-selected="{{ $isActive ? 'true' : 'false' }}"
                @isset($item['controls']) aria-controls="{{ $item['controls'] }}" @endisset
            ><i class="bi {{ $item['icon'] }}" aria-hidden="true"></i> {{ $item['label'] }}</a>
        @else
            <button class="{{ $isActive ? 'active' : '' }}"
                type="button"
                data-view="{{ $item['view'] }}"
                role="tab"
                aria-selected="{{ $isActive ? 'true' : 'false' }}"
                @isset($item['controls']) aria-controls="{{ $item['controls'] }}" @endisset
            ><i class="bi {{ $item['icon'] }}" aria-hidden="true"></i> {{ $item['label'] }}</button>
        @endif
    @endforeach
</nav>
