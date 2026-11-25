@php($directoryTheme = \App\Support\WorkBoardDesign::departmentDirectoryTheme($department))

<a class="wb-department-card wb-department-card--{{ $directoryTheme }}"
    href="{{ route('work-board.department', $department) }}"
    aria-labelledby="wb-department-name-{{ $department->getKey() }}"
    aria-describedby="wb-department-members-{{ $department->getKey() }}">
    <div class="wb-department-card__visual" aria-hidden="true">
        <span class="wb-department-card__eyebrow">Department</span>
        <span class="wb-department-card__display-name">{{ $department->department_name }}</span>
        <span class="wb-department-card__artwork"></span>
    </div>
    <div class="wb-department-card__foot">
        <div class="wb-department-card__identity">
            <h3 id="wb-department-name-{{ $department->getKey() }}">{{ $department->department_name }}</h3>
            <p class="wb-department-card__members" id="wb-department-members-{{ $department->getKey() }}">
                <i class="bi bi-people" aria-hidden="true"></i>
                <span>{{ number_format($department->member_count) }} สมาชิก</span>
            </p>
        </div>
        <span class="wb-department-card__arrow" aria-hidden="true"><i class="bi bi-arrow-up-right"></i></span>
    </div>
</a>
