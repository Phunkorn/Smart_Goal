@extends('layouts.app')

@section('title', 'แจ้งปัญหา IT')

@push('styles')
    @vite('resources/css/pages/tickets.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/tickets/form.js')
@endpush

@section('content')
<div class="ticket-page ticket-page--form">
    <header class="page-head">
        <div>
            <span class="eyebrow">Smart Ticket</span>
            <h1>แจ้งปัญหา IT</h1>
            <p>แจ้งปัญหาคอมพิวเตอร์ ระบบ หรืออุปกรณ์ ทีม IT จะได้รับเรื่องของคุณทันที</p>
        </div>
    </header>

    <form class="ticket-form panel" method="POST" action="{{ route('tickets.store') }}"
        enctype="multipart/form-data" data-ticket-form>
        @csrf
        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">

        @include('tickets.components.form-alert')

        @include('tickets.components.requester-summary')

        @include('tickets.components.form-fields', ['locations' => $locations])
        @include('tickets.components.submit-button', ['busyLabel' => 'กำลังส่งคำขอ...'])
    </form>
</div>
@endsection
