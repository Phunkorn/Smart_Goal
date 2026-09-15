{{--
    กล่องรายละเอียดหลักฐานผลงาน — มีใบเดียวต่อหน้า เนื้อในคัดลอกจาก template ของแถวที่กด
    (resources/js/components/evidence-modal.js) ลำดับชั้นและโฟกัสเป็นหน้าที่ของ modal-stack
--}}
<div class="subtask-modal project-report__evidence-modal" role="dialog" aria-modal="true"
    aria-labelledby="projectEvidenceTitle" data-evidence-modal hidden>
    <div class="subtask-modal__panel project-report__evidence-panel">
        <button type="button" class="subtask-modal__close project-report__evidence-close" data-evidence-modal-close aria-label="ปิด">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
        <div class="project-report__evidence-content" data-evidence-modal-content></div>
    </div>
</div>
