@php($openProfileModal = $errors->hasAny(['name', 'phone', 'profile_image']))
<x-account-modal id="settingsProfileModal" title="แก้ไขโปรไฟล์" size="lg" :data-open-on-load="$openProfileModal ? 'true' : null">
    <form action="{{ route('settings.update') }}" method="POST" enctype="multipart/form-data" class="settings-form" data-account-form="profile">
        @csrf
        @method('PATCH')

        <div class="modal-body">
            <div class="alert d-none" role="status" aria-live="polite" data-account-feedback></div>
            <div class="row g-3">
                <div class="col-sm-6">
                    <label for="settingsName" class="form-label">ชื่อ</label>
                    <input id="settingsName" type="text" name="name" value="{{ request()->routeIs('settings.index') ? old('name', $user->name) : $user->name }}"
                        class="form-control @error('name') is-invalid @enderror" autocomplete="name" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6">
                    <label for="settingsPhone" class="form-label">เบอร์โทรศัพท์</label>
                    <input id="settingsPhone" type="tel" name="phone" value="{{ request()->routeIs('settings.index') ? old('phone', $user->phone) : $user->phone }}"
                        class="form-control @error('phone') is-invalid @enderror" autocomplete="tel">
                    @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="settingsProfileImage" class="form-label">รูปภาพโปรไฟล์</label>
                    {{-- input ไฟล์ตัวจริงคลุมทั้งกล่อง จึงคลิกเลือกหรือลากไฟล์มาวางได้ด้วยพฤติกรรมของเบราว์เซอร์เอง --}}
                    <div class="settings-upload @error('profile_image') is-invalid @enderror" data-profile-image-picker>
                        <input id="settingsProfileImage" type="file" name="profile_image"
                            class="settings-upload__input @error('profile_image') is-invalid @enderror"
                            accept="image/png,image/jpeg,image/webp" aria-describedby="settingsProfileImageHelp"
                            data-profile-image-input>
                        <span class="settings-upload__icon" aria-hidden="true"><i class="bi bi-cloud-arrow-up"></i></span>
                        <span class="settings-upload__text" data-profile-image-name>เลือกรูปภาพใหม่ หรือลากไฟล์มาวางที่นี่</span>
                    </div>
                    @error('profile_image')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <p id="settingsProfileImageHelp" class="settings-form__help">รองรับ JPG, PNG หรือ WebP ขนาดไม่เกิน 4 MB</p>
                </div>
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-primary settings-primary-button">
                <i class="bi bi-save" aria-hidden="true"></i>
                บันทึกข้อมูล
            </button>
        </div>
    </form>
</x-account-modal>
