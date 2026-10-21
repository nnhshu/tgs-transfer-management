<?php
/**
 * Chi tiết phiếu CHUYỂN KHO global. Bên ĐI: sửa, duyệt / bỏ duyệt xuất, xoá. Bên ĐẾN: duyệt / bỏ duyệt nhận, từ chối.
 *
 * @package tgs-transfer-management
 */
if (!defined('ABSPATH')) {
    exit;
}
$gt_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$gt_nonce = wp_create_nonce(TGS_GT_Ajax::NONCE);
?>
<div class="container-xxl flex-grow-1 container-p-y gt-detail" data-id="<?php echo (int) $gt_id; ?>" data-nonce="<?php echo esc_attr($gt_nonce); ?>">
    <div class="card mb-3">
        <div class="card-body d-flex align-items-center gap-2 flex-wrap">
            <h5 class="mb-0 me-auto"><i class="bx bx-transfer-alt me-1"></i>Phiếu chuyển kho <span class="font-monospace gt-code"></span> <span class="gt-badges"></span></h5>
            <a class="btn btn-outline-secondary" href="<?php echo esc_url(tgs_url('ticket-transfer-exports')); ?>">Danh sách</a>
            <span class="gt-actions d-flex gap-2"></span>
        </div>
    </div>
    <div class="alert d-none gt-msg"></div>
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card h-100"><div class="card-body"><h6 class="text-primary">Đầu đi (xuất)</h6><div class="gt-from"></div></div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body"><h6 class="text-success">Đầu đến (nhận)</h6><div class="gt-to"></div></div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body"><h6>Thông tin</h6><div class="gt-info"></div></div></div></div>
    </div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>#</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th><th class="text-end">SL</th><th class="text-end">SL nhận</th><th class="text-end">Đơn giá</th><th class="text-end">Thuế %</th><th class="text-end">Thành tiền</th><th>Ghi chú</th></tr></thead>
                <tbody class="gt-items"></tbody>
                <tfoot><tr><th colspan="4" class="text-end">Tổng</th><th class="text-end gt-tqty"></th><th></th><th></th><th></th><th class="text-end gt-tamount"></th><th></th></tr></tfoot>
            </table>
        </div>
    </div>
</div>
<script>
(function ($) {
    var $d = $('.gt-detail'), id = +$d.data('id'), nonce = $d.data('nonce');
    var fmt = function (n) { return (Math.round(+n || 0)).toLocaleString('vi-VN'); };
    var esc = function (s) { return $('<div>').text(s == null ? '' : s).html(); };
    var editUrl = '<?php echo esc_url_raw(tgs_url('transfer-export-add')); ?>&id=' + id;
    function msg(type, text) { $d.find('.gt-msg').removeClass('d-none alert-success alert-danger').addClass('alert-' + type).text(text); }
    function act(doVal, confirmText, extra) {
        if (confirmText && !confirm(confirmText)) return;
        $.post(ajaxurl, $.extend({ action: 'tgs_gt_action', nonce: nonce, transfer_id: id, do: doVal }, extra || {})).done(function (r) {
            if (!r.success) return msg('danger', r.data && r.data.message || 'Thao tác thất bại');
            if (doVal === 'delete') { window.location.href = '<?php echo esc_url_raw(tgs_url('ticket-transfer-exports')); ?>'; return; }
            msg('success', 'Đã cập nhật phiếu.'); render(r.data);
        });
    }
    function render(t) {
        var side = t.side || ($d.data('side'));
        $d.find('.gt-code').text(t.transfer_code);
        var b = (+t.export_status === 1 ? '<span class="badge bg-label-success">Đã duyệt xuất</span>' : '<span class="badge bg-label-secondary">Nháp</span>') + ' '
            + (+t.export_status === 1 && +t.to_blog_id <= 0 ? '<span class="badge bg-label-secondary">Xuất ra ngoài hệ thống</span>' : '')
            + (+t.export_status === 1 && +t.to_blog_id > 0 ? ({ 0: '<span class="badge bg-label-warning">Chờ nhận</span>', 1: '<span class="badge bg-label-success">Đã nhận</span>', 2: '<span class="badge bg-label-danger">Từ chối</span>' }[+t.import_status] || '') : '')
            + (+t.is_deleted ? ' <span class="badge bg-danger">Đã xoá</span>' : '');
        $d.find('.gt-badges').html(b);
        $d.find('.gt-from').html('<div>' + esc(t.from_blog_name) + ' <span class="text-muted">(blog ' + t.from_blog_id + ')</span></div><div>Chi nhánh: <b>' + esc(t.from_branch_code || 'chưa nối') + '</b></div><div>Phân kho: <b>' + esc(t.from_zone) + '</b></div>'
            + (t.export_approved_at ? '<div class="text-muted small">Duyệt xuất: ' + esc(t.export_approved_at) + '</div>' : ''));
        $d.find('.gt-to').html('<div>' + esc(t.to_blog_name) + ' <span class="text-muted">(blog ' + t.to_blog_id + ')</span></div><div>Chi nhánh: <b>' + esc(t.to_branch_code || 'chưa nối') + '</b></div><div>Phân kho: <b>' + esc(t.to_zone) + '</b></div>'
            + (t.import_approved_at ? '<div class="text-muted small">' + (+t.import_status === 2 ? 'Từ chối' : 'Duyệt nhận') + ': ' + esc(t.import_approved_at) + '</div>' : '')
            + (t.reject_reason ? '<div class="text-danger small">Lý do: ' + esc(t.reject_reason) + '</div>' : ''));
        $d.find('.gt-info').html('<div>Ngày: <b>' + esc(t.doc_date) + '</b></div><div>Loại: ' + (+t.transfer_type === 2 ? 'Trả về' : 'Chuyển đi') + '</div>' + (t.source_label ? '<div>Chứng từ gốc: <b>' + esc(t.source_label) + '</b></div>' : '') + '<div>Ghi chú: ' + esc(t.note || '') + '</div>');
        var $b = $d.find('.gt-items').empty(), tq = 0;
        (t.items || []).forEach(function (it, i) {
            tq += +it.quantity;
            $b.append('<tr><td>' + (i + 1) + '</td><td class="font-monospace">' + esc(it.sku) + '</td><td>' + esc(it.product_name) + '</td><td>' + esc(it.unit_name) + '</td><td class="text-end">' + fmt(it.quantity) + '</td><td class="text-end">' + (it.received_qty == null ? '' : fmt(it.received_qty)) + '</td><td class="text-end">' + fmt(it.price) + '</td><td class="text-end">' + (+it.tax_percent) + '</td><td class="text-end">' + fmt(it.amount) + '</td><td>' + esc(it.note) + '</td></tr>');
        });
        $d.find('.gt-tqty').text(fmt(tq)); $d.find('.gt-tamount').text(fmt(t.total_amount));
        var $a = $d.find('.gt-actions').empty(), sa = <?php echo is_super_admin() ? 'true' : 'false'; ?>;
        if (+t.is_deleted) return;
        var out = side === 'out' || sa, inn = side === 'in' || sa;
        function btn(cls, icon, text, fn) { $('<button type="button" class="btn ' + cls + '">').html('<i class="bx ' + icon + ' me-1"></i>' + text).on('click', fn).appendTo($a); }
        if (out) {
            btn('btn-outline-primary', 'bx-edit', 'Sửa', function () { window.location.href = editUrl; });
            if (+t.export_status === 0) btn('btn-primary', 'bx-check-circle', 'Duyệt xuất', function () { act('approve_export', 'Duyệt xuất? Tồn bên xuất trừ ngay, bên nhận thấy phiếu chờ nhận.'); });
            else if (+t.import_status !== 1) btn('btn-outline-warning', 'bx-undo', 'Bỏ duyệt xuất', function () { act('unapprove_export', 'Bỏ duyệt xuất? Tồn trả về bên xuất.'); });
            if (+t.import_status !== 1) btn('btn-outline-danger', 'bx-trash', 'Xoá', function () { act('delete', 'Xoá phiếu chuyển kho?'); });
        }
        if (inn && +t.export_status === 1 && +t.to_blog_id > 0) {
            if (+t.import_status === 0) {
                btn('btn-success', 'bx-check-double', 'Duyệt nhận', function () { act('approve_import', 'Xác nhận đã nhận đủ hàng? Tồn bên nhận cộng ngay.'); });
                btn('btn-outline-danger', 'bx-x-circle', 'Từ chối', function () { var r = prompt('Lý do từ chối nhận:'); if (r !== null) act('reject', '', { reason: r }); });
            } else if (+t.import_status === 1) {
                btn('btn-outline-warning', 'bx-undo', 'Bỏ duyệt nhận', function () { act('unapprove_import', 'Bỏ duyệt nhận? Tồn bên nhận trừ lại.'); });
            }
        }
    }
    $.post(ajaxurl, { action: 'tgs_gt_get', nonce: nonce, transfer_id: id }).done(function (r) {
        if (!r.success) return msg('danger', r.data && r.data.message || 'Không thấy phiếu');
        $d.data('side', r.data.side); render(r.data);
    });
})(jQuery);
</script>
