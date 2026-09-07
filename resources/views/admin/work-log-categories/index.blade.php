@extends('layouts.app')

@section('title', 'หมวดงานประจำวัน')

@push('styles')
    @vite('resources/css/pages/daily-logs.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/daily-logs/categories.js')
@endpush

@section('content')
{{--
    จัดการหมวดงานของบันทึกงานประจำวัน

    หมวดงานเป็นตาราง lookup เพราะแต่ละองค์กรมีหมวดต่างกันและจะเพิ่มเรื่อย ๆ
    การให้ admin เพิ่มเองได้จากหน้านี้ ทำให้ไม่ต้องเขียน migration และ deploy ใหม่
    ทุกครั้งที่อยากเพิ่มหมวดหนึ่งหมวด
--}}
<div class="routine-page" data-category-page>
    <header class="routine-page__header">
        <div>
            <div class="routine-page__breadcrumb">
                <span>ระบบ</span>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span>หมวดงานประจำวัน</span>
            </div>
            <h1 class="routine-page__title">หมวดงานประจำวัน</h1>
            <p class="routine-page__subtitle">
                หมวดที่พนักงานเลือกได้เมื่อบันทึกงานประจำวัน เช่น IT Support หรือ ซ่อมบำรุง
            </p>
        </div>
    </header>

    @if(session('success'))
        <p class="daily-log__notice" role="status">
            <i class="bi bi-check-circle" aria-hidden="true"></i> {{ session('success') }}
        </p>
    @endif

    @error('status')
        <p class="log-modal__error" role="alert">{{ $message }}</p>
    @enderror

    <div class="routine-page__body">
        <section class="routine-list" aria-labelledby="categoryListHeading">
            <h2 class="visually-hidden" id="categoryListHeading">หมวดงานที่มีอยู่</h2>

            @forelse($categories as $category)
                <article class="routine-card @unless($category->is_active) routine-card--inactive @endunless">
                    <form method="POST" action="{{ route('admin.work-log-categories.update', $category) }}"
                        class="category-row" data-category-form>
                        @csrf
                        @method('PATCH')

                        <div class="category-row__fields">
                            <label class="visually-hidden" for="categoryName{{ $category->id }}">ชื่อหมวด</label>
                            <input type="text" class="form-control category-row__name"
                                id="categoryName{{ $category->id }}" name="name"
                                value="{{ $category->name }}" maxlength="60" required>

                            <label class="visually-hidden" for="categoryTone{{ $category->id }}">โทนสี</label>
                            <select class="form-select category-row__tone" id="categoryTone{{ $category->id }}" name="tone">
                                @foreach($tones as $tone)
                                    <option value="{{ $tone }}" @selected($category->tone === $tone)>{{ $tone }}</option>
                                @endforeach
                            </select>

                            <label class="visually-hidden" for="categoryIcon{{ $category->id }}">ไอคอน</label>
                            <input type="text" class="form-control category-row__icon"
                                id="categoryIcon{{ $category->id }}" name="icon"
                                value="{{ $category->icon }}" maxlength="40" placeholder="bi-headset">

                            <label class="visually-hidden" for="categoryOrder{{ $category->id }}">ลำดับ</label>
                            <input type="number" class="form-control category-row__order"
                                id="categoryOrder{{ $category->id }}" name="sort_order"
                                value="{{ $category->sort_order }}" min="0" max="9999">

                            <label class="category-row__active">
                                <input type="checkbox" name="is_active" value="1" @checked($category->is_active)>
                                <span>เปิดใช้งาน</span>
                            </label>
                        </div>

                        <div class="category-row__meta">
                            <span class="log-chip log-chip--{{ $category->tone }}">
                                @if($category->icon)<i class="bi {{ $category->icon }}" aria-hidden="true"></i>@endif
                                {{ $category->name }}
                            </span>
                            <span class="category-row__usage">ใช้อยู่ {{ $category->logs_count }} รายการ</span>
                        </div>

                        <div class="category-row__actions">
                            <button type="submit" class="btn btn-outline-primary btn-sm">บันทึก</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('admin.work-log-categories.destroy', $category) }}"
                        class="routine-card__actions" data-category-delete
                        data-usage="{{ $category->logs_count }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="routine-card__delete" aria-label="ลบหมวด {{ $category->name }}">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                </article>
            @empty
                <p class="routine-list__empty">
                    <i class="bi bi-tags" aria-hidden="true"></i>
                    ยังไม่มีหมวดงาน — เพิ่มได้ที่ฟอร์มด้านขวา
                </p>
            @endforelse
        </section>

        <aside class="routine-form-panel">
            <form method="POST" action="{{ route('admin.work-log-categories.store') }}" class="routine-form">
                @csrf

                <h2 class="routine-form__heading">เพิ่มหมวดงาน</h2>

                <div class="log-field">
                    <label class="form-label" for="newCategoryName">ชื่อหมวด <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="newCategoryName" name="name"
                        maxlength="60" required placeholder="เช่น Network">
                    @error('name')<span class="log-field__hint">{{ $message }}</span>@enderror
                </div>

                <div class="log-field-row">
                    <div class="log-field">
                        <label class="form-label" for="newCategoryTone">โทนสี</label>
                        <select class="form-select" id="newCategoryTone" name="tone">
                            @foreach($tones as $tone)
                                <option value="{{ $tone }}" @selected($tone === 'gray')>{{ $tone }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="log-field">
                        <label class="form-label" for="newCategoryOrder">ลำดับ</label>
                        <input type="number" class="form-control" id="newCategoryOrder" name="sort_order"
                            value="0" min="0" max="9999">
                    </div>
                </div>

                <div class="log-field">
                    <label class="form-label" for="newCategoryIcon">ไอคอน (Bootstrap Icons)</label>
                    <input type="text" class="form-control" id="newCategoryIcon" name="icon"
                        maxlength="40" placeholder="bi-headset">
                    @error('icon')<span class="log-field__hint">{{ $message }}</span>@enderror
                </div>

                <p class="routine-form__hint">
                    หมวดที่มีบันทึกงานใช้อยู่จะลบไม่ได้ — ให้ปิดใช้งานแทนเพื่อเก็บประวัติย้อนหลังไว้
                </p>

                <button type="submit" class="btn btn-primary routine-form__submit">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มหมวดงาน
                </button>
            </form>
        </aside>
    </div>
</div>
@endsection
