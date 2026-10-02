<article class="report-choice report-choice--{{ $tone }}">
    <div class="report-choice__visual" aria-hidden="true">
        <span class="report-choice__icon"><i class="bi {{ $icon }}"></i></span>
        <span class="report-choice__graphic"><i></i><i></i><i></i><i></i></span>
    </div>
    <div class="report-choice__content">
        <h2>{{ $title }}</h2>
        <p>{{ $description }}</p>
        @if(count($features))
            <ul>
                @foreach($features as $feature)
                    <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>{{ $feature }}</span></li>
                @endforeach
            </ul>
        @endif
    </div>
    <a href="{{ $route }}" class="report-choice__cta">
        <span>{{ $cta }}</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
    </a>
</article>
