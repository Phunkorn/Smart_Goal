@php
    /**
     * การ์ดโพสต์หนึ่งใบ — แหล่งเดียวของการ์ดทั้งตอนเปิดหน้า โหลดเพิ่ม สร้าง และแก้ไข
     *
     * @var \App\Models\SocialPost $post  โหลดผ่าน SocialPostService (author.department, media,
     *                                    likes_count, comments_count, liked_by_viewer)
     *
     * ข้อความผู้ใช้แสดงผ่าน {{ }} เท่านั้น (escape แล้ว) การขึ้นบรรทัดคงไว้ด้วย CSS white-space
     * รูปใช้ URL ของ MediaController ซึ่งตรวจสิทธิ์จากโพสต์ ไม่มี path จริงของไฟล์ออกไปที่หน้าเว็บ
     */
    $author = $post->author;
    $authorName = $author?->name ?? 'ผู้ใช้ที่ถูกลบ';
    $department = $author?->department?->department_name;
    $media = $post->media;
    $images = $media->values()->map(fn ($item, $index) => [
        'id' => $item->id,
        'url' => route('media.social-post-media.show', $item),
        'width' => $item->width,
        'height' => $item->height,
        'alt' => 'รูปภาพที่ '.($index + 1).' จากโพสต์ของ '.$authorName,
    ]);
    $visibleImages = $images->take(4);
    $hiddenImageCount = max(0, $images->count() - 4);
    $canUpdate = auth()->user()?->can('update', $post) ?? false;
    $canDelete = auth()->user()?->can('delete', $post) ?? false;
    $isOwner = (int) $post->user_id === (int) auth()->id();
    $likes = (int) ($post->likes_count ?? 0);
    $commentCount = (int) ($post->comments_count ?? 0);
    $liked = (bool) ($post->liked_by_viewer ?? false);
    // ผู้ชมของโพสต์ — ชื่อแผนกคือแผนกที่ตรึงไว้กับโพสต์ ไม่ใช่แผนกปัจจุบันของผู้โพสต์
    $targetDepartment = $post->isDepartmentOnly() ? $post->targetDepartment?->department_name : null;
    $audienceLabel = $post->isDepartmentOnly()
        ? 'เฉพาะแผนก'.($targetDepartment ? ' '.$targetDepartment : '')
        : 'ทุกคนในองค์กร';
@endphp
<article class="social-card social-post" id="social-post-{{ $post->id }}" data-social-post data-post-id="{{ $post->id }}"
    data-like-url="{{ route('social.posts.like.store', $post) }}"
    data-comments-url="{{ route('social.posts.comments.index', $post) }}"
    data-images="{{ json_encode($images->map(fn ($image) => ['url' => $image['url'], 'alt' => $image['alt']])->all()) }}"
    aria-labelledby="social-post-{{ $post->id }}-author">
    <header class="social-post__head">
        <span class="social-avatar" aria-hidden="true">
            @include('components.user-avatar-content', ['user' => $author, 'avatarLength' => 2])
        </span>
        <div class="social-post__meta">
            <strong class="social-post__author" id="social-post-{{ $post->id }}-author">{{ $authorName }}</strong>
            <span class="social-post__sub">
                @if ($department)
                    <span class="social-post__department">{{ $department }}</span>
                    <span aria-hidden="true">·</span>
                @endif
                <time datetime="{{ \App\Support\SocialTime::iso($post->created_at) }}" title="{{ \App\Support\SocialTime::full($post->created_at) }}">{{ \App\Support\SocialTime::relative($post->created_at) }}</time>
                <span aria-hidden="true">·</span>
                <span class="social-post__audience{{ $post->isDepartmentOnly() ? ' is-department' : '' }}" title="{{ $audienceLabel }}" data-social-post-audience="{{ $post->visibility }}">
                    <i class="bi {{ $post->isDepartmentOnly() ? 'bi-people-fill' : 'bi-globe2' }}" aria-hidden="true"></i>
                    <span class="visually-hidden">ผู้ที่มองเห็น: {{ $audienceLabel }}</span>
                </span>
                @if ($post->edited_at)
                    <span aria-hidden="true">·</span>
                    <span class="social-post__edited" title="แก้ไขเมื่อ {{ \App\Support\SocialTime::full($post->edited_at) }}">แก้ไขแล้ว</span>
                @endif
            </span>
        </div>

        @if ($canUpdate || $canDelete)
            <div class="dropdown social-post__menu">
                <button type="button" class="social-icon-button" data-bs-toggle="dropdown" aria-expanded="false"
                    aria-label="จัดการโพสต์ของ {{ $authorName }}">
                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end social-post__menu-list">
                    @if ($canUpdate)
                        <li>
                            <button type="button" class="dropdown-item" data-social-post-edit
                                data-update-url="{{ route('social.posts.update', $post) }}"
                                data-post="{{ json_encode(['id' => $post->id, 'body' => (string) $post->body, 'visibility' => $post->visibility, 'department_label' => $targetDepartment ? 'เฉพาะแผนกของฉัน ('.$targetDepartment.')' : null, 'media' => $images->map(fn ($image) => ['id' => $image['id'], 'url' => $image['url'], 'alt' => $image['alt']])->all()]) }}">
                                <i class="bi bi-pencil" aria-hidden="true"></i> แก้ไขโพสต์
                            </button>
                        </li>
                    @endif
                    @if ($canDelete)
                        <li>
                            <button type="button" class="dropdown-item text-danger" data-social-post-delete
                                data-destroy-url="{{ route('social.posts.destroy', $post) }}"
                                data-moderation="{{ $isOwner ? 'false' : 'true' }}">
                                <i class="bi bi-trash3" aria-hidden="true"></i> {{ $isOwner ? 'ลบโพสต์' : 'ลบโพสต์ (ผู้ดูแลระบบ)' }}
                            </button>
                        </li>
                    @endif
                </ul>
            </div>
        @endif
    </header>

    @if ($post->hasBody())
        <div class="social-post__body">{{ $post->body }}</div>
    @endif

    @if ($visibleImages->isNotEmpty())
        <div class="social-media-grid social-media-grid--{{ $visibleImages->count() }}">
            @foreach ($visibleImages as $index => $image)
                <button type="button" class="social-media-grid__item" data-social-image-open="{{ $index }}"
                    aria-label="เปิดดู{{ $image['alt'] }}">
                    <img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" loading="lazy" decoding="async"
                        @if($image['width'] && $image['height']) width="{{ $image['width'] }}" height="{{ $image['height'] }}" @endif>
                    @if ($loop->last && $hiddenImageCount > 0)
                        <span class="social-media-grid__more" aria-hidden="true">+{{ $hiddenImageCount }}</span>
                    @endif
                </button>
            @endforeach
        </div>
    @endif

    <div class="social-post__stats">
        <span class="social-post__likes" data-social-like-summary @if($likes === 0) hidden @endif>
            <span class="social-post__like-badge" aria-hidden="true"><i class="bi bi-hand-thumbs-up-fill"></i></span>
            <span data-social-like-count>{{ number_format($likes) }}</span> ถูกใจ
        </span>
        <button type="button" class="social-post__comment-count" data-social-comments-toggle
            aria-controls="social-comments-{{ $post->id }}" @if($commentCount === 0) hidden @endif>
            <span data-social-comment-count>{{ number_format($commentCount) }}</span> ความคิดเห็น
        </button>
    </div>

    <div class="social-post__actions">
        <button type="button" class="social-post__action{{ $liked ? ' is-active' : '' }}" data-social-like
            aria-pressed="{{ $liked ? 'true' : 'false' }}">
            <i class="bi {{ $liked ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up' }}" aria-hidden="true" data-social-like-icon></i>
            <span>ถูกใจ</span>
        </button>
        <button type="button" class="social-post__action" data-social-comments-toggle data-focus-input
            aria-controls="social-comments-{{ $post->id }}" aria-expanded="false">
            <i class="bi bi-chat" aria-hidden="true"></i>
            <span>แสดงความคิดเห็น</span>
        </button>
    </div>

    <section class="social-comments" id="social-comments-{{ $post->id }}" data-social-comments hidden
        aria-label="ความคิดเห็นของโพสต์ {{ $authorName }}">
        <button type="button" class="social-comments__older" data-social-comments-older hidden>
            ดูความคิดเห็นก่อนหน้า
        </button>
        <p class="social-comments__status" data-social-comments-status role="status" hidden></p>
        <ul class="social-comments__list" data-social-comment-list></ul>
        <p class="social-comments__empty" data-social-comments-empty hidden>ยังไม่มีความคิดเห็น เริ่มแสดงความคิดเห็นเป็นคนแรกได้เลย</p>

        <form class="social-comment-form" data-social-comment-form action="{{ route('social.posts.comments.store', $post) }}" novalidate>
            <span class="social-avatar social-avatar--sm" aria-hidden="true">
                @include('components.user-avatar-content', ['user' => auth()->user(), 'avatarLength' => 2])
            </span>
            <div class="social-comment-form__field">
                <label class="visually-hidden" for="social-comment-input-{{ $post->id }}">เขียนความคิดเห็น</label>
                <textarea id="social-comment-input-{{ $post->id }}" name="body" rows="1"
                    maxlength="{{ (int) config('social.comment_max_length') }}"
                    placeholder="เขียนความคิดเห็น..." data-social-comment-input></textarea>
                <button type="submit" class="social-comment-form__send" aria-label="ส่งความคิดเห็น">
                    <i class="bi bi-send-fill" aria-hidden="true"></i>
                </button>
            </div>
        </form>
    </section>
</article>
