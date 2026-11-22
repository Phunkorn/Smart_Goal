@php($telegramLinked = $user->telegram_chat_id !== null)
@php($telegramConfigured = filled(config('services.telegram.bot_username')) && (bool) config('services.telegram.enabled'))

<section class="settings-card settings-telegram" aria-labelledby="telegram-settings-title" data-telegram-settings
    data-status-url="{{ route('settings.telegram.status') }}"
    data-link-url="{{ route('settings.telegram.link') }}"
    data-linked="{{ $telegramLinked ? 'true' : 'false' }}">
    <div class="settings-card__header settings-telegram__header">
        <div class="settings-telegram__summary">
            <div class="settings-telegram__icon" aria-hidden="true"><i class="bi bi-telegram"></i></div>
            <div>
                <span class="settings-card__eyebrow">การแจ้งเตือน</span>
                <h2 id="telegram-settings-title">Telegram</h2>
                <p>รับการแจ้งเตือนงานเป็นแชทส่วนตัว ไม่ต้องเปิดเว็บค้างไว้</p>
            </div>
        </div>

        <div class="settings-telegram__actions">
            @if($telegramLinked)
                <form action="{{ route('settings.telegram.test') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-send" aria-hidden="true"></i>
                        ส่งข้อความทดสอบ
                    </button>
                </form>
                <form action="{{ route('settings.telegram.destroy') }}" method="POST" data-telegram-unlink-form>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle" aria-hidden="true"></i>
                        ยกเลิกการเชื่อมต่อ
                    </button>
                </form>
            @else
                <button type="button" class="btn btn-primary settings-primary-button" data-telegram-connect
                    @disabled(! $telegramConfigured)>
                    <i class="bi bi-telegram" aria-hidden="true"></i>
                    เชื่อมต่อ Telegram
                </button>
            @endif
        </div>
    </div>

    @if(! $telegramConfigured && ! $telegramLinked)
       
    @elseif($telegramLinked)
        <div class="settings-telegram__state">
            <span class="settings-telegram__badge {{ $user->telegram_notifications_enabled ? 'settings-telegram__badge--on' : 'settings-telegram__badge--off' }}">
                <i class="bi {{ $user->telegram_notifications_enabled ? 'bi-bell-fill' : 'bi-bell-slash' }}" aria-hidden="true"></i>
                {{ $user->telegram_notifications_enabled ? 'เปิดอยู่' : 'ปิดอยู่' }}
            </span>
            <span class="settings-telegram__account">
                เชื่อมต่อกับ
                <strong>{{ $user->telegram_username ? '@'.$user->telegram_username : 'บัญชี Telegram ของคุณ' }}</strong>
                @if($user->telegram_linked_at)
                    เมื่อ {{ $user->telegram_linked_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.
                @endif
            </span>

            <form action="{{ route('settings.telegram.update') }}" method="POST" class="settings-telegram__toggle"
                data-telegram-toggle-form>
                @csrf
                @method('PATCH')
                <input type="hidden" name="telegram_notifications_enabled" value="{{ $user->telegram_notifications_enabled ? 0 : 1 }}">
                <input class="form-check-input" type="checkbox" role="switch" id="telegramEnabledSwitch"
                    @checked($user->telegram_notifications_enabled)>
                <label class="form-check-label" for="telegramEnabledSwitch">รับการแจ้งเตือนผ่าน Telegram</label>
            </form>
        </div>
    @endif
    @if($telegramLinked)
        <div class="alert alert-warning m-3" data-telegram-unlink-confirm hidden>
            <p>ยกเลิกการเชื่อมต่อ Telegram? คุณจะไม่ได้รับการแจ้งเตือนจนกว่าจะเชื่อมต่อใหม่</p>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-light" data-telegram-unlink-no>ไม่ใช่ตอนนี้</button>
                <button type="button" class="btn btn-danger" data-telegram-unlink-yes>ยกเลิกการเชื่อมต่อ</button>
            </div>
        </div>
    @endif
</section>

<section class="settings-card settings-telegram-linking mt-3 p-3" aria-label="เชื่อมต่อ Telegram" data-telegram-modal hidden>
    <ol class="settings-telegram-steps">
        <li><span>กดปุ่มด้านล่างเพื่อเปิดบอทของ Smart Goal ในแอป Telegram</span></li>
        <li><span>กด <strong>Start</strong> ในหน้าแชทที่เปิดขึ้นมา</span></li>
        <li><span>กลับมาที่หน้านี้ ระบบจะอัปเดตสถานะให้อัตโนมัติ</span></li>
    </ol>

    <a class="settings-telegram-link" href="#" target="_blank" rel="noopener" data-telegram-deep-link hidden>
        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
        เปิดบอทใน Telegram
    </a>

    <div class="settings-telegram-waiting" data-telegram-waiting hidden>
        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
        <span>กำลังรอการยืนยันจาก Telegram…</span>
    </div>

    <div class="settings-security__notice">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        <span>รหัสเชื่อมต่อใช้ได้ครั้งเดียวและหมดอายุใน {{ \App\Models\TelegramLinkToken::LIFETIME_MINUTES }} นาที อย่าส่งต่อให้ผู้อื่น</span>
    </div>

</section>
