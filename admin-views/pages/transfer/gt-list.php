<?php
/**
 * Màn "Phiếu chuyển kho": nút Tạo phiếu chuyển kho, dải lọc (Trạng thái · Từ · Đến · Chi nhánh · Tìm nhanh), lưới phiếu.
 * Phiếu đang chờ xác nhận nền vàng, đã xác nhận nền xám. Bấm vào một dòng để mở phiếu (duyệt nhận / xoá làm trong phiếu).
 *
 * @package tgs-transfer-management
 */
if (!defined('ABSPATH')) {
    exit;
}
$gt_nonce = wp_create_nonce(TGS_GT_Ajax::NONCE);
$gt_today = current_time('Y-m-d');
?>
<div class="container-fluid flex-grow-1 container-p-y gt-list" data-nonce="<?php echo esc_attr($gt_nonce); ?>" data-blog="<?php echo (int) get_current_blog_id(); ?>">
    <style>
        .gt-list .gt-toolbar .btn { padding: .25rem .6rem; }
        .gt-list .gt-filter { background: #e9ecef; border-radius: 6px; padding: .5rem .75rem; }
        .gt-list .gt-filter label { font-weight: 600; margin: 0 .35rem 0 .75rem; white-space: nowrap; }
        .gt-list .gt-filter label:first-child { margin-left: 0; }
        .gt-list table td, .gt-list table th { white-space: nowrap; font-size: .85rem; }
        .gt-list tr.gt-pending > td { background: #fff7c2; }
        .gt-list tr.gt-done > td { background: #e4e4e4; color: #666; }
        .gt-list tr.gt-sel > td { background: #cfe2ff !important; color: #000; }
        .gt-list .gt-legend span { display: inline-block; width: 16px; height: 12px; vertical-align: middle; margin-right: 4px; }
    </style>
    <div class="card">
        <div class="card-body pb-2">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                <h5 class="mb-0 me-auto"><i class="bx bx-transfer-alt me-1"></i>Phiếu chuyển kho</h5>
                <a class="btn btn-sm btn-primary" href="<?php echo esc_url(tgs_url('transfer-export-add')); ?>"><i class="bx bx-plus me-1"></i>Tạo phiếu chuyển kho</a>
            </div>
            <div class="d-flex align-items-center flex-wrap gap-1 gt-filter">
                <label>Trạng thái</label>
                <select class="form-select form-select-sm w-auto gt-f-status">
                    <option value="pending">Đang chờ xác nhận(pending)</option>
                    <option value="approved">Đã xác nhận(verified &amp; approved)</option>
                    <option value="all">Tất cả</option>
                </select>
                <label>Từ</label><input type="date" class="form-control form-control-sm w-auto gt-f-from" value="<?php echo esc_attr(date('Y-m-01', current_time('timestamp'))); ?>">
                <label>Đến</label><input type="date" class="form-control form-control-sm w-auto gt-f-to" value="<?php echo esc_attr($gt_today); ?>">
                <label>Chi nhánh</label><select class="form-select form-select-sm w-auto gt-f-blog"><option value="all">Tất cả</option></select>
                <label>Tìm nhanh</label><input class="form-control form-control-sm gt-f-search" style="width:220px" placeholder="(Enter để tìm)">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle gt-table">
                <thead class="table-light"><tr>
                    <th>Duyệt</th><th>Số phiếu</th><th>Ngày chứng từ</th><th>Diễn giải</th><th>User ID</th><th>Người duyệt</th>
                    <th>Ngày tạo</th><th>Ngày duyệt</th><th>Chi nhánh xuất</th><th>Chi nhánh nhập</th><th>Updated By</th><th>Ngày sửa</th>
                </tr></thead>
                <tbody class="gt-rows"><tr><td colspan="12" class="text-center text-muted py-4">Đang tải…</td></tr></tbody>
            </table>
        </div>
        <div class="card-footer py-2 d-flex gap-4 small gt-legend">
            <div><span style="background:#fff7c2;border:1px solid #e5d97a"></span>Đang chờ xác nhận(pending)</div>
            <div><span style="background:#e4e4e4;border:1px solid #bbb"></span>Đã xác nhận(verified &amp; approved)</div>
            <div class="ms-auto text-muted gt-total"></div>
        </div>
    </div>
</div>
<script>
(function ($) {
    var $l = $('.gt-list'), nonce = $l.data('nonce'), me = +$l.data('blog'), rows = [];
    var formUrl = '<?php echo esc_url_raw(tgs_url('transfer-export-add')); ?>';
    var esc = function (s) { return $('<div>').text(s == null ? '' : s).html(); };
    var d = function (s) { return s && s.length >= 10 ? s.substr(8, 2) + '/' + s.substr(5, 2) + '/' + s.substr(0, 4) : ''; };
    var dt = function (s) { return s && s.length >= 16 ? d(s) + ' ' + s.substr(11, 5) : d(s); };
    function ajax(action, data) { return $.post(ajaxurl, $.extend({ action: 'tgs_gt_' + action, nonce: nonce }, data || {})); }
    function open(id) { window.location.href = formUrl + '&id=' + id; }
    function load() {
        ajax('htlist', { status: $l.find('.gt-f-status').val(), from: $l.find('.gt-f-from').val(), to: $l.find('.gt-f-to').val(), blog_id: $l.find('.gt-f-blog').val(), search: $l.find('.gt-f-search').val() }).done(function (r) {
            var $b = $l.find('.gt-rows').empty();
            if (!r.success) return $b.append('<tr><td colspan="12" class="text-danger">' + esc(r.data && r.data.message) + '</td></tr>');
            rows = r.data.items;
            if (!rows.length) $b.append('<tr><td colspan="12" class="text-center text-muted py-4">Không có phiếu</td></tr>');
            rows.forEach(function (t) {
                $('<tr>').addClass(t.approved ? 'gt-done' : 'gt-pending').attr('data-id', t.transfer_id).css('cursor', 'pointer').html(
                    '<td class="text-center"><input type="checkbox" disabled ' + (t.approved ? 'checked' : '') + '></td>'
                    + '<td class="font-monospace">' + esc(t.code) + (t.rejected ? ' <span class="badge bg-danger">Từ chối</span>' : '') + (!t.exported ? ' <span class="badge bg-secondary">Nháp</span>' : '') + '</td>'
                    + '<td>' + d(t.doc_date) + '</td><td>' + esc(t.note) + '</td><td>' + esc(t.user_id) + '</td><td>' + esc(t.approved_by) + '</td>'
                    + '<td>' + dt(t.created_at) + '</td><td>' + dt(t.approved_at) + '</td>'
                    + '<td title="' + esc(t.from_site) + '">' + esc(t.from_name) + '</td><td title="' + esc(t.to_site) + '">' + esc(t.to_name) + '</td>'
                    + '<td>' + esc(t.updated_by) + '</td><td>' + dt(t.updated_at) + '</td>'
                ).appendTo($b);
            });
            $l.find('.gt-total').text(rows.length + ' phiếu');
        });
    }
    $l.on('click', '.gt-rows tr[data-id]', function () { open(+$(this).data('id')); });
    $l.find('.gt-f-status,.gt-f-from,.gt-f-to,.gt-f-blog').on('change', load);
    $l.find('.gt-f-search').on('keydown', function (e) { if (e.key === 'Enter') load(); });
    ajax('options', {}).done(function (r) {
        var $s = $l.find('.gt-f-blog');
        if (r.success) r.data.sites.forEach(function (s) { $s.append($('<option>').val(s.blogId).text(s.name + (s.branchCode ? ' — ' + s.branchCode : ''))); });
        $s.val(String(me));
        load();
    });
})(jQuery);
</script>
