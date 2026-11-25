<x-account-modal id="settingsFrameGalleryModal" title="เลือกกรอบโปรไฟล์" size="lg" data-frame-gallery>
    <div class="modal-body sg-frame-gallery">
        <div class="sg-frame-gallery__controls">
            <div class="sg-frame-gallery__hero" aria-live="polite">
                <span class="sg-frame-gallery__hint">ตัวอย่างกรอบที่เลือก</span>
                @include('settings.components.frame-avatar', ['frame' => $savedFrame])
                <strong data-frame-gallery-name>{{ $savedFrame?->name ?? 'ไม่ใช้กรอบ' }}</strong>
                <small data-frame-gallery-kind></small>
            </div>
            <div class="sg-frame-gallery__tools">
                <div class="sg-frame-gallery__tabs" role="tablist" aria-label="ประเภทกรอบโปรไฟล์">
                    @foreach(['all' => ['ทั้งหมด', $profileFrames->count()], 'static' => ['กรอบปกติ', $profileFrames->where('animated', false)->count()], 'animated' => ['เคลื่อนไหว', $profileFrames->where('animated', true)->count()]] as $key => [$label, $count])
                        <button type="button" role="tab" id="profileFrameTab-{{ $key }}" aria-controls="profileFrameResults"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? 0 : -1 }}" data-frame-category="{{ $key }}">
                            {{ $label }} <span>{{ $count }}</span>
                        </button>
                    @endforeach
                </div>
                <label class="visually-hidden" for="settingsFrameSearch">ค้นหาชื่อกรอบ</label>
                <input type="search" id="settingsFrameSearch" class="form-control" placeholder="ค้นหาชื่อกรอบ" data-frame-search data-account-initial-focus>
            </div>
        </div>
        <div id="profileFrameResults" role="tabpanel" aria-labelledby="profileFrameTab-all" tabindex="0">
            <div class="sg-frame-gallery__grid">
                @foreach(collect([null])->concat($profileFrames->values())->concat($savedFrame && ! $savedFrame->is_active ? [$savedFrame] : []) as $frame)
                    @php
                        $kind = $frame ? ($frame->animated ? 'animated' : 'static') : 'none';
                        $kindLabel = $frame ? ($frame->animated ? 'เคลื่อนไหว' : 'ปกติ') : '';
                    @endphp
                    <button type="button" class="sg-frame-gallery__card" aria-pressed="false" data-frame-option
                        data-frame-slug="{{ $frame?->slug ?? 'none' }}" data-frame-name="{{ $frame?->name ?? 'ไม่ใช้กรอบ' }}"
                        data-frame-kind="{{ $kind }}" data-frame-kind-label="{{ $kindLabel }}" @if($frame && ! $frame->is_active) data-frame-inactive hidden @endif>
                        <span class="sg-frame-gallery__marker" aria-hidden="true">
                            <i class="bi bi-check-circle-fill sg-frame-gallery__check"></i>
                            @if($frame?->animated)<i class="bi bi-stars sg-frame-gallery__motion"></i>@endif
                        </span>
                        @include('settings.components.frame-avatar', ['frame' => $frame])
                        <strong>{{ $frame?->name ?? 'ไม่ใช้กรอบ' }}</strong>
                        @if($kindLabel)<small>{{ $kindLabel }}</small>@endif
                    </button>
                @endforeach
            </div>
            <p class="sg-frame-gallery__empty" role="status" data-frame-empty hidden>ไม่พบกรอบที่ตรงกับคำค้นหา</p>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-primary settings-primary-button" data-frame-confirm data-bs-dismiss="modal">เลือกกรอบนี้</button>
    </div>
</x-account-modal>
