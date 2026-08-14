<div class="wb-summary" aria-label="สรุปสถานะงาน">
    @foreach($statusMeta as $key => $meta)
        <div class="wb-summary__item wb-tone-{{ $meta['tone'] }}">
            <span class="wb-summary__dot" aria-hidden="true"></span>
            <div>
                <span class="wb-summary__label">{{ $meta['label'] }}</span>
                <strong>{{ number_format($statusCounts[$key] ?? 0) }}</strong>
                <small>งาน</small>
            </div>
        </div>
    @endforeach
</div>
