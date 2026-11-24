/** Password hints only; server-side PasswordPolicy remains authoritative. */
export function initSetupPassword(doc = globalThis.document) {
    const document = doc;
    const rules = document?.getElementById('passwordRules');
    if (!rules || rules.dataset.rulesReady === 'on') return null;
    rules.dataset.rulesReady = 'on';
    const setRule = (id, ok) => {
        const rule = document.getElementById(id);
        rule.classList.toggle('ok', ok);
        rule.querySelector('i').className = ok ? 'bi bi-check-circle-fill' : 'bi bi-circle';
    };
    // ตัวช่วยเตือนเท่านั้น การบังคับจริงอยู่ที่ App\Support\PasswordPolicy ฝั่งเซิร์ฟเวอร์
    function checkPassword() {
        const minLength = Number(rules.dataset.minLength);
        const password = document.getElementById('password').value;
        const confirmation = document.getElementById('password_confirmation').value;
        const checks = {
            'rule-length': [...password].length >= minLength,
            'rule-lowercase': /\p{Ll}/u.test(password),
            'rule-uppercase': /\p{Lu}/u.test(password),
            'rule-number': /\p{N}/u.test(password),
            'rule-symbol': /[\p{Z}\p{S}\p{P}]/u.test(password),
            'rule-match': password.length > 0 && password === confirmation,
        };
        let passed = 0;
        const total = Object.keys(checks).length;
        for (const [id, ok] of Object.entries(checks)) {
            setRule(id, ok);
            if (ok) passed++;
        }

        // แถบความคืบหน้าไล่สี แดง -> ส้ม -> เขียว ตามจำนวนข้อที่ผ่าน
        const meter = document.getElementById('passwordMeter');
        if (meter) {
            const percent = Math.round(passed / total * 100);
            meter.style.width = percent + '%';
            meter.dataset.strength = percent < 50 ? 'weak' : percent < 100 ? 'partial' : 'complete';
        }

        // ขอบเขียวเมื่อครบเงื่อนไข ขอบแดงพร้อมข้อความเมื่อยืนยันไม่ตรง
        const strong = ['rule-length', 'rule-lowercase', 'rule-uppercase', 'rule-number', 'rule-symbol'].every((id) => checks[id]);
        const mismatch = confirmation.length > 0 && password !== confirmation;
        document.getElementById('controlPassword')?.classList.toggle('is-good', strong);
        document.getElementById('controlConfirmation')?.classList.toggle('is-bad', mismatch);
        document.getElementById('controlConfirmation')?.classList.toggle('is-good', checks['rule-match']);
        document.getElementById('confirmationMessage')?.classList.toggle('is-shown', mismatch);
        document.getElementById('submitBtn').disabled = passed < total;
    }

    for (const id of ['password', 'password_confirmation']) {
        document.getElementById(id)?.addEventListener('input', checkPassword);
    }
    checkPassword();
    return {checkPassword};
}
