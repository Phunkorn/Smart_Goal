@php
    $appearanceErrors = $errors->getBag('appearance');
    $appearanceFrame = $appearanceErrors->any() ? old('avatar_frame', \App\Support\ProfileAppearance::frame($user)) : \App\Support\ProfileAppearance::frame($user);
    $coverSettings = config('profile-appearance.cover');
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
                            <x-profile-portrait :user="$user">
                                <span class="settings-appearance__avatar" data-account-avatar>
                                    @if($user->profile_image)<span>{{ \App\Support\WorkBoardDesign::initials($user->name) }}</span>@endif
                                    @include('components.user-avatar-content', ['user' => $user, 'avatarLength' => 2, 'avatarUpper' => true])
                                </span>
                            </x-profile-portrait>
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
                    <p id="settingsProfileCoverHelp" class="settings-form__help">{{ strtoupper(implode(', ', $coverSettings['mime_types'])) }} ไม่เกิน {{ $coverSettings['max_kilobytes'] / 1024 }} MB และ {{ $coverSettings['max_dimension'] }} พิกเซลต่อด้าน</p>
                    <div class="form-check mt-2 mb-3">
                        <input class="form-check-input" type="checkbox" name="remove_profile_cover" value="1" id="settingsRemoveProfileCover" data-appearance-remove>
                        <label class="form-check-label" for="settingsRemoveProfileCover">ลบภาพปกและใช้พื้นหลังเริ่มต้น</label>
                    </div>
                    <label for="settingsAvatarFrame" class="form-label">กรอบโปรไฟล์</label>
                    <select id="settingsAvatarFrame" name="avatar_frame" class="form-select" data-appearance-frame>
                        <option value="none" @selected($appearanceFrame === 'none')>ไม่ใช้กรอบ</option>
                        @foreach(collect(\App\Support\ProfileAppearance::frames())->groupBy('animated', true) as $animated => $frames)
                            <optgroup label="{{ $animated ? 'กรอบเคลื่อนไหว' : 'กรอบปกติ' }}">
                                @foreach($frames as $key => $frame)
                                    <option value="{{ $key }}" @selected($appearanceFrame === $key)>{{ $frame['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="settings-form__help">กรอบเคลื่อนไหวจะแสดงเป็นภาพนิ่งเมื่ออุปกรณ์ตั้งค่าลดการเคลื่อนไหว</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-primary settings-primary-button"><i class="bi bi-save" aria-hidden="true"></i>บันทึกการตกแต่ง</button>
        </div>
    </form>
</x-account-modal>
