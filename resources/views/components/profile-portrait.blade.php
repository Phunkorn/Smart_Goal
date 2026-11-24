@props(['user'])
<span {{ $attributes->class(['sg-profile-portrait']) }} data-profile-frame="{{ \App\Support\ProfileAppearance::frame($user) }}">
    {{ $slot }}
</span>
