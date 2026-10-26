<?php

namespace App\Http\Middleware;

use App\Services\TicketSchemaStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * หน้าของ Smart Ticket ตอบ 503 พร้อมคำอธิบาย เมื่อฐานข้อมูลยังไม่ได้ migrate ตาราง Ticket
 * แทนการปล่อยให้ SQL error เป็นหน้า 500 — ส่วนอื่นของ Smart Goals ไม่ถูกแตะ
 */
class EnsureTicketsInstalled
{
    public const MESSAGE = 'Smart Ticket ยังไม่พร้อมใช้งานในระบบนี้ กรุณาติดต่อผู้ดูแลระบบ';

    public function __construct(private readonly TicketSchemaStatus $schema) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->schema->isInstalled()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => self::MESSAGE], 503);
        }

        return response()->view('tickets.unavailable', ['message' => self::MESSAGE], 503);
    }
}
