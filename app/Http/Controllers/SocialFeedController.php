<?php

namespace App\Http\Controllers;

use App\Models\SocialPost;
use App\Services\SocialFeedReadService;
use App\Services\SocialPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * หน้าชุมชน (/social) และการโหลดโพสต์เพิ่ม
 *
 * หน้าแรกเรนเดอร์โพสต์ชุดแรกจาก server ส่วน "โหลดโพสต์เพิ่มเติม" ได้ HTML ของการ์ด
 * จาก partial ตัวเดียวกัน (social.partials.post) การ์ดโพสต์จึงมีแหล่งเดียว ไม่มีเทมเพลตชุดที่สองใน JS
 */
class SocialFeedController extends Controller
{
    public function __construct(
        private readonly SocialPostService $posts,
        private readonly SocialFeedReadService $reads,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SocialPost::class);

        $viewer = $request->user()->loadMissing('department');
        // บันทึกเวลาเปิดฟีดก่อนดึงโพสต์ — ดู SocialFeedReadService::markSeen() (ตัวเลขบนเมนูชุมชนกลับเป็น 0)
        $this->reads->markSeen($viewer);
        $feed = $this->posts->feed($viewer);

        return view('social.index', [
            'viewer' => $viewer,
            'posts' => $feed['posts'],
            'nextCursor' => $feed['next_cursor'],
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SocialPost::class);

        $validated = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $feed = $this->posts->feed($request->user(), isset($validated['before']) ? (int) $validated['before'] : null);

        return response()->json([
            'ok' => true,
            'html' => view('social.partials.posts', ['posts' => $feed['posts']])->render(),
            'count' => $feed['posts']->count(),
            'next_cursor' => $feed['next_cursor'],
        ]);
    }
}
