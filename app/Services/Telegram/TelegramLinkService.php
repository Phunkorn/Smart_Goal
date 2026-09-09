<?php

namespace App\Services\Telegram;

use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * การผูกและยกเลิกการผูกบัญชี Telegram รายบุคคล
 *
 * ผู้ใช้ขอรหัสจากหน้าตั้งค่าแล้วส่งให้บอทด้วย /start <token> วิธีนี้ทำให้ผู้ใช้
 * ไม่ต้องไปหา chat_id ของตัวเองจากบอทอื่น และเว็บได้ chat_id ที่ยืนยันตัวตนแล้ว
 * เพราะมีแต่เจ้าของบัญชีที่ล็อกอินอยู่เท่านั้นที่เห็นรหัส
 */
class TelegramLinkService
{
    public function __construct(private readonly TelegramOutbox $outbox) {}

    /**
     * ออกรหัสใหม่ให้ผู้ใช้ และทิ้งรหัสเดิมที่ยังไม่ถูกใช้
     *
     * ทิ้งของเดิมเสมอเพื่อให้มีรหัสที่ใช้ได้อยู่ชุดเดียวต่อผู้ใช้หนึ่งคน
     * รหัสเก่าที่ค้างในประวัติแชทจึงใช้ผูกซ้ำไม่ได้
     */
    public function issueToken(User $user): TelegramLinkToken
    {
        return DB::transaction(function () use ($user): TelegramLinkToken {
            TelegramLinkToken::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->delete();

            return TelegramLinkToken::create([
                'user_id' => $user->id,
                'token' => Str::random(32),
                'expires_at' => now()->addMinutes(TelegramLinkToken::LIFETIME_MINUTES),
            ]);
        });
    }

    public function deepLink(TelegramLinkToken $token): ?string
    {
        $bot = trim((string) config('services.telegram.bot_username'), " \t\n\r\0\x0B@");

        return $bot === '' ? null : 'https://t.me/'.$bot.'?start='.$token->token;
    }

    public function consume(string $token, int $chatId, ?string $telegramUsername = null): TelegramLinkResult
    {
        $record = TelegramLinkToken::query()->where('token', $token)->first();

        if (! $record || ! $record->isUsable() || ! $record->user) {
            return TelegramLinkResult::invalid();
        }

        $owner = User::query()->where('telegram_chat_id', $chatId)->first();

        if ($owner && (int) $owner->id !== (int) $record->user_id) {
            return TelegramLinkResult::chatTaken();
        }

        $user = $record->user;

        DB::transaction(function () use ($record, $user, $chatId, $telegramUsername): void {
            $user->forceFill([
                'telegram_chat_id' => $chatId,
                'telegram_username' => $telegramUsername ? Str::limit($telegramUsername, 60, '') : null,
                'telegram_linked_at' => now(),
                'telegram_notifications_enabled' => true,
            ])->save();

            $record->forceFill(['consumed_at' => now()])->save();
        });

        AuditTrail::log('telegram_linked', $user, 'เชื่อมต่อการแจ้งเตือน Telegram: '.$user->name);

        return TelegramLinkResult::linked($user);
    }

    public function unlink(User $user): void
    {
        $this->outbox->unlink($user);

        TelegramLinkToken::query()->where('user_id', $user->id)->whereNull('consumed_at')->delete();

        AuditTrail::log('telegram_unlinked', $user, 'ยกเลิกการเชื่อมต่อ Telegram: '.$user->name);
    }

    public function setEnabled(User $user, bool $enabled): void
    {
        $user->forceFill(['telegram_notifications_enabled' => $enabled])->save();
    }
}
