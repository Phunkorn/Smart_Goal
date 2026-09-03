{{-- ข้อความแจ้งลอยด้านล่าง ข้อความมาจาก session ของเซิร์ฟเวอร์เท่านั้น --}}
<div class="auth-toast" data-auth-toast="{{ session('status') ?? '' }}" role="status" aria-live="polite">
    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
    <span data-auth-toast-text></span>
</div>
