@php($avatarSize = $size ?? 'md')
<span class="wb-avatar wb-avatar--{{ $avatarSize }}" title="{{ $user->name }}">
    @if($user->profile_image)
        <img src="{{ route('media.profile', $user) }}" alt="{{ $user->name }}">
    @else
        {{ \App\Support\WorkBoardDesign::initials($user->name) }}
    @endif
</span>
