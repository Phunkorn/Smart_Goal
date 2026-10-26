<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LINE Login (OAuth 2.1 + OpenID Connect) สำหรับฟอร์มแจ้งปัญหาสาธารณะ /support
 *
 * ใช้ยืนยันตัวตนผู้แจ้งภายนอกเท่านั้น (ไม่ใช่การเข้าสู่ระบบ Smart Goal) — ผลลัพธ์เก็บใน session ของผู้แจ้ง
 * แล้ว TicketSubmissionService ใช้ userId กันเปิดเคสซ้ำ (หนึ่งบัญชี LINE มี Ticket ที่ยังไม่ปิดได้ใบเดียว)
 *
 * ขั้นตอน: authorizeUrl() → LINE ส่ง code กลับมาที่ callback → identify() แลก code เป็น ID token
 * แล้วให้ LINE ตรวจ ID token เอง (endpoint verify: ลายเซ็น, aud = channel id, อายุ, nonce)
 * จึงไม่ต้องมีไลบรารี JWT ในระบบ
 *
 * ไม่ได้ตั้ง LINE_LOGIN_CHANNEL_ID / SECRET → enabled() = false และฟอร์มทำงานแบบเดิม (ไม่บังคับ LINE)
 */
class LineLogin
{
    private const AUTHORIZE_URL = 'https://access.line.me/oauth2/v2.1/authorize';

    private const TOKEN_URL = 'https://api.line.me/oauth2/v2.1/token';

    private const VERIFY_URL = 'https://api.line.me/oauth2/v2.1/verify';

    private const TIMEOUT_SECONDS = 8;

    /** ตัวตนที่ยืนยันแล้วใน session ของผู้แจ้ง: {user_id, name} */
    public const SESSION_KEY = 'support.line';

    /** ตั้งค่าช่องทาง LINE Login ครบแล้ว */
    public function enabled(): bool
    {
        return filled($this->channelId()) && filled($this->channelSecret());
    }

    /** ผู้แจ้งต้องยืนยันตัวด้วย LINE — ตั้งค่า LINE ไว้แล้ว หรือ config tickets.public.require_line (ค่าเริ่มต้น true) */
    public function required(): bool
    {
        return $this->enabled() || (bool) config('tickets.public.require_line', true);
    }

    /** ต้องใช้ LINE แต่ยังไม่ได้ตั้งค่า → รับแจ้งปัญหาไม่ได้ (ไม่ถอยไปรับแบบไม่ผูก LINE) */
    public function unavailable(): bool
    {
        return $this->required() && ! $this->enabled();
    }

    public function authorizeUrl(string $state, string $nonce, string $redirectUri): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->channelId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'nonce' => $nonce,
            'scope' => 'profile openid',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * แลก code เป็นตัวตนที่ LINE ยืนยันแล้ว — ล้มเหลวทุกกรณีคืน null (บันทึก log ไม่โยนต่อ)
     *
     * @return array{user_id: string, name: string}|null
     */
    public function identify(string $code, string $redirectUri, string $nonce): ?array
    {
        try {
            $token = Http::asForm()->timeout(self::TIMEOUT_SECONDS)->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $this->channelId(),
                'client_secret' => $this->channelSecret(),
            ]);

            $idToken = $token->successful() ? $token->json('id_token') : null;
            if (! is_string($idToken) || $idToken === '') {
                Log::warning('LINE Login: token exchange failed', ['status' => $token->status()]);

                return null;
            }

            $verified = Http::asForm()->timeout(self::TIMEOUT_SECONDS)->post(self::VERIFY_URL, [
                'id_token' => $idToken,
                'client_id' => $this->channelId(),
                'nonce' => $nonce,
            ]);

            $userId = $verified->successful() ? $verified->json('sub') : null;
            if (! is_string($userId) || $userId === '' || mb_strlen($userId) > 64) {
                Log::warning('LINE Login: ID token verification failed', ['status' => $verified->status()]);

                return null;
            }

            return [
                'user_id' => $userId,
                'name' => mb_substr(trim((string) $verified->json('name', '')), 0, TicketSubmissionService::NAME_MAX),
            ];
        } catch (Throwable $exception) {
            Log::warning('LINE Login: request failed', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * ตัวตน LINE ที่ยืนยันแล้วของ session นี้ — ที่เดียวที่อ่านค่านี้ (controller และ Form Request ใช้ร่วมกัน)
     *
     * @return array{user_id: string, name: string}|null
     */
    public static function identityFrom(Session $session): ?array
    {
        $line = $session->get(self::SESSION_KEY);

        return is_array($line) && is_string($line['user_id'] ?? null) && $line['user_id'] !== ''
            ? ['user_id' => $line['user_id'], 'name' => (string) ($line['name'] ?? '')]
            : null;
    }

    private function channelId(): ?string
    {
        return config('services.line_login.channel_id');
    }

    private function channelSecret(): ?string
    {
        return config('services.line_login.channel_secret');
    }
}
