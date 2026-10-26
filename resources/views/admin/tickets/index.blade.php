@extends('layouts.app')

@section('title', 'ตั้งค่า Smart Ticket')

@push('styles')
    @vite('resources/css/pages/tickets.css')
@endpush

@section('content')
@php
    use App\Http\Requests\Tickets\Settings\UpdateTicketAgentRequest;
    use App\Services\TicketSubmissionService;

    $responsibleCount = $departments->where('ticket_responsible', true)->count();
    $agentCount = $agents->where('is_agent', true)->count();
    $sourceLabels = ['department' => 'ตามแผนก', 'granted' => 'เปิดรายคน', 'revoked' => 'ปิดรายคน'];
    $accessLabels = ['department' => 'ตามแผนก', 'granted' => 'เปิดสิทธิ์รายคน', 'revoked' => 'ปิดสิทธิ์รายคน'];
@endphp
{{--
    ทุกการตั้งค่าเป็น form จริงที่ส่งไป TicketSettingsController (ตรวจ TicketPolicy::manageSettings
    และเขียน AuditTrail ที่ TicketSettingsService) หน้านี้จึงไม่มี JavaScript ของตัวเอง
--}}
<div class="ticket-page ticket-settings">
    <header class="page-head">
        <div>
            <span class="eyebrow">Smart Ticket</span>
            <h1>ตั้งค่า Smart Ticket</h1>
            <p>จัดการผู้รับผิดชอบ Ticket สิทธิ์รายคน และสาขาที่ใช้ในฟอร์มแจ้งปัญหา</p>
        </div>
    </header>

    @include('tickets.components.flash')
    @if ($errors->any() && ! $errors->has('status'))
        <div class="ticket-flash ticket-flash--error" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <div class="panel ticket-panel ticket-settings__workspace">
        <div class="ticket-settings__tabs" role="tablist" aria-label="ส่วนการตั้งค่า Smart Ticket">
            <button class="ticket-settings__tab active" id="settings-agents-tab" type="button" role="tab"
                data-bs-toggle="tab" data-bs-target="#settings-agents" aria-controls="settings-agents" aria-selected="true">
                <i class="bi bi-people" aria-hidden="true"></i> เจ้าหน้าที่ <span>{{ $agentCount }}</span>
            </button>
            <button class="ticket-settings__tab" id="settings-departments-tab" type="button" role="tab"
                data-bs-toggle="tab" data-bs-target="#settings-departments" aria-controls="settings-departments" aria-selected="false">
                <i class="bi bi-building" aria-hidden="true"></i> แผนกรับผิดชอบ <span>{{ $responsibleCount }}</span>
            </button>
            <button class="ticket-settings__tab" id="settings-locations-tab" type="button" role="tab"
                data-bs-toggle="tab" data-bs-target="#settings-locations" aria-controls="settings-locations" aria-selected="false">
                <i class="bi bi-geo-alt" aria-hidden="true"></i> สาขา/สถานที่ <span>{{ $locations->where('is_active', true)->count() }}</span>
            </button>
        </div>
        <div class="ticket-settings__content tab-content">
    {{-- 1. แผนกที่รับผิดชอบ --}}
    <section class="ticket-settings__section ticket-settings__section--departments tab-pane fade" id="settings-departments" role="tabpanel" aria-labelledby="settings-departments-tab" tabindex="0">
        <h2 class="ticket-panel__title">
            <span class="ticket-panel__icon"><i class="bi bi-building" aria-hidden="true"></i></span>
            แผนกที่รับผิดชอบ Ticket <span class="ticket-panel__count">{{ $responsibleCount }}</span>
        </h2>
        <p class="ticket-panel__hint">เปิดให้สมาชิกที่ใช้งานอยู่ในแผนกรับ Ticket อัตโนมัติ และปรับสิทธิ์รายคนได้ด้านล่าง</p>

        @if ($responsibleCount === 0)
            <div class="ticket-flash ticket-flash--warning" role="note">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>ยังไม่ได้เลือกแผนกที่รับผิดชอบ — เลือกแผนก IT ของคุณด้านล่าง หรือเพิ่มเจ้าหน้าที่รายบุคคล</span>
            </div>
        @endif

        @if ($departments->isEmpty())
            <p class="ticket-panel__empty">ยังไม่มีแผนกในระบบ</p>
        @else
            <ul class="ticket-settings__list">
                @foreach ($departments as $department)
                    <li class="ticket-settings__row">
                        <span class="ticket-settings__identity">
                            <strong>{{ $department->department_name }}</strong>
                            <small>สมาชิกที่ใช้งานอยู่ {{ number_format($department->active_members_count) }} คน</small>
                        </span>
                        <form method="POST" action="{{ route('admin.tickets.departments.update', $department) }}">
                            @csrf
                            @method('PATCH')
                            {{-- ส่งค่าที่ "ต้องการให้เป็น" ไม่ใช่ค่าปัจจุบัน (รูปแบบเดียวกับหน้าจัดการแผนก) --}}
                            <input type="hidden" name="ticket_responsible" value="{{ $department->ticket_responsible ? '0' : '1' }}">
                            <button type="submit" class="ticket-switch {{ $department->ticket_responsible ? 'is-on' : '' }}"
                                role="switch" aria-checked="{{ $department->ticket_responsible ? 'true' : 'false' }}"
                                aria-label="แผนก {{ $department->department_name }} รับผิดชอบ Ticket">
                                <span class="ticket-switch__track" aria-hidden="true"></span>
                                <span class="ticket-switch__text">{{ $department->ticket_responsible ? 'รับผิดชอบ' : 'ไม่รับผิดชอบ' }}</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 2. เจ้าหน้าที่รายบุคคลและการแจ้งเตือน --}}
    <section class="ticket-settings__section ticket-settings__section--agents tab-pane fade show active" id="settings-agents" role="tabpanel" aria-labelledby="settings-agents-tab" tabindex="0">
        <h2 class="ticket-panel__title">
            <span class="ticket-panel__icon"><i class="bi bi-people" aria-hidden="true"></i></span>
            เจ้าหน้าที่ Ticket <span class="ticket-panel__count">{{ $agentCount }}</span>
        </h2>
        <p class="ticket-panel__hint">ดูว่าใครรับ Ticket ได้ และกำหนดสิทธิ์หรือการแจ้งเตือนของแต่ละคน สิทธิ์รายคนมีผลเหนือค่าแผนก</p>

        @if ($candidates->isNotEmpty())
            <form class="ticket-settings__add" method="POST" action="{{ route('admin.tickets.agents.store') }}">
                @csrf
                <label class="ticket-form__label" for="settings-add-agent">เพิ่มเจ้าหน้าที่</label>
                <div class="ticket-settings__add-row">
                    <select id="settings-add-agent" name="user_id" class="form-select" required>
                        <option value="">เลือกผู้ใช้</option>
                        @foreach ($candidates as $departmentName => $people)
                            <optgroup label="{{ $departmentName }}">
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <button type="submit" class="ticket-button ticket-button--primary">
                        <i class="bi bi-person-plus" aria-hidden="true"></i> เพิ่ม
                    </button>
                </div>
            </form>
        @endif

        @if ($agents->isEmpty())
            <p class="ticket-panel__empty">ยังไม่มีเจ้าหน้าที่ — เลือกแผนกที่รับผิดชอบ หรือเพิ่มรายบุคคลด้านบน</p>
        @else
            <div class="ticket-settings__table-wrap">
                <table class="ticket-settings__table">
                    <thead>
                        <tr>
                            <th scope="col">ชื่อและแผนก</th>
                            <th scope="col">การเข้าถึง Ticket</th>
                            <th scope="col">แจ้งเตือน Ticket ใหม่</th>
                            <th scope="col"><span class="visually-hidden">บันทึกการเปลี่ยนแปลง</span></th>
                        </tr>
                    </thead>
                    <tbody>
                @foreach ($agents as $agent)
                    @php($person = $agent['user'])
                    <tr>
                        <td class="ticket-settings__agent-name">
                            <strong>{{ $person->name }}</strong>
                            <small>{{ $person->department?->department_name ?? 'ไม่มีแผนก' }}</small>
                            <span class="ticket-settings__agent-meta {{ $agent['is_agent'] ? 'is-active' : '' }}">
                                {{ $agent['is_agent'] ? 'รับ Ticket ได้' : 'ยังไม่รับ Ticket' }} · {{ $sourceLabels[$agent['source']] }}
                            </span>
                        </td>
                        <td class="ticket-settings__agent-access" data-label="การเข้าถึง Ticket">
                                <input type="hidden" name="receives_alerts" value="0" form="agent-form-{{ $person->id }}">
                                <label class="visually-hidden" for="agent-access-{{ $person->id }}">สิทธิ์ของ {{ $person->name }}</label>
                                <select id="agent-access-{{ $person->id }}" name="access" class="form-select" form="agent-form-{{ $person->id }}">
                                    @foreach (array_keys(UpdateTicketAgentRequest::ACCESS) as $access)
                                        <option value="{{ $access }}" @selected($agent['source'] === $access)>{{ $accessLabels[$access] }}</option>
                                    @endforeach
                                </select>
                        </td>
                        <td class="ticket-settings__agent-alerts">
                            <label class="ticket-settings__check">
                                <input class="form-check-input" type="checkbox" name="receives_alerts" value="1" form="agent-form-{{ $person->id }}" @checked($agent['receives_alerts'])>
                                <span>รับแจ้งเตือน</span>
                            </label>
                        </td>
                        <td class="ticket-settings__agent-action">
                            <form id="agent-form-{{ $person->id }}" method="POST" action="{{ route('admin.tickets.agents.update', $person) }}">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="ticket-button ticket-button--outline">บันทึก</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- 3. สาขา/สถานที่ --}}
    <section class="ticket-settings__section ticket-settings__section--locations tab-pane fade" id="settings-locations" role="tabpanel" aria-labelledby="settings-locations-tab" tabindex="0">
        <h2 class="ticket-panel__title">
            <span class="ticket-panel__icon"><i class="bi bi-geo-alt" aria-hidden="true"></i></span>
            สาขา/สถานที่ <span class="ticket-panel__count">{{ $locations->where('is_active', true)->count() }}</span>
        </h2>
        <p class="ticket-panel__hint">สาขาที่เปิดใช้งานจะแสดงในฟอร์มแจ้งปัญหา ปิดใช้งานเพื่อเก็บประวัติ Ticket เดิม</p>

        <form class="ticket-settings__add" method="POST" action="{{ route('admin.tickets.locations.store') }}">
            @csrf
            <label class="ticket-form__label" for="settings-add-location">เพิ่มสาขา/สถานที่</label>
            <div class="ticket-settings__add-row">
                <input id="settings-add-location" type="text" name="name" class="form-control" required
                    maxlength="{{ TicketSubmissionService::NAME_MAX }}" placeholder="เช่น สาขาบางนา">
                <button type="submit" class="ticket-button ticket-button--primary">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่ม
                </button>
            </div>
        </form>

        @if ($locations->isEmpty())
            <p class="ticket-panel__empty">ยังไม่มีรายการสาขา — ฟอร์มแจ้งปัญหาจะให้ผู้แจ้งพิมพ์สถานที่เอง</p>
        @else
            <ul class="ticket-settings__list">
                @foreach ($locations as $location)
                    <li class="ticket-settings__row">
                        <form class="ticket-settings__location-form" method="POST" action="{{ route('admin.tickets.locations.update', $location) }}">
                            @csrf
                            @method('PATCH')
                            <label class="visually-hidden" for="location-name-{{ $location->id }}">ชื่อสาขา</label>
                            <input id="location-name-{{ $location->id }}" type="text" name="name" class="form-control" required
                                value="{{ $location->name }}" maxlength="{{ TicketSubmissionService::NAME_MAX }}">
                            <input type="hidden" name="is_active" value="0">
                            <label class="ticket-settings__check">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($location->is_active)>
                                เปิดใช้งาน
                            </label>
                            <button type="submit" class="ticket-button ticket-button--outline">บันทึก</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
        </div>
    </div>
</div>
@endsection
