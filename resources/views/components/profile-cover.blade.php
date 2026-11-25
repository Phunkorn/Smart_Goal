@props(['user', 'priority' => false])
@php($coverUrl = \App\Support\ProfileAppearance::coverUrl($user))
<div {{ $attributes->class(['sg-profile-cover']) }} data-profile-cover data-cover-url="{{ $coverUrl }}"
    @if($priority) data-priority-cover @endif aria-hidden="true">
    @if($coverUrl)
        <img src="{{ $coverUrl }}" alt="" loading="{{ $priority ? 'eager' : 'lazy' }}"
            @if($priority) fetchpriority="high" @endif data-profile-cover-image>
    @endif
</div>
