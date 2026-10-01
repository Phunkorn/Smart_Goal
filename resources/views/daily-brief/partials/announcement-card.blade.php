{{--
    ประกาศหนึ่งใบในสรุปประจำวัน — ผู้ประกาศ (รูป ชื่อ ตำแหน่ง · แผนก) และเนื้อหา

    เนื้อหาเป็นข้อความธรรมดา แสดงด้วย nl2br(e()) เพื่อคงการขึ้นบรรทัดโดยไม่เปิดช่อง XSS
--}}
<article class="daily-brief__announcement daily-brief__announcement--{{ $announcement['audience'] }}">
    <header class="daily-brief__author">
        @if($announcement['author'])
            @include('work-board.partials.avatar', ['user' => $announcement['author'], 'size' => 'lg'])
        @endif
        <div class="daily-brief__author-text">
            <strong>{{ $announcement['author']?->name ?? 'ไม่ทราบผู้ประกาศ' }}</strong>
            <small>{{ $announcement['author_role'] }}</small>
            <time>{{ $announcement['posted_at'] }}</time>
        </div>
        <span class="badge-soft {{ $announcement['audience_meta']['tone'] }} daily-brief__audience">
            {{ $announcement['audience_meta']['label'] }}
        </span>
    </header>
    <h4 class="daily-brief__announcement-title">
        {{ $announcement['title'] }}
        @if($announcement['is_new'])<span class="daily-brief__new">ใหม่</span>@endif
    </h4>
    <p class="daily-brief__announcement-body">{!! nl2br(e($announcement['body'])) !!}</p>
</article>
