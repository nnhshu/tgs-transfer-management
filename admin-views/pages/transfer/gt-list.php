<?php
/**
 * Danh sách phiếu CHUYỂN KHO global của website: chuyển đi (bên xuất) / chuyển đến (bên nhận, phiếu đã duyệt xuất) / tất cả.
 * $gt_direction (out | in | all) do file route đặt; mặc định out.
 *
 * @package tgs-transfer-management
 */
if (!defined('ABSPATH')) {
    exit;
}
$gt_direction = isset($gt_direction) ? $gt_direction : (isset($_GET['dir']) ? sanitize_key($_GET['dir']) : 'out');
$gt_nonce = wp_create_nonce(TGS_GT_Ajax::NONCE);
$gt_titles = ['out' => 'Phiếu chuyển kho đi', 'in' => 'Phiếu chuyển kho đến', 'all' => 'Tất cả phiếu chuyển kho'];
?>
<div class="container-xxl flex-grow-1 container-p-y gt-list" data-nonce="<?php echo esc_attr($gt_nonce); ?>" data-dir="<?php echo esc_attr($gt_direction); ?>">
    <div class="card mb-3">
        <div class="card-body d-flex align-items-center gap-2 flex-wrap">
            <h5 class="mb-0 me-auto"><i class="bx bx-transfer-alt me-1"></i><?php echo esc_html($gt_titles[$gt_direction] ?? $gt_titles['out']); ?></h5>
            <div class="btn-group">
                <a class="btn btn-sm <?php echo $gt_direction === 'out' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo esc_url(tgs_url('ticket-transfer-exports')); ?>">Chuyển đi</a>
                <a class="btn btn-sm <?php echo $gt_direction === 'in' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo esc_url(tgs_url('ticket-transfer-imports')); ?>">Chuyển đến</a>
                <a class="btn btn-sm <?php echo $gt_direction === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo esc_url(tgs_url('transfer-report')); ?>">Tất cả</a>
            </div>
            <a class="btn btn-primary" href="<?php echo esc_url(tgs_url('transfer-export-add')); ?>"><i class="bx bx-plus me-1"></i>Tạo phiếu chuyển kho</a>
        </div>
    </div>
    <div class="card">
        <div class="card-body d-flex gap-2 flex-wrap align-items-end">
            <div><label class="form-label">Từ ngày</label><input type="date" class="form-control gt-f-from" value="<?php echo esc_attr(date('Y-m-01', current_time('timestamp'))); ?>"></div>
            <div><label class="form-label">Đến ngày</label><input type="date" class="form-control gt-f-to" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></div>
            <div><label class="form-label">Trạng thái</label><select class="form-select gt-f-status"><option value="">Tất cả</option><option value="draft">Nháp (chưa duyệt xuất)</option><option value="pending" <?php selected($gt_direction, 'in'); ?>>Chờ nhận</option><option value="received">Đã nhận</option><option value="rejected">Từ chối</option></select></div>
            <div class="flex-grow-1"><label class="form-label">Tìm</label><input class="form-control gt-f-search" placeholder="Mã phiếu, ghi chú, phân kho"></div>
            <button type="button" class="btn btn-primary gt-f-go"><i class="bx bx-search me-1"></i>Tìm</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Mã phiếu</th><th>Ngày</th><th>Đi (website · chi nhánh · phân kho)</th><th>Đến (website · chi nhánh · phân kho)</th><th class="text-end">Tổng SL</th><th class="text-end">Tổng tiền</th><th>Xuất</th><th>Nhận</th></tr></thead>
                <tbody class="gt-rows"><tr><td colspan="8" class="text-center text-muted py-4">Đang tải…</td></tr></tbody>
            </table>
        </div>
        <div class="card-footer text-muted small gt-total"></div>
    </div>
</div>
<script>
(function ($) {
    var $l = $('.gt-list'), nonce = $l.data('nonce'), dir = $l.data('dir');
    var fmt = function (n) { return (Math.round(+n || 0)).toLocaleString('vi-VN'); };
    var esc = function (s) { return $('<div>').text(s == null ? '' : s).html(); };
    var detail = '<?php echo esc_url_raw(tgs_url('ticket-transfer-export-detail')); ?>&id=';
    function badgeOut(t) { return +t.export_status === 1 ? '<span class="badge bg-label-success">Đã duyệt</span>' : '<span class="badge bg-label-secondary">Nháp</span>'; }
    function badgeIn(t) {
        if (+t.export_status !== 1) return '';
        if (+t.to_blog_id <= 0) return '<span class="badge bg-label-secondary">Ngoài hệ thống</span>';
        return { 0: '<span class="badge bg-label-warning">Chờ nhận</span>', 1: '<span class="badge bg-label-success">Đã nhận</span>', 2: '<span class="badge bg-label-danger">Từ chối</span>' }[+t.import_status] || '';
    }
    function load() {
        $.post(ajaxurl, { action: 'tgs_gt_list', nonce: nonce, direction: dir, from: $l.find('.gt-f-from').val(), to: $l.find('.gt-f-to').val(), status: $l.find('.gt-f-status').val(), search: $l.find('.gt-f-search').val(), per_page: 200 })
            .done(function (r) {
                var $b = $l.find('.gt-rows').empty();
                if (!r.success) return $b.append('<tr><td colspan="8" class="text-danger">' + esc(r.data && r.data.message) + '</td></tr>');
                if (!r.data.items.length) $b.append('<tr><td colspan="8" class="text-center text-muted py-4">Không có phiếu</td></tr>');
                r.data.items.forEach(function (t) {
                    $('<tr style="cursor:pointer">').html('<td class="font-monospace"><a href="' + detail + t.transfer_id + '">' + esc(t.transfer_code) + '</a>' + (+t.transfer_type === 2 ? ' <span class="badge bg-label-info">trả về</span>' : '') + '</td>'
                        + '<td>' + esc(t.doc_date) + '</td>'
                        + '<td>' + esc(t.from_blog_name) + ' · <b>' + esc(t.from_branch_code || '?') + '</b> · ' + esc(t.from_zone) + '</td>'
                        + '<td>' + esc(t.to_blog_name) + ' · <b>' + esc(t.to_branch_code || '?') + '</b> · ' + esc(t.to_zone) + '</td>'
                        + '<td class="text-end">' + fmt(t.total_qty) + '</td><td class="text-end">' + fmt(t.total_amount) + '</td><td>' + badgeOut(t) + '</td><td>' + badgeIn(t) + '</td>')
                        .on('click', function (e) { if (e.target.tagName !== 'A') window.location.href = detail + t.transfer_id; }).appendTo($b);
                });
                $l.find('.gt-total').text(r.data.total + ' phiếu');
            });
    }
    $l.find('.gt-f-go').on('click', load);
    $l.find('.gt-f-search').on('keydown', function (e) { if (e.key === 'Enter') load(); });
    load();
})(jQuery);
</script>
