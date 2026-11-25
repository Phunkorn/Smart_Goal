@php
    $appearanceErrors = $errors->getBag('appearance');
    $coverSettings = config('profile-appearance.cover');
    $appearanceFrame = \App\Support\ProfileAppearance::previewFrame($user, $appearanceErrors->any() ? old('avatar_frame') : null);
@endphp
<x-account-modal id="settingsAppearanceModal" title="ตกแต่งโปรไฟล์" size="lg" :data-open-on-load="$appearanceErrors->any() ? 'true' : null">
    <form action="{{ route('settings.appearance.update') }}" method="POST" enctype="multipart/form-data"
        class="settings-form settings-appearance" data-account-form="appearance" data-profile-user="{{ $user->id }}">
        @csrf
        @method('PATCH')
        <div class="modal-body">
            <div class="alert {{ $appearanceErrors->any() ? 'alert-danger' : 'd-none' }}" role="status" aria-live="polite" data-account-feedback>{{ $appearanceErrors->first() }}</div>
            <div class="row g-4">
                <div class="col-md-5">
                    <p class="form-label" id="settingsAppearancePreviewLabel">ตัวอย่างโปรไฟล์</p>
                    <div class="settings-appearance__preview" aria-labelledby="settingsAppearancePreviewLabel" data-appearance-preview>
                        <x-profile-cover :user="$user" />
                        <div class="settings-appearance__identity">
                            @include('settings.components.frame-avatar', ['frame' => $appearanceFrame])
                            <strong data-account-name>{{ $user->name }}</strong>
                            <small>{{ \App\Support\RoleLabel::for($user) }} · {{ $user->department?->department_name }}</small>
                        </div>
                    </div>
                    <p class="settings-form__help">ภาพปกจะแสดงเต็มพื้นที่โดยครอปจากกึ่งกลาง</p>
                </div>
                <div class="col-md-7">
                    <label for="settingsProfileCover" class="form-label">ภาพปกโปรไฟล์</label>
                    <div class="settings-upload" data-profile-image-picker>
                        <input id="settingsProfileCover" type="file" name="profile_cover" class="settings-upload__input"
                            accept="{{ implode(',', array_map(fn ($type) => 'image/'.($type === 'jpg' ? 'jpeg' : $type), array_unique(array_filter($coverSettings['mime_types'], fn ($type) => $type !== 'jpeg')))) }}"
                            aria-describedby="settingsProfileCoverHelp" data-profile-image-input data-appearance-cover
                            data-max-bytes="{{ $coverSettings['max_kilobytes'] * 1024 }}">
                        <i class="bi bi-cloud-arrow-up settings-upload__icon" aria-hidden="true"></i>
                        <span class="settings-upload__text" data-profile-image-name>เลือกรูปภาพ หรือลากไฟล์มาวางที่นี่</span>
                    </div>
                    <p id="settingsProfileCoverHelp" class="settings-form__help">ภาพนิ่ง JPG, PNG หรือ WebP ไม่เกิน {{ $coverSettings['max_kilobytes'] / 1024 }} MB, {{ $coverSettings['max_dimension'] }} พิกเซลต่อด้าน และ {{ $coverSettings['max_pixels'] / 1_000_000 }} ล้านพิกเซล</p>
                    <div class="form-check mt-2 mb-3">
                        <input class="form-check-input" type="checkbox" name="remove_profile_cover" value="1" id="settingsRemoveProfileCover" data-appearance-remove>
                        <label class="form-check-label" for="settingsRemoveProfileCover">ลบภาพปกและใช้พื้นหลังเริ่มต้น</label>
                    </div>
                    <input id="settingsAvatarFrame" type="hidden" name="avatar_frame" value="{{ $appearanceFrame?->slug ?? 'none' }}" data-appearance-frame
                        data-saved-frame="{{ $user->avatarFrame?->slug ?? 'none' }}"
                        data-saved-frame-name="{{ $user->avatarFrame?->name ?? 'ไม่ใช้กรอบ' }}"
                        data-saved-frame-asset="{{ $user->avatarFrame?->asset_path }}"
                        data-saved-frame-poster="{{ $user->avatarFrame?->preview_path }}">
                    <div class="settings-appearance__frame">
                        @include('settings.components.frame-avatar', ['frame' => $appearanceFrame])
                        <div class="settings-appearance__frame-text">
                            <span class="form-label d-block mb-0">กรอบโปรไฟล์</span>
                            <strong data-appearance-frame-name aria-live="polite">{{ $appearanceFrame?->name ?? 'ไม่ใช้กรอบ' }}</strong>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-account-open="settingsFrameGalleryModal"
                            data-account-modal-url="{{ route('settings.appearance.frames') }}">เลือกกรอบโปรไฟล์</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-primary settings-primary-button"><i class="bi bi-save" aria-hidden="true"></i>บันทึกการตกแต่ง</button>
        </div>
    </form>
</x-account-modal>
