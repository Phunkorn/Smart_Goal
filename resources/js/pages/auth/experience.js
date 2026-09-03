/**
 * งานตกแต่งที่ใช้ร่วมกันทั้ง 3 หน้า auth (login / setup-password / welcome)
 *
 * ทุกอย่างในไฟล์นี้เป็นการนำเสนอล้วน ๆ ไม่มีการตัดสินใจเชิงสิทธิ์หรือ validation
 * การบังคับจริงทั้งหมดยังอยู่ที่เซิร์ฟเวอร์ตามเดิม ฟอร์มจึงยัง submit ได้ปกติ
 * แม้สคริปต์นี้จะโหลดไม่สำเร็จ
 */

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** ทยอยไล่ให้แต่ละชิ้นลอยขึ้นตามลำดับที่เขียนไว้ใน DOM */
const playEntrance = () => {
    document.querySelectorAll('[data-auth-rise]').forEach((node, index) => {
        window.setTimeout(() => node.classList.add('is-in'), reduceMotion ? 0 : 90 * index);
    });
};

/** ตารางกริดฉากหลังขยับตามเมาส์เล็กน้อยเพื่อให้ฉากมีความลึก */
const bindParallax = () => {
    const grid = document.querySelector('[data-auth-grid]');
    const card = document.querySelector('[data-auth-card]');
    if (!grid || reduceMotion || !window.matchMedia('(pointer:fine)').matches) return;

    window.addEventListener('mousemove', (event) => {
        const x = event.clientX / window.innerWidth - 0.5;
        const y = event.clientY / window.innerHeight - 0.5;
        grid.style.transform = `translate3d(${x * -26}px, ${y * -26}px, 0)`;
        if (card) card.style.transform = `rotateY(${x * 3.4}deg) rotateX(${y * -3.4}deg)`;
    });
    document.addEventListener('mouseleave', () => {
        if (card) card.style.transform = '';
    });
};

/** ระลอกคลื่นตรงจุดที่คลิกจริง ไม่ใช่กลางปุ่มเสมอ */
const bindRipple = () => {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.btn');
        if (!button || reduceMotion) return;

        const size = Math.max(button.offsetWidth, button.offsetHeight);
        const bounds = button.getBoundingClientRect();
        const ripple = document.createElement('span');
        ripple.className = 'btn__ripple';
        ripple.style.width = `${size}px`;
        ripple.style.height = `${size}px`;
        ripple.style.left = `${event.clientX - bounds.left - size / 2}px`;
        ripple.style.top = `${event.clientY - bounds.top - size / 2}px`;
        button.append(ripple);
        window.setTimeout(() => ripple.remove(), 600);
    });
};

/**
 * ปุ่มที่ส่งฟอร์มต้องบอกว่ากำลังทำงานอยู่ และกันการกดซ้ำ
 * ใช้ data-loading-label เพื่อให้แต่ละหน้ากำหนดข้อความของตัวเองได้
 */
const bindSubmitState = () => {
    document.querySelectorAll('form[data-auth-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('[data-auth-submit]');
            if (!button || button.disabled) return;

            const label = button.dataset.loadingLabel || 'กำลังดำเนินการ';
            button.classList.add('is-busy');
            button.disabled = true;
            const slot = button.querySelector('span');
            if (slot) slot.innerHTML = `<i class="btn__spinner" aria-hidden="true"></i>${label}`;
        });
    });
};

/** ข้อความแจ้งลอยด้านล่าง ใช้กับผลลัพธ์ที่เซิร์ฟเวอร์ส่งกลับมาเท่านั้น */
const showToast = (message) => {
    const toast = document.querySelector('[data-auth-toast]');
    const slot = toast?.querySelector('[data-auth-toast-text]');
    if (!toast || !slot || !message) return;

    slot.textContent = message;
    toast.classList.add('is-shown');
    window.setTimeout(() => toast.classList.remove('is-shown'), 2600);
};

const bindPasswordToggle = () => {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-toggle-password]');
        if (!trigger) return;

        const input = document.getElementById(trigger.dataset.togglePassword);
        const icon = trigger.querySelector('i');
        if (!input || !icon) return;

        const hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        icon.classList.toggle('bi-eye-fill', !hidden);
        icon.classList.toggle('bi-eye-slash-fill', hidden);
        trigger.setAttribute('aria-label', hidden ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
        input.focus();
    });
};

playEntrance();
bindParallax();
bindRipple();
bindSubmitState();
bindPasswordToggle();

const flash = document.querySelector('[data-auth-toast]')?.dataset.authToast;
if (flash) window.setTimeout(() => showToast(flash), 400);
