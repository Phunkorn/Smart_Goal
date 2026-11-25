<x-profile-portrait :frame="$frame">
    <span class="settings-appearance__avatar" data-account-avatar>
        @if($user->profile_image)<span>{{ \App\Support\WorkBoardDesign::initials($user->name) }}</span>@endif
        @include('components.user-avatar-content', ['user' => $user, 'avatarLength' => 2, 'avatarUpper' => true])
    </span>
</x-profile-portrait>
