<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * ด่านกันสแปมและการส่งซ้ำของฟอร์มแจ้งปัญหา — ที่เดียว
 *
 * ฟอร์มสาธารณะ /support ไม่ต้องเข้าสู่ระบบ จึงใช้สามชั้นที่ไม่ต้องเพิ่ม dependency:
 *   1. Rate limit ต่อ IP (ลงทะเบียนใน AppServiceProvider ด้วยชื่อ RATE_LIMITER)
 *   2. Honeypot — ช่องที่มนุษย์มองไม่เห็นแต่บอทมักกรอก
 *   3. เวลากรอกขั้นต่ำ — token เข้ารหัสที่บอกเวลาเปิดฟอร์ม ส่งเร็วกว่า MIN_SECONDS คือบอท
 *
 * ผู้ใช้ภายในผ่านชั้นที่ 1 (จำกัดต่อบัญชี) เพราะยืนยันตัวตนแล้ว
 * การกดส่งซ้ำ (double submit) กันด้วย submission_key ใน TicketSubmissionService
 */
final class TicketSubmissionGuard
{
    public const RATE_LIMITER = 'ticket-submissions';

    public const HONEYPOT_FIELD = 'website';

    public const TOKEN_FIELD = 'form_token';

    /** มนุษย์อ่านฟอร์มและพิมพ์รายละเอียดไม่ทันใน 3 วินาที */
    public const MIN_SECONDS = 3;

    /** ฟอร์มที่เปิดค้างนานกว่านี้ต้องส่งใหม่ — กันการเก็บ token ไว้ยิงซ้ำภายหลัง */
    public const MAX_AGE_SECONDS = 6 * 60 * 60;

    public static function issueToken(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /** คืนข้อความภาษาไทยเมื่อควรปฏิเสธ หรือ null เมื่อผ่าน */
    public static function rejectionReason(mixed $honeypot, mixed $token): ?string
    {
        $blocked = 'ไม่สามารถส่งแบบฟอร์มได้ กรุณาลองใหม่อีกครั้ง';

        if (trim((string) $honeypot) !== '') {
            return $blocked;
        }

        try {
            $issuedAt = (int) Crypt::decryptString((string) $token);
        } catch (DecryptException) {
            return $blocked;
        }

        $elapsed = now()->getTimestamp() - $issuedAt;

        if ($elapsed < self::MIN_SECONDS) {
            return $blocked;
        }

        if ($elapsed > self::MAX_AGE_SECONDS) {
            return 'หน้าฟอร์มเปิดค้างไว้นานเกินไป กรุณาตรวจสอบข้อมูลแล้วกดส่งอีกครั้ง';
        }

        return null;
    }
}
