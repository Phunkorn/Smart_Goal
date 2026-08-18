@php($acceptedPeople = $task->collaborators->where('pivot.status', 'accepted')->values())
@if($acceptedPeople->isNotEmpty())
    <span class="mytasks-kanban__collaborators" title="ผู้ร่วมงาน: {{ $acceptedPeople->pluck('name')->join(', ') }}">
        <i class="bi bi-people"></i>{{ $acceptedPeople->count() }}
    </span>
@endif
