<?php
/**
 * "Báo cáo chuyển kho nội bộ" — hai thẻ:
 *   BC chuyển kho chi tiết            mỗi dòng hàng một dòng (đã xác nhận nền xám), tổng SL xuất / SL nhập / Thành tiền.
 *   BC chuyển kho nhóm theo mặt hàng  cộng theo mã hàng; lọc thêm chi nhánh / kho xuất, chi nhánh / kho nhập.
 * Bảng lọc trái: Từ ngày · Đến ngày · Chi nhánh (để trống = mọi chi nhánh) · Trạng thái phiếu chuyển kho; Tìm kiếm · In · Xuất.
 *
 * @package tgs-transfer-management
 */
if (!defined('ABSPATH')) {
    exit;
}
$gt_nonce = wp_create_nonce(TGS_GT_Ajax::NONCE);
$gt_today = current_time('Y-m-d');
?>
<div class="container-fluid flex-grow-1 container-p-y gt-rep" data-nonce="<?php echo esc_attr($gt_nonce); ?>" data-blog="<?php echo (int) get_current_blog_id(); ?>">
    <style>
        .gt-rep .gt-side fieldset { border: 1px solid #cfd4da; border-radius: 6px; padding: .25rem .75rem .75rem; }
        .gt-rep .gt-side legend { float: none; width: auto; font-size: .9rem; font-weight: 600; padding: 0 .4rem; margin: 0; }
        .gt-rep .gt-side label { font-size: .85rem; margin: .4rem 0 .1rem; }
        .gt-rep .gt-note { color: #6c8ebf; font-style: italic; font-size: .8rem; }
        .gt-rep table td, .gt-rep table th { white-space: nowrap; font-size: .82rem; }
        .gt-rep tr.gt-done > td { background: #e4e4e4; color: #666; }
        .gt-rep tfoot th { background: #f5f5f5; color: #0d6efd; }
        @media print { .gt-rep .gt-side, .gt-rep .nav, .layout-menu, .layout-navbar, .tgs-mega-nav, .tgs-tabs { display: none !important; } .gt-rep .gt-main { width: 100% !important; } }
    </style>
    <h5 class="mb-2"><i class="bx bx-bar-chart-alt-2 me-1"></i>Báo cáo chuyển kho nội bộ</h5>
    <ul class="nav nav-tabs mb-2">
        <li class="nav-item"><a class="nav-link active" href="#" data-mode="detail">BC chuyển kho chi tiết</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-mode="item">BC chuyển kho nhóm theo mặt hàng</a></li>
    </ul>
    <div class="d-flex gap-3 align-items-start">
        <div class="gt-side" style="width:250px;flex:0 0 250px">
            <fieldset>
                <legend>Tiêu chí tìm kiếm</legend>
                <div class="row g-1">
                    <div class="col-6"><label class="form-label">Từ ngày</label><input type="date" class="form-control form-control-sm gt-from" value="<?php echo esc_attr($gt_today); ?>"></div>
                    <div class="col-6"><label class="form-label">Đến ngày</label><input type="date" class="form-control form-control-sm gt-to" value="<?php echo esc_attr($gt_today); ?>"></div>
                </div>
                <div class="gt-only-detail">
                    <label class="form-label">Chi nhánh</label>
                    <select class="form-select form-select-sm gt-blog"><option value="">(Tất cả)</option></select>
                </div>
                <div class="gt-only-item d-none">
                    <label class="form-label">Chi nhánh xuất</label><select class="form-select form-select-sm gt-fblog"><option value="">(Tất cả)</option><option value="0">Ngoài hệ thống</option></select>
                    <label class="form-label">Kho xuất</label><select class="form-select form-select-sm gt-fzone"><option value="">Tất cả</option></select>
                    <label class="form-label">Chi nhánh nhập</label><select class="form-select form-select-sm gt-tblog"><option value="">(Tất cả)</option><option value="0">Ngoài hệ thống</option></select>
                    <label class="form-label">Kho nhập</label><select class="form-select form-select-sm gt-tzone"><option value="">Tất cả</option></select>
                </div>
                <label class="form-label">Trạng thái phiếu chuyển kho</label>
                <select class="form-select form-select-sm gt-status">
                    <option value="pending">Đang chờ xác nhận(pending)</option>
                    <option value="approved">Đã xác nhận(verified &amp; approved)</option>
                    <option value="all" selected>Tất cả</option>
                </select>
                <div class="d-grid gap-1 mt-3">
                    <button type="button" class="btn btn-sm btn-outline-primary gt-search"><i class="bx bx-search me-1"></i>Tìm kiếm</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary gt-print"><i class="bx bx-printer me-1"></i>In</button>
                    <button type="button" class="btn btn-sm btn-outline-success gt-export"><i class="bx bx-export me-1"></i>Xuất</button>
                </div>
                <p class="gt-note mt-3 mb-0 gt-only-detail">Chú ý: Nếu muốn tìm kiếm trên tất cả các chi nhánh, hãy bỏ trống ô nhập Chi nhánh</p>
            </fieldset>
        </div>
        <div class="gt-main flex-grow-1" style="min-width:0">
            <div class="card"><div class="table-responsive" style="max-height:72vh">
                <table class="table table-sm table-bordered mb-0 align-middle gt-table">
                    <thead class="table-light"></thead><tbody></tbody><tfoot></tfoot>
                </table>
            </div></div>
        </div>
    </div>
</div>
<script>
(function ($) {
    var $r = $('.gt-rep'), nonce = $r.data('nonce'), me = +$r.data('blog'), mode = 'detail';
    var editUrl = '<?php echo esc_url_raw(tgs_url('transfer-export-add')); ?>&id=';
    var esc = function (s) { return $('<div>').text(s == null ? '' : s).html(); };
    var n = function (v) { return (+v || 0).toLocaleString('vi-VN', { maximumFractionDigits: 3 }); };
    var d = function (s) { return s && s.length >= 10 ? s.substr(8, 2) + '/' + s.substr(5, 2) + '/' + s.substr(0, 4) : ''; };
    var dt = function (s) { return s && s.length >= 16 ? d(s) + ' ' + s.substr(11, 5) : d(s); };
    function ajax(action, data) { return $.post(ajaxurl, $.extend({ action: 'tgs_gt_' + action, nonce: nonce }, data || {})); }
    var COLS = {
        detail: [['approved', 'Đã xác nhận'], ['code', 'Số phiếu'], ['doc_date', 'Ngày chứng từ'], ['from_name', 'CN xuất'], ['to_name', 'CN nhập'], ['from_zone', 'Kho xuất'], ['to_zone', 'Kho nhập'],
            ['sku', 'Mã hàng'], ['name', 'Tên hàng'], ['out_qty', 'SL xuất', 1], ['in_qty', 'SL nhập', 1], ['price', 'Đơn giá', 1], ['amount', 'Thành tiền', 1], ['note', 'Diễn giải'], ['user_id', 'User lập phiếu'],
            ['created_at', 'Ngày tạo'], ['created_by', 'Người tạo'], ['approved_at', 'Ngày xác nhận'], ['approved_by', 'User xác nhận'], ['updated_at', 'Ngày sửa'], ['updated_by', 'Người sửa']],
        item: [['sku', 'Mã hàng'], ['name', 'Tên hàng'], ['unit', 'ĐVT'], ['out_qty', 'SL xuất', 1], ['in_qty', 'SL nhập', 1], ['price', 'Đơn giá', 1], ['amount', 'Thành tiền', 1]]
    };
    function cell(c, row) {
        var v = row[c[0]];
        if (c[0] === 'approved') return '<td class="text-center"><input type="checkbox" disabled ' + (v ? 'checked' : '') + '></td>';
        if (c[0] === 'code') return '<td class="font-monospace"><a href="' + editUrl + row.transfer_id + '">' + esc(v) + '</a></td>';
        if (c[0] === 'doc_date') return '<td>' + d(v) + '</td>';
        if (/_at$/.test(c[0])) return '<td>' + dt(v) + '</td>';
        if (c[0] === 'from_name' || c[0] === 'to_name') return '<td title="' + esc(row[c[0] === 'from_name' ? 'from_site' : 'to_site']) + '">' + esc(v) + '</td>';
        return c[2] ? '<td class="text-end">' + n(v) + '</td>' : '<td>' + esc(v) + '</td>';
    }
    function head() {
        $r.find('thead').html('<tr>' + COLS[mode].map(function (c) { return '<th' + (c[2] ? ' class="text-end"' : '') + '>' + c[1] + '</th>'; }).join('') + '</tr>');
        $r.find('tbody').html('<tr><td colspan="' + COLS[mode].length + '" class="text-center text-muted py-4">Chọn tiêu chí rồi bấm Tìm kiếm</td></tr>');
        $r.find('tfoot').empty();
    }
    function search() {
        var data = { mode: mode, from: $r.find('.gt-from').val(), to: $r.find('.gt-to').val(), status: $r.find('.gt-status').val() };
        if (mode === 'detail') data.blog_id = $r.find('.gt-blog').val();
        else { data.from_blog = $r.find('.gt-fblog').val(); data.to_blog = $r.find('.gt-tblog').val(); data.from_zone = $r.find('.gt-fzone').val(); data.to_zone = $r.find('.gt-tzone').val(); }
        $r.find('tbody').html('<tr><td colspan="' + COLS[mode].length + '" class="text-center text-muted py-4">Đang tải…</td></tr>');
        ajax('report', data).done(function (res) {
            var cols = COLS[mode], $b = $r.find('tbody').empty();
            if (!res.success) return $b.html('<tr><td colspan="' + cols.length + '" class="text-danger">' + esc(res.data && res.data.message) + '</td></tr>');
            var rows = res.data.rows, sum = {}, codes = {};
            if (!rows.length) $b.html('<tr><td colspan="' + cols.length + '" class="text-center text-muted py-4">Không có dữ liệu</td></tr>');
            rows.forEach(function (row) {
                cols.forEach(function (c) { if (c[2]) sum[c[0]] = (sum[c[0]] || 0) + (+row[c[0]] || 0); });
                codes[row.code || row.sku] = 1;
                $b.append('<tr class="' + (row.approved ? 'gt-done' : '') + '">' + cols.map(function (c) { return cell(c, row); }).join('') + '</tr>');
            });
            var countCol = mode === 'detail' ? 'code' : 'sku';
            $r.find('tfoot').html('<tr>' + cols.map(function (c) {
                if (c[0] === countCol) return '<th>' + rows.length.toLocaleString('vi-VN') + '</th>'; // như phần mềm cũ: đếm số dòng
                if (c[2] && c[0] !== 'price') return '<th class="text-end">' + n(sum[c[0]]) + '</th>';
                return '<th></th>';
            }).join('') + '</tr>');
        });
    }
    function zonesFor($blog, $zone) {
        var b = +$blog.val(); $zone.html('<option value="">Tất cả</option>');
        if (b > 0) ajax('options', { blog_id: b }).done(function (r) { if (r.success) r.data.zones.forEach(function (z) { $zone.append($('<option>').val(z.code).text(z.code + ' — ' + z.name)); }); });
    }
    $r.find('.nav-link').on('click', function (e) {
        e.preventDefault(); $r.find('.nav-link').removeClass('active'); $(this).addClass('active'); mode = $(this).data('mode');
        $r.find('.gt-only-detail').toggleClass('d-none', mode !== 'detail'); $r.find('.gt-only-item').toggleClass('d-none', mode !== 'item'); head();
    });
    $r.find('.gt-fblog').on('change', function () { zonesFor($(this), $r.find('.gt-fzone')); });
    $r.find('.gt-tblog').on('change', function () { zonesFor($(this), $r.find('.gt-tzone')); });
    $r.find('.gt-search').on('click', search);
    $r.find('.gt-print').on('click', function () { window.print(); });
    $r.find('.gt-export').on('click', function () {
        var html = '<html><head><meta charset="utf-8"></head><body><table border="1">' + $r.find('.gt-table').html() + '</table></body></html>';
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['﻿' + html], { type: 'application/vnd.ms-excel' }));
        a.download = (mode === 'detail' ? 'bc-chuyen-kho-chi-tiet' : 'bc-chuyen-kho-theo-mat-hang') + '.xls';
        a.click();
    });
    ajax('options', {}).done(function (r) {
        if (r.success) r.data.sites.forEach(function (s) {
            var t = s.name + (s.branchCode ? ' — ' + s.branchCode : '');
            $r.find('.gt-blog,.gt-fblog,.gt-tblog').append($('<option>').val(s.blogId).text(t));
        });
        head();
    });
})(jQuery);
</script>
