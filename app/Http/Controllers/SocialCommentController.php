<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Http\Requests\Social\SocialCommentRequest;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Services\SocialCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * ความคิดเห็นและการตอบกลับในชุมชน
 *
 * ทุก response ส่ง HTML จาก partial social.partials.comment ตัวเดียวกัน และจำนวนความคิดเห็น
 * ล่าสุดของโพสต์ หน้าเว็บจึงอัปเดตเฉพาะส่วนโดยไม่ต้องโหลดหน้าใหม่
 */
class SocialCommentController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(private readonly SocialCommentService $comments) {}

    public function index(Request $request, SocialPost $post): JsonResponse
    {
        Gate::authorize('view', $post);

        $validated = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $page = $this->comments->page($post, isset($validated['before']) ? (int) $validated['before'] : null);

        return response()->json([
            'ok' => true,
            'html' => view('social.partials.comments', ['comments' => $page['comments']])->render(),
            'next_cursor' => $page['next_cursor'],
            'comments_count' => $this->comments->countFor($post),
        ]);
    }

    public function store(SocialCommentRequest $request, SocialPost $post)
    {
        $comment = $this->comments->create($post, $request->user(), $request->body(), $request->parentId());

        return $this->jsonOrBack($request, true, 'แสดงความคิดเห็นแล้ว', 201, [
            'comment_id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'html' => view('social.partials.comment', ['comment' => $comment])->render(),
            'comments_count' => $this->comments->countFor($post),
        ]);
    }

    public function update(SocialCommentRequest $request, SocialComment $comment)
    {
        $comment = $this->comments->update($comment, $request->body());

        return $this->jsonOrBack($request, true, 'บันทึกการแก้ไขความคิดเห็นแล้ว', 200, [
            'comment_id' => $comment->id,
            'html' => view('social.partials.comment', ['comment' => $comment])->render(),
        ]);
    }

    public function destroy(Request $request, SocialComment $comment)
    {
        Gate::authorize('delete', $comment);

        $post = $comment->post;
        $this->comments->delete($comment, $request->user());

        return $this->jsonOrBack($request, true, 'ลบความคิดเห็นแล้ว', 200, [
            'comment_id' => $comment->id,
            'comments_count' => $this->comments->countFor($post),
        ]);
    }
}
