@php
    /**
     * ความคิดเห็นหนึ่งรายการ (และการตอบกลับของมัน ถ้าเป็นความคิดเห็นหลัก)
     *
     * @var \App\Models\SocialComment $comment  โหลด author.department และ replies.author.department แล้ว
     *
     * การตอบกลับลึกได้หนึ่งระดับ — data-root-id คือความคิดเห็นหลักที่การตอบกลับต้องผูกไว้
     * ปุ่มแก้ไข/ลบมาจาก SocialCommentPolicy ส่วน server ตรวจซ้ำทุกคำขอ
     */
    $author = $comment->author;
    $authorName = $author?->name ?? 'ผู้ใช้ที่ถูกลบ';
    $department = $author?->department?->department_name;
    $isReply = $comment->isReply();
    $canUpdate = auth()->user()?->can('update', $comment) ?? false;
    $canDelete = auth()->user()?->can('delete', $comment) ?? false;
@endphp
<li class="social-comment{{ $isReply ? ' social-comment--reply' : '' }}" id="social-comment-{{ $comment->id }}"
    data-social-comment data-comment-id="{{ $comment->id }}" data-root-id="{{ $comment->parent_id ?? $comment->id }}"
    data-author="{{ $authorName }}">
    <span class="social-avatar social-avatar--sm" aria-hidden="true">
        @include('components.user-avatar-content', ['user' => $author, 'avatarLength' => 2])
    </span>
    <div class="social-comment__main">
        <div class="social-comment__bubble">
            <span class="social-comment__author">{{ $authorName }}</span>
            @if ($department)
                <span class="social-comment__department">{{ $department }}</span>
            @endif
            <p class="social-comment__text" data-social-comment-text>{{ $comment->body }}</p>
        </div>
        <div class="social-comment__meta">
            <time datetime="{{ \App\Support\SocialTime::iso($comment->created_at) }}" title="{{ \App\Support\SocialTime::full($comment->created_at) }}">{{ \App\Support\SocialTime::relative($comment->created_at) }}</time>
            @if ($comment->edited_at)
                <span class="social-comment__edited" title="แก้ไขเมื่อ {{ \App\Support\SocialTime::full($comment->edited_at) }}">แก้ไขแล้ว</span>
            @endif
            <button type="button" class="social-comment__link" data-social-comment-reply>ตอบกลับ</button>
            @if ($canUpdate)
                <button type="button" class="social-comment__link" data-social-comment-edit
                    data-update-url="{{ route('social.comments.update', $comment) }}"
                    data-body="{{ $comment->body }}">แก้ไข</button>
            @endif
            @if ($canDelete)
                <button type="button" class="social-comment__link social-comment__link--danger" data-social-comment-delete
                    data-destroy-url="{{ route('social.comments.destroy', $comment) }}"
                    data-has-replies="{{ ! $isReply && $comment->replies->isNotEmpty() ? 'true' : 'false' }}">ลบ</button>
            @endif
        </div>

        @unless ($isReply)
            <ul class="social-comment__replies" data-social-reply-list>
                @foreach ($comment->replies as $reply)
                    @include('social.partials.comment', ['comment' => $reply])
                @endforeach
            </ul>
            <div class="social-comment__reply-slot" data-social-reply-slot></div>
        @endunless
    </div>
</li>
