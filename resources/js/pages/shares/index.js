/*
 * หน้าแชร์งาน
 *
 * สามอย่างที่ต้องทำ: สลับแท็บ, ยืนยันก่อนส่ง/ตัดสินคำขอ, และเปิดโมดัลรายละเอียด
 * แบบอ่านอย่างเดียว
 *
 * ทุกฟอร์มยังเป็นฟอร์ม POST จริงที่ทำงานได้เมื่อ JavaScript ไม่ทำงาน ที่นี่แค่เพิ่ม
 * การยืนยันก่อน submit ไม่ใช่แทนที่การ submit ด้วย fetch
 */

const page = document.querySelector('[data-shares-page]');

if (page) {
    const modal = document.querySelector('[data-share-detail-modal]');
    let modalTrigger = null;

    /* แท็บเป็นการซ่อน/แสดงฝั่ง client ล้วน URL เปลี่ยนด้วย replaceState เพื่อให้
       รีเฟรชแล้วยังอยู่แท็บเดิม โดยไม่เพิ่มประวัติย้อนกลับทีละแท็บ */
    const showTab = (key) => {
        page.querySelectorAll('[data-shares-tab]').forEach((tab) => {
            const active = tab.dataset.sharesTab === key;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        page.querySelectorAll('[data-shares-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.sharesPanel !== key;
        });

        const url = new URL(window.location.href);
        url.searchParams.set('tab', key);
        window.history.replaceState({}, '', url);
    };

    const closeModal = () => {
        if (!modal) {
            return;
        }
        modal.hidden = true;
        modalTrigger?.focus();
        modalTrigger = null;
    };

    const openModal = (button) => {
        if (!modal) {
            return;
        }

        const fill = (selector, value) => {
            const node = modal.querySelector(selector);
            if (node) {
                node.textContent = value || '-';
            }
        };

        fill('[data-share-detail-topic]', button.dataset.topic);
        fill('[data-share-detail-project]', button.dataset.project);
        fill('[data-share-detail-sharer]', button.dataset.sharer);
        fill('[data-share-detail-owner]', button.dataset.owner);
        fill('[data-share-detail-due]', button.dataset.due);
        // สิ่งที่แชร์คืองานย่อย โมดัลจึงบอกว่ามันอยู่ใต้งานไหน ไม่ใช่ว่ามีงานย่อยกี่ใบ
        fill('[data-share-detail-parent]', button.dataset.parent || 'ไม่มีงานแม่');
        fill('[data-share-detail-body]', button.dataset.details || 'ไม่มีรายละเอียดเพิ่มเติม');

        modalTrigger = button;
        modal.hidden = false;
        modal.querySelector('.shares-modal__close')?.focus();
    };

    page.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-shares-tab]');
        if (tab) {
            showTab(tab.dataset.sharesTab);
            return;
        }

        const detail = event.target.closest('[data-share-detail]');
        if (detail) {
            openModal(detail);
        }
    });

    modal?.addEventListener('click', (event) => {
        if (event.target.closest('[data-share-detail-close]')) {
            closeModal();
        }
    });

    /* โมดัลนี้เป็น modal จริง (มีฉากหลังและ aria-modal) จึงต้องปิดด้วย Escape
       และคืนโฟกัสกลับไปที่ปุ่มที่เปิดมัน */
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && !modal.hidden) {
            closeModal();
        }
    });

    page.addEventListener('submit', async (event) => {
        const joinForm = event.target.closest('[data-share-join]');
        const decideForm = event.target.closest('[data-share-decide]');
        const closeForm = event.target.closest('[data-share-close]');
        const form = joinForm || decideForm || closeForm;

        if (!form || form.dataset.confirmed === '1') {
            return;
        }

        event.preventDefault();

        const prompt = joinForm
            ? {
                title: 'ขอเข้าร่วมงานนี้',
                text: 'คำขอจะถูกส่งไปให้ผู้แชร์พิจารณา',
                icon: 'question',
                confirmButtonText: 'ส่งคำขอ',
            }
            : closeForm
                ? {
                    title: 'ปิดประกาศ',
                    text: 'คำขอที่ยังไม่ได้พิจารณาทั้งหมดจะถูกยกเลิก',
                    icon: 'warning',
                    confirmButtonText: 'ปิดประกาศ',
                }
                : decideForm.dataset.decision === 'approve'
                    ? {
                        /* ข้อความมาจาก data-final ที่ server คำนวณด้วย
                           WorkOrderShareService::decidesAlone() ห้ามให้ JS เดาเอง
                           เพราะเคยเขียนตายตัวแล้วบอกผิดกับหัวหน้าแผนกที่แชร์งานเอง */
                        title: 'อนุมัติคำขอ',
                        text: decideForm.dataset.final === '1'
                            ? 'อนุมัติแล้วผู้ขอจะเข้าร่วมงานนี้ได้ทันที'
                            : 'ผู้ขออยู่คนละแผนกกับงาน คำขอจะถูกส่งต่อให้หัวหน้าแผนกของคุณพิจารณาอีกขั้น',
                        icon: 'question',
                        confirmButtonText: 'อนุมัติ',
                    }
                    : {
                        title: 'ปฏิเสธคำขอ',
                        text: 'ผู้ขอจะได้รับการแจ้งเตือนว่าคำขอถูกปฏิเสธ',
                        icon: 'warning',
                        confirmButtonText: 'ปฏิเสธ',
                    };

        const result = await window.Swal.fire({
            ...prompt,
            showCancelButton: true,
            cancelButtonText: 'ยกเลิก',
        });

        if (!result.isConfirmed) {
            return;
        }

        form.dataset.confirmed = '1';
        form.submit();
    });

    const requested = new URL(window.location.href).searchParams.get('tab');
    if (requested && page.querySelector(`[data-shares-panel="${requested}"]`)) {
        showTab(requested);
    }
}
