<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Ticket แจ้งปัญหา IT
 *
 * สถานะเปลี่ยนได้ทางเดียวคือ App\Services\TicketWorkflowService::transition()
 * ตาราง TRANSITIONS ด้านล่างคือนิยามของ state machine ที่ทั้ง service และ TicketPolicy อ่าน
 *
 * คอลัมน์ผู้แจ้ง สถานะ เลข Ticket และการปิดงานไม่อยู่ใน $fillable โดยตั้งใจ
 * ค่าเหล่านี้ถูกตั้งจากฝั่ง server ใน service เท่านั้น (forceFill) ไม่ใช่จากข้อมูลที่ผู้ใช้ส่งมา
 */
class Ticket extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FOLLOW_UP = 'follow_up';

    public const STATUS_CLOSED = 'closed';

    public const SOURCE_INTERNAL = 'internal';

    public const SOURCE_PUBLIC = 'public';

    public const ACTION_START = 'start';

    public const ACTION_FOLLOW_UP = 'follow_up';

    public const ACTION_RESUME = 'resume';

    public const ACTION_CLOSE = 'close';

    public const ACTION_REOPEN = 'reopen';

    /** ยกเลิกงาน — เก็บสถานะ closed เพื่อรักษากติกา Ticket ที่เปิดอยู่ และแยกด้วย activity ล่าสุดที่ปิดงาน */
    public const ACTION_CANCEL = 'cancel';

    /**
     * action => [สถานะต้นทางที่อนุญาต, สถานะปลายทาง]
     *
     * ปิดงานปกติต้องรับงานก่อน ส่วนยกเลิก Ticket ใหม่ทำได้โดยเจ้าหน้าที่โดยไม่ต้องรับงาน
     *
     * @var array<string, array{from: array<int, string>, to: string}>
     */
    public const TRANSITIONS = [
        self::ACTION_START => ['from' => [self::STATUS_NEW], 'to' => self::STATUS_ACTIVE],
        self::ACTION_FOLLOW_UP => ['from' => [self::STATUS_ACTIVE], 'to' => self::STATUS_FOLLOW_UP],
        self::ACTION_RESUME => ['from' => [self::STATUS_FOLLOW_UP], 'to' => self::STATUS_ACTIVE],
        self::ACTION_CLOSE => ['from' => [self::STATUS_ACTIVE, self::STATUS_FOLLOW_UP], 'to' => self::STATUS_CLOSED],
        self::ACTION_CANCEL => ['from' => [self::STATUS_NEW, self::STATUS_ACTIVE, self::STATUS_FOLLOW_UP], 'to' => self::STATUS_CLOSED],
        self::ACTION_REOPEN => ['from' => [self::STATUS_CLOSED], 'to' => self::STATUS_ACTIVE],
    ];

    /** ความยาวหัวการ์ดที่ได้จากบรรทัดแรกของรายละเอียด */
    public const SUMMARY_LENGTH = 120;

    protected $fillable = [
        'description',
        'contact',
    ];

    /** userId ของ LINE เป็นตัวระบุบัญชีภายนอก — ใช้ฝั่ง server กันเปิดเคสซ้ำเท่านั้น ไม่ส่งออกใน JSON */
    protected $hidden = [
        'line_user_id',
        'open_line_user_id',
        'open_requester_id',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'reopen_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'follow_up_date' => 'date',
            'first_responded_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopen_count' => 'integer',
        ];
    }

    public static function formatNumber(int $id): string
    {
        return 'TK-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /** action นี้ทำได้จากสถานะนี้หรือไม่ */
    public static function allowsTransition(string $fromStatus, string $action): bool
    {
        return in_array($fromStatus, self::TRANSITIONS[$action]['from'] ?? [], true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * หัวการ์ด — บรรทัดแรกที่มีข้อความของรายละเอียด ไม่เก็บซ้ำเป็นคอลัมน์
     */
    protected function summary(): Attribute
    {
        return Attribute::get(function (): string {
            $firstLine = Str::of((string) $this->description)
                ->explode("\n")
                ->map(fn (string $line) => trim($line))
                ->first(fn (string $line) => $line !== '', '');

            return Str::limit($firstLine, self::SUMMARY_LENGTH, '…');
        });
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id')->withTrashed();
    }

    public function requesterDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requester_department_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(TicketLocation::class, 'location_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id')->withTrashed();
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    /** ทุกคนที่เคยเข้าร่วม รวมคนที่ออกไปแล้ว */
    public function participants(): HasMany
    {
        return $this->hasMany(TicketParticipant::class);
    }

    /** ผู้เข้าร่วมปัจจุบัน */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class)->orderBy('created_at')->orderBy('id');
    }

    /** เหตุการณ์ปิดล่าสุดใช้แยก "ยกเลิก" จาก "ปิดงาน" รวมกรณีเปิดงานใหม่แล้วปิดอีกครั้ง */
    public function closingActivity(): HasOne
    {
        return $this->hasOne(TicketActivity::class)
            ->where('type', TicketActivity::TYPE_STATUS_CHANGED)
            ->where('to_status', self::STATUS_CLOSED)
            ->latestOfMany('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /** ผู้ใช้คนนี้เป็นผู้เข้าร่วมปัจจุบันหรือไม่ — ใช้ relation ที่โหลดไว้แล้วเมื่อมี */
    public function hasActiveParticipant(User $user): bool
    {
        if ($this->relationLoaded('activeParticipants')) {
            return $this->activeParticipants->contains('user_id', $user->id);
        }

        return $this->activeParticipants()->where('user_id', $user->id)->exists();
    }

    public function isRequestedBy(User $user): bool
    {
        return $this->requester_id !== null && (int) $this->requester_id === (int) $user->id;
    }
}
