{{--
    เหตุผลที่ล่าช้าหรือเกินกำหนด จัดกลุ่มตามข้อความเหตุผล
    Overview แสดง 5 อันดับ หน้าฉบับเต็มแสดงทุกกลุ่มเหนือรายการเหตุการณ์
--}}
<div class="operational-table-scroll">
    <table class="operational-table operational-table--reasons" data-operational-reason-table>
        <caption class="visually-hidden">เหตุผลที่ล่าช้าหรือเกินกำหนด เรียงตามจำนวนครั้ง</caption>
        <thead>
            <tr>
                <th scope="col">สาเหตุ</th>
                <th scope="col" class="is-number">จำนวนครั้ง</th>
            </tr>
        </thead>
        <tbody>
            @forelse($groups as $group)
                <tr data-operational-reason-row>
                    <th scope="row" data-label="สาเหตุ">
                        {{ $group['reason'] }}
                        <small>{{ implode(' · ', $group['types']) }}</small>
                    </th>
                    <td data-label="จำนวนครั้ง" class="is-number"><span class="operational-count">{{ number_format($group['count']) }}</span></td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" class="operational-table__empty">ไม่มีงานที่ล่าช้าหรือเกินกำหนดในเดือนนี้</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
