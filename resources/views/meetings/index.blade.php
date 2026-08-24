@extends('layouts.app')
@section('title', 'การประชุม')

@section('content')
{{-- เนื้อหาทั้งหมดอยู่ใน partial ร่วม เพื่อให้ view "ประชุม" ในหน้างานของฉันใช้ซ้ำได้ --}}
@include('meetings.components.meeting-list')
@endsection
