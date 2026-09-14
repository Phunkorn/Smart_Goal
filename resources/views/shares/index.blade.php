@extends('layouts.app')

@section('title', 'แชร์งาน')

@push('styles')
    @vite('resources/css/pages/shares.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/shares/index.js')
@endpush

@section('content')
{{--
    หน้าแชร์งาน

    สามแท็บตอบคนละคำถาม: มีงานอะไรให้เข้าร่วมบ้าง / ใครขอเข้าร่วมงานของฉัน /
    คำขอที่ฉันส่งไปถึงไหนแล้ว

    ข้อจำกัดสำคัญ: ผู้ที่ยังไม่เข้าร่วมเปิดงานในบอร์ดไม่ได้ (WorkOrderPolicy::view()
    ปฏิเสธ) การ์ดจึงแสดงได้เฉพาะข้อมูลที่ประกาศเปิดเผยเท่านั้น ห้ามลิงก์ไฟล์แนบหรือ
    คอมเมนต์ เพราะไฟล์แนบเป็น private ที่เสิร์ฟผ่าน MediaController/ProtectedMedia
    เท่านั้น ลิงก์เข้าหน้างานจริงจะโผล่หลังเข้าร่วมสำเร็จแล้ว
--}}
<div class="shares-page" data-shares-page>
    <header class="shares-header">
        <span class="shares-eyebrow">SHARED TASKS</span>
        <h1>แชร์งาน</h1>
        <p>งานที่เพื่อนร่วมงานเปิดรับผู้ร่วมงาน กดขอเข้าร่วมได้ แล้วรอผู้แชร์พิจารณา</p>
    </header>

    @include('shares.partials.flash')

    <nav class="shares-tabs" role="tablist" aria-label="มุมมองหน้าแชร์งาน">
        @php($tabs = [
            'feed' => ['งานที่ถูกแชร์', $feed->count()],
            'incoming' => ['คำขอที่ฉันได้รับ', $incomingRequests->count()],
            'mine' => ['คำขอของฉัน', $myRequests->count()],
        ])
        @foreach($tabs as $key => $tab)
            <button type="button" class="shares-tab {{ $activeTab === $key ? 'is-active' : '' }}"
                role="tab" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                aria-controls="shares-panel-{{ $key }}" data-shares-tab="{{ $key }}">
                {{ $tab[0] }}
                @if($tab[1] > 0)<span class="shares-tab__count">{{ $tab[1] }}</span>@endif
            </button>
        @endforeach
    </nav>

    <section id="shares-panel-feed" class="shares-panel" role="tabpanel"
        data-shares-panel="feed" @unless($activeTab === 'feed') hidden @endunless>
        @forelse($feed as $share)
            @include('shares.partials.share-card', ['share' => $share])
        @empty
            @include('shares.partials.empty', [
                'icon' => 'bi-share',
                'text' => 'ยังไม่มีงานที่ถูกแชร์ให้คุณเห็นในตอนนี้',
            ])
        @endforelse
    </section>

    <section id="shares-panel-incoming" class="shares-panel" role="tabpanel"
        data-shares-panel="incoming" @unless($activeTab === 'incoming') hidden @endunless>
        @forelse($incomingRequests as $shareRequest)
            @include('shares.partials.incoming-request', ['shareRequest' => $shareRequest])
        @empty
            @include('shares.partials.empty', [
                'icon' => 'bi-inbox',
                'text' => 'ยังไม่มีใครขอเข้าร่วมงานที่คุณแชร์',
            ])
        @endforelse

        @if($myShares->isNotEmpty())
            <h2 class="shares-subheading">ประกาศของฉัน</h2>
            <div class="shares-my-list">
                {{--
                    เฉพาะประกาศที่ยังเปิดอยู่ — ปิดแล้วคือหายไปเลย ไม่ใช่ค้างไว้เป็นรายการสีจาง
                    พร้อมป้าย "ปิดแล้ว" ซึ่งกลายเป็นประวัติว่าเคยเปิดแชร์งานใบไหนไว้บ้าง
                    (ตัวกรองอยู่ที่ WorkOrderShareQuery::mySharesFor())
                --}}
                @foreach($myShares as $share)
                    <article class="shares-mine">
                        <div class="shares-mine__text">
                            <strong>{{ $share->workOrder?->job_topic ?? 'งานถูกลบแล้ว' }}</strong>
                            <small>
                                {{ $share->workOrder?->taskList?->name ?? 'ไม่มีโปรเจกต์' }}
                                · {{ $share->scope === 'department' ? 'เฉพาะแผนก '.($share->department?->department_name ?? 'ของฉัน') : 'ทั้งองค์กร' }}
                            </small>
                        </div>
                        <form method="POST" action="{{ route('shares.destroy', $share) }}" data-share-close>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="shares-btn shares-btn--ghost">ปิดประกาศ</button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section id="shares-panel-mine" class="shares-panel" role="tabpanel"
        data-shares-panel="mine" @unless($activeTab === 'mine') hidden @endunless>
        @forelse($myRequests as $shareRequest)
            @include('shares.partials.my-request', ['shareRequest' => $shareRequest])
        @empty
            @include('shares.partials.empty', [
                'icon' => 'bi-send',
                'text' => 'คุณยังไม่ได้ส่งคำขอเข้าร่วมงานใด',
            ])
        @endforelse
    </section>
</div>

{{-- โมดัลรายละเอียดงาน — อ่านอย่างเดียว ข้อมูลมาจาก data-* ของการ์ดที่ถูกกด --}}
<div class="shares-modal" data-share-detail-modal hidden>
    <div class="shares-modal__backdrop" data-share-detail-close></div>
    <div class="shares-modal__panel" role="dialog" aria-modal="true" aria-labelledby="share-detail-title">
        <header>
            <h2 id="share-detail-title" data-share-detail-topic></h2>
            <button type="button" class="shares-modal__close" data-share-detail-close aria-label="ปิด">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <dl class="shares-modal__facts">
            <div><dt>โปรเจกต์</dt><dd data-share-detail-project></dd></div>
            <div><dt>ผู้แชร์</dt><dd data-share-detail-sharer></dd></div>
            <div><dt>ผู้รับผิดชอบ</dt><dd data-share-detail-owner></dd></div>
            <div><dt>กำหนดส่ง</dt><dd data-share-detail-due></dd></div>
            <div><dt>อยู่ใต้งาน</dt><dd data-share-detail-parent></dd></div>
        </dl>
        <p class="shares-modal__details" data-share-detail-body></p>
        <p class="shares-modal__hint">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            เห็นได้เท่าที่ผู้แชร์เปิดเผย ไฟล์แนบและคอมเมนต์จะเปิดได้หลังเข้าร่วมงานแล้ว
        </p>
    </div>
</div>
@endsection
