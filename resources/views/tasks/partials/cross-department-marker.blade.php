@if($crossDepartment ?? null)
    <span class="cross-department-marker is-{{ $crossDepartment['kind'] }}" data-cross-department-marker title="{{ $crossDepartment['label'] }}">
        <i class="bi bi-arrow-left-right" aria-hidden="true"></i><span>{{ $crossDepartment['label'] }}</span>
    </span>
@endif
