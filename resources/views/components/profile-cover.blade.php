@props(['user'])
@php($coverUrl = \App\Support\ProfileAppearance::coverUrl($user))
<div {{ $attributes->class(['sg-profile-cover']) }} data-profile-cover data-cover-url="{{ $coverUrl }}" aria-hidden="true">
    @if($coverUrl)
        <img src="{{ $coverUrl }}" alt="" loading="lazy" data-profile-cover-image>
    @endif
</div>
