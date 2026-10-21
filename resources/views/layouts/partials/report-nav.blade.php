<details class="report-nav{{ request()->routeIs('reports.operational*', 'reports.projects*') ? ' report-nav--active' : '' }}" {{ request()->routeIs('reports.*') ? 'open' : '' }}>
    <summary class="nav-item report-nav__toggle">
        <i class="bi bi-clipboard-data" aria-hidden="true"></i>
        <span class="nav-item__label">รายงาน</span>
        <i class="bi bi-chevron-down report-nav__chevron" aria-hidden="true"></i>
    </summary>
    <div class="report-nav__submenu">
        @can('viewReport', \App\Models\WorkLog::class)
            <a href="{{ route('reports.operational') }}"
                class="nav-item report-nav__link {{ request()->routeIs('reports.operational*') ? 'active' : '' }}"
                @if(request()->routeIs('reports.operational*')) aria-current="{{ request()->routeIs('reports.operational') ? 'page' : 'location' }}" @endif>
                <span class="nav-item__label">รายงานปฏิบัติงาน</span>
            </a>
        @endcan
        <a href="{{ route('reports.projects') }}"
            class="nav-item report-nav__link {{ request()->routeIs('reports.projects*') ? 'active' : '' }}"
            @if(request()->routeIs('reports.projects*')) aria-current="{{ request()->routeIs('reports.projects') ? 'page' : 'location' }}" @endif>
            <span class="nav-item__label">รายงานโปรเจกต์</span>
        </a>
    </div>
</details>
