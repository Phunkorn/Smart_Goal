{{--
    ช่องค้นหางานและโปรเจกต์บน Topbar — popover ไม่ใช่ modal: ไม่ล็อกการเลื่อนหน้า ไม่บังทั้งหน้า
    ปิดด้วยคลิกนอกกล่องหรือ Escape แล้วคืนโฟกัสให้ปุ่มเปิด เปิดด้วยปุ่มนี้หรือ Ctrl/⌘ + K

    สถานะเปิด/ปิด โฟกัส คีย์บอร์ด และการยิงคำค้น อยู่ที่ resources/js/components/global-search.js ที่เดียว
    ผลลัพธ์มาจาก route('search') (GlobalSearchService) ซึ่งจำกัดขอบเขตตามสิทธิ์ของผู้ใช้
--}}
<div class="global-search" data-global-search data-search-url="{{ route('search') }}"
    data-search-min-length="{{ \App\Services\GlobalSearchService::MIN_LENGTH }}">
    <button type="button" class="global-search__trigger" data-global-search-trigger
        aria-haspopup="dialog" aria-expanded="false" aria-controls="globalSearchPanel"
        aria-keyshortcuts="Control+K Meta+K" aria-label="ค้นหางานหรือโปรเจกต์">
        <i class="bi bi-search global-search__trigger-icon" aria-hidden="true"></i>
        <span class="global-search__placeholder" aria-hidden="true">ค้นหางานหรือโปรเจกต์...</span>
    </button>

    <div class="global-search__panel" id="globalSearchPanel" role="dialog" aria-modal="false"
        aria-label="ค้นหางานหรือโปรเจกต์" data-global-search-panel>
        <div class="global-search__field">
            <i class="bi bi-search global-search__field-icon" aria-hidden="true"></i>
            <input type="search" class="global-search__input" data-global-search-input
                role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="globalSearchResults"
                aria-label="คำค้นหา" placeholder="ค้นหางานหรือโปรเจกต์..."
                maxlength="{{ \App\Services\GlobalSearchService::MAX_LENGTH }}" autocomplete="off" spellcheck="false" enterkeyhint="go">
            <span class="global-search__spinner" data-global-search-loading aria-hidden="true"></span>
            <button type="button" class="global-search__clear" data-global-search-clear aria-label="ล้างคำค้นหา" hidden>
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>

        <div class="global-search__results" id="globalSearchResults" role="listbox" aria-label="ผลการค้นหา" data-global-search-results></div>

        <p class="global-search__message" data-global-search-message>ค้นจากชื่องาน เนื้อหางาน หรือชื่อโปรเจกต์</p>
        <p class="visually-hidden" role="status" aria-live="polite" data-global-search-status></p>

        <footer class="global-search__footer" aria-hidden="true">
            <span><kbd>↑</kbd><kbd>↓</kbd> เลือก</span>
            <span><kbd>Enter</kbd> เปิด</span>
            <span><kbd>Esc</kbd> ปิด</span>
        </footer>
    </div>
</div>
