{{--
    Toast แจ้งผลลัพธ์การสร้างโปรเจกต์/มอบหมายงาน (SweetAlert2 มุมขวาบน)
    ใช้ร่วมกันโดยหน้าที่มีโมดัลมอบหมายงาน: Admin Board Overview และ Admin Member Workspace
--}}
@if (session('success') || $errors->any())
<script>
    (function () {
        const Toast = window.Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toastEl) => {
                toastEl.addEventListener('mouseenter', window.Swal.stopTimer);
                toastEl.addEventListener('mouseleave', window.Swal.resumeTimer);
            },
        });

        @if (session('success'))
            Toast.fire({icon: 'success', title: @json(session('success'))});
        @endif

        @if ($errors->any())
            Toast.fire({icon: 'error', title: @json($errors->first())});
        @endif
    })();
</script>
@endif
