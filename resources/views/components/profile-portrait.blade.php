@props(['frame' => null])
<span {{ $attributes->class(['sg-profile-portrait']) }}
    data-profile-frame="{{ $frame?->slug ?? 'none' }}"
    data-profile-frame-asset="{{ $frame?->asset_path }}"
    data-profile-frame-poster="{{ $frame?->preview_path }}">
    {{ $slot }}
    <img class="sg-profile-portrait__overlay" data-profile-frame-overlay alt="" aria-hidden="true" decoding="async" hidden>
</span>
