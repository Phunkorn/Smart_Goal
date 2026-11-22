@extends('layouts.app')
@section('title', 'ตั้งค่าบัญชี')
@section('content')
<div class="settings-page">
    <header class="settings-page__header">
        <h1>ตั้งค่าบัญชี</h1>
        <p>จัดการข้อมูลส่วนตัว ความปลอดภัย และการแจ้งเตือนจากเมนูบัญชีบนแถบด้านบน</p>
    </header>
    <button type="button" class="btn btn-primary" data-account-open="settingsModal">เปิดการเชื่อม Telegram</button>
</div>
@endsection
