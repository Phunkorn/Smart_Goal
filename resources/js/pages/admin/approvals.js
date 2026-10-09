document.querySelectorAll('[data-approval-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const approved = ['approved', 'accepted'].includes(form.dataset.decision);
        const topic = form.dataset.topic || 'งานนี้';
        const result = await window.Swal.fire({
            icon: approved ? 'question' : 'warning',
            title: approved ? 'อนุมัติการมอบหมายงาน?' : 'ปฏิเสธการมอบหมายงาน?',
            text: `${topic} — การตัดสินใจนี้จะเปลี่ยนสถานะคำขอทันที`,
            showCancelButton: true,
            confirmButtonText: approved ? 'อนุมัติ' : 'ปฏิเสธ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: approved ? '#198754' : '#dc3545',
            reverseButtons: true,
        });

        if (result.isConfirmed) {
            form.submit();
        }
    });
});

/*
 * ?approval_queue= ถูกกรองที่ server แล้ว (AdminApprovalController) หน้าจอแสดงคิวเดียวอยู่แล้ว
 * สิ่งที่เหลือให้ฝั่ง client คือเลื่อนแถบแท็บให้แท็บที่เลือกอยู่ในสายตาบนจอแคบ
 */
document.querySelector('.admin-approvals-tabs [aria-current="page"]')
    ?.scrollIntoView({block: 'nearest', inline: 'center'});

/*
 * ค้นหาและกรองในหน้าติดตาม "งานข้ามแผนกของทีม"
 *
 * server ส่งรายการที่หัวหน้าแผนกมีสิทธิ์เห็นมาครบแล้ว (AdminApprovalQuery::outgoingCollaborations)
 * ที่นี่จึงเป็นเรื่องการอ่านล้วน ๆ ไม่ใช่เรื่องสิทธิ์ และไม่ต้องยิง request ใหม่
 *
 * ซ่อนด้วย hidden ไม่ใช่ display inline เพื่อให้ CSS เป็นเจ้าของ layout ที่เดียว
 * การ์ดของคนที่ไม่เหลืองานที่ตรงเงื่อนไขจะถูกซ่อนทั้งใบ และตัวเลข "x งาน" ของแต่ละคน
 * นับเฉพาะงานที่ยังแสดงอยู่ ไม่งั้นหัวการ์ดจะโกหกว่ามีงานมากกว่าที่เห็น
 */
const crossDeptList = document.querySelector('[data-cross-dept-list]');

if (crossDeptList) {
    const searchInput = document.querySelector('[data-cross-dept-search]');
    const filterButtons = [...document.querySelectorAll('[data-cross-dept-filter]')];
    const members = [...crossDeptList.querySelectorAll('[data-cross-dept-member]')];
    const noResult = document.querySelector('[data-cross-dept-no-result]');
    let activeBucket = 'all';

    const applyCrossDeptFilters = () => {
        const term = (searchInput?.value || '').trim().toLowerCase();
        let visible = 0;

        members.forEach((member) => {
            // ค้นหาชื่อคนแล้วต้องเห็นงานของเขาทั้งหมด ไม่ใช่เฉพาะงานที่ชื่อมีคำนั้น
            const memberMatches = term !== '' && (member.dataset.search || '').includes(term);
            let shown = 0;

            member.querySelectorAll('[data-cross-dept-task]').forEach((task) => {
                const matches = (activeBucket === 'all' || task.dataset.bucket === activeBucket)
                    && (term === '' || memberMatches || (task.dataset.search || '').includes(term));

                task.hidden = !matches;
                shown += matches ? 1 : 0;
            });

            member.hidden = shown === 0;
            visible += shown;

            const count = member.querySelector('[data-cross-dept-count]');

            if (count) {
                count.textContent = `${shown} งาน`;
            }
        });

        if (noResult) {
            noResult.hidden = visible > 0;
        }
    };

    filterButtons.forEach((button) => button.addEventListener('click', () => {
        activeBucket = button.dataset.crossDeptFilter;

        filterButtons.forEach((other) => {
            const isActive = other === button;

            other.classList.toggle('is-active', isActive);
            other.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        applyCrossDeptFilters();
    }));

    searchInput?.addEventListener('input', applyCrossDeptFilters);
}
