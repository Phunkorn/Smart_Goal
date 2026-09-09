<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use App\Services\Telegram\TelegramOutbox;
use App\Support\TelegramMessageComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * การเชื่อมต่อ Telegram ของผู้ใช้ที่ล็อกอินอยู่
 *
 * แยกจาก SettingsController เพราะเป็นคนละเรื่องกับข้อมูลส่วนตัวและรหัสผ่าน
 * และมีทั้งเส้นทางที่ตอบ JSON (หน้าเว็บ poll สถานะการผูก) และเส้นทางที่ redirect กลับ
 *
 * ทุกเส้นทางทำงานกับ $request->user() เท่านั้น ไม่รับ user id จากภายนอกเลย
 * ผู้ใช้จึงแก้การเชื่อมต่อของคนอื่นไม่ได้แม้ยิง request ตรง
 */
class TelegramSettingsController extends Controller
{
    public function __construct(
        private readonly TelegramLinkService $links,
        private readonly TelegramOutbox $outbox,
        private readonly TelegramClient $client,
    ) {}

    public function link(Request $request): JsonResponse
    {
        $user = $this->eligibleUser($request);
        $token = $this->links->issueToken($user);
        $deepLink = $this->links->deepLink($token);

        if ($deepLink === null) {
            return response()->json([
                'ok' => false,
                'message' => 'ระบบยังไม่ได้ตั้งค่าชื่อบอท Telegram กรุณาติดต่อผู้ดูแลระบบ',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'ok' => true,
            'deep_link' => $deepLink,
            'token' => $token->token,
            'expires_at' => $token->expires_at->toIso8601String(),
        ]);
    }

    /**
     * ให้หน้าตั้งค่าถามสถานะเป็นระยะระหว่างรอผู้ใช้กด Start ในแอป Telegram
     * เพราะการผูกสำเร็จเกิดที่ webhook ไม่ใช่ที่ request ของเบราว์เซอร์
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'ok' => true,
            'linked' => $user->telegram_chat_id !== null,
            'enabled' => (bool) $user->telegram_notifications_enabled,
            'username' => $user->telegram_username,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->eligibleUser($request);

        $validated = $request->validate([
            'telegram_notifications_enabled' => ['required', 'boolean'],
        ]);

        if ($user->telegram_chat_id === null) {
            return back()->withErrors(['telegram' => 'ยังไม่ได้เชื่อมต่อ Telegram']);
        }

        $enabled = (bool) $validated['telegram_notifications_enabled'];
        $this->links->setEnabled($user, $enabled);

        return back()->with('success', $enabled
            ? 'เปิดการแจ้งเตือนผ่าน Telegram แล้ว'
            : 'ปิดการแจ้งเตือนผ่าน Telegram แล้ว');
    }

    public function test(Request $request): RedirectResponse
    {
        $user = $this->eligibleUser($request);

        if ($user->telegram_chat_id === null) {
            return back()->withErrors(['telegram' => 'ยังไม่ได้เชื่อมต่อ Telegram']);
        }

        if (! $this->client->enabled()) {
            return back()->withErrors(['telegram' => 'ระบบยังไม่ได้เปิดใช้งานการแจ้งเตือนผ่าน Telegram']);
        }

        $result = $this->outbox->sendNow($user, TelegramMessageComposer::test($user->name));

        return $result->ok
            ? back()->with('success', 'ส่งข้อความทดสอบไปที่ Telegram แล้ว')
            : back()->withErrors(['telegram' => 'ส่งไม่สำเร็จ: '.($result->description ?: 'ไม่ทราบสาเหตุ')]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->links->unlink($this->eligibleUser($request));

        return back()->with('success', 'ยกเลิกการเชื่อมต่อ Telegram แล้ว');
    }

    /**
     * viewer เป็นสิทธิ์อ่านอย่างเดียวและไม่เคยเป็นผู้รับการแจ้งเตือนในระบบ
     * ช่องทางใหม่ต้องไม่กลายเป็นทางลัดข้ามกติกานั้น
     */
    private function eligibleUser(Request $request): User
    {
        $user = $request->user();

        abort_if($user->role === 'viewer', Response::HTTP_FORBIDDEN);

        return $user;
    }
}
