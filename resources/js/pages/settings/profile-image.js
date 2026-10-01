/**
 * กล่องเลือกรูปโปรไฟล์ใช้ input ไฟล์ตัวจริงแบบโปร่งใส เบราว์เซอร์จึงไม่แสดงชื่อไฟล์ให้เอง
 * โมดูลนี้แค่เขียนชื่อไฟล์ที่เลือกลงในกล่องและไฮไลต์ตอนลากไฟล์ผ่าน ไม่แตะค่าของ input
 * และไม่เปลี่ยนการส่งฟอร์ม
 */
export function initializeProfileImagePicker(root = document) {
    const pickers = root.querySelectorAll('[data-profile-image-picker]');

    pickers.forEach((picker) => {
        if (picker.dataset.profileImagePickerReady === 'true') return;

        const input = picker.querySelector('[data-profile-image-input]');
        const label = picker.querySelector('[data-profile-image-name]');
        if (! input || ! label) return;

        picker.dataset.profileImagePickerReady = 'true';
        const emptyText = label.textContent.trim();

        input.addEventListener('change', () => {
            const file = input.files?.[0];
            label.textContent = file ? file.name : emptyText;
            picker.classList.toggle('has-file', Boolean(file));
            picker.classList.remove('is-dragover');
        });

        input.addEventListener('dragenter', () => picker.classList.add('is-dragover'));
        input.addEventListener('dragleave', () => picker.classList.remove('is-dragover'));
        input.addEventListener('drop', () => picker.classList.remove('is-dragover'));
    });

    return pickers.length;
}
