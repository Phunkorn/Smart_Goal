@extends('layouts.app')

@section('title', 'ชุมชน')

@push('styles')
    @vite('resources/css/pages/social.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/social/index.js')
@endpush

@section('content')
{{--
    ชุมชน (Social Community) — ฟีดส่วนกลางของพนักงานทุกแผนก

    การ์ดโพสต์และความคิดเห็นเรนเดอร์จาก partial ของ server เท่านั้น (social.partials.*)
    ทั้งตอนเปิดหน้าและตอนที่ JavaScript ขอเพิ่ม/แทนที่ ปุ่มที่แสดงตามสิทธิ์มาจาก @can ของ
    SocialPostPolicy / SocialCommentPolicy ส่วน server ตรวจสิทธิ์ซ้ำทุกคำขอ
--}}
<div class="social-page" data-social-page
    data-feed-url="{{ route('social.posts.index') }}"
    data-max-images="{{ \App\Support\SocialImageRules::maxImages() }}"
    data-max-image-kb="{{ \App\Support\SocialImageRules::maxKilobytes() }}"
    data-post-max-length="{{ (int) config('social.post_max_length') }}">
    <div class="social-page__column">
        <header class="social-page__header">
            <span class="social-page__icon" aria-hidden="true"><i class="bi bi-chat-square-text"></i></span>
            <div>
                <h1>Social Community</h1>
                <p>พื้นที่พูดคุยและแบ่งปันของพนักงานทุกแผนกใน Smart Goals</p>
            </div>
        </header>

        @include('social.partials.composer')

        <section class="social-feed" aria-label="โพสต์ในชุมชน">
            <div class="social-feed__list" data-social-feed>
                @include('social.partials.posts')
            </div>

            <div class="social-feed__empty" data-social-empty @if($posts->isNotEmpty()) hidden @endif>
                <span class="social-feed__empty-icon" aria-hidden="true"><i class="bi bi-chat-square-heart"></i></span>
                <strong>ยังไม่มีโพสต์ในชุมชน</strong>
                <p>เริ่มแบ่งปันเรื่องราวแรกให้เพื่อนร่วมงานทุกแผนกได้เลย</p>
            </div>

            <div class="social-feed__more">
                <button type="button" class="social-feed__more-button" data-social-load-more
                    data-next-cursor="{{ $nextCursor }}" @if(! $nextCursor) hidden @endif>
                    <span class="spinner-border spinner-border-sm" aria-hidden="true" data-social-spinner hidden></span>
                    <span data-social-load-more-label>โหลดโพสต์เพิ่มเติม</span>
                </button>
                <p class="social-feed__end" data-social-feed-end @if($nextCursor || $posts->isEmpty()) hidden @endif>คุณดูโพสต์ครบทั้งหมดแล้ว</p>
            </div>
        </section>
    </div>

    @include('social.partials.post-modal')
    @include('social.partials.image-viewer')
    @include('social.partials.templates')
</div>
@endsection
