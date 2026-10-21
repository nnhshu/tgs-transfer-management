<?php
/**
 * Lập / sửa phiếu CHUYỂN KHO global (?id= để sửa). Đầu ĐI = website đang đứng + phân kho đi; đầu ĐẾN = website / chi nhánh
 * nhận + phân kho đến (phân kho đồng bộ từ danh mục kho của phần mềm kế toán). Lưu nháp hoặc lưu & duyệt xuất.
 *
 * @package tgs-transfer-management
 */
if (!defined('ABSPATH')) {
    exit;
}
$gt_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$gt_nonce = wp_create_nonce(TGS_GT_Ajax::NONCE);
?>
<div class="container-xxl flex-grow-1 container-p-y gt-wrap" data-id="<?php echo (int) $gt_id; ?>" data-nonce="<?php echo esc_attr($gt_nonce); ?>">
    <div class="card mb-3">
        <div class="card-body d-flex align-items-center gap-2 flex-wrap">
            <h5 class="mb-0 me-auto"><i class="bx bx-transfer-alt me-1"></i><?php echo $gt_id ? 'Sửa phiếu chuyển kho' : 'Tạo phiếu chuyển kho'; ?> <span class="text-muted gt-code"></span></h5>
            <a class="btn btn-outline-secondary" href="<?php echo esc_url(tgs_url('ticket-transfer-exports')); ?>">Quay lại</a>
            <button type="button" class="btn btn-outline-primary gt-save" data-approve="0"><i class="bx bx-save me-1"></i>Lưu nháp</button>
            <button type="button" class="btn btn-primary gt-save" data-approve="1"><i class="bx bx-check-circle me-1"></i>Lưu &amp; duyệt xuất</button>
        </div>
    </div>
    <div class="alert d-none gt-msg"></div>
    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-primary"><i class="bx bx-log-out-circle me-1"></i>Đầu đi (xuất)</h6>
                <div class="mb-2"><label class="form-label">Website / chi nhánh</label><input class="form-control gt-from-site" disabled></div>
                <div class="mb-2"><label class="form-label">Phân kho đi <span class="text-danger">*</span></label><select class="form-select gt-from-zone"></select></div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-success"><i class="bx bx-log-in-circle me-1"></i>Đầu đến (nhận)</h6>
                <div class="mb-2"><label class="form-label">Website / chi nhánh nhận <span class="text-danger">*</span></label><select class="form-select gt-to-site"><option value="">— Chọn nơi nhận —</option></select></div>
                <div class="mb-2"><label class="form-label">Phân kho đến <span class="text-danger">*</span></label><select class="form-select gt-to-zone"></select></div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <h6><i class="bx bx-file me-1"></i>Thông tin phiếu</h6>
                <div class="mb-2"><label class="form-label">Ngày chứng từ</label><input type="date" class="form-control gt-date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></div>
                <div class="mb-2"><label class="form-label">Loại</label><select class="form-select gt-type"><option value="1">Chuyển đi</option><option value="2">Trả về</option></select></div>
                <div class="mb-2"><label class="form-label">Ghi chú</label><textarea class="form-control gt-note" rows="2"></textarea></div>
            </div></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
            <h6 class="mb-0 me-auto">Danh sách hàng</h6>
            <div class="position-relative" style="width:420px;max-width:100%">
                <input class="form-control gt-search" placeholder="Gõ mã / tên / mã vạch để thêm hàng…" autocomplete="off">
                <div class="list-group position-absolute w-100 shadow gt-results" style="z-index:20;max-height:320px;overflow:auto"></div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th>#</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th><th class="text-end">Tồn</th><th style="width:110px" class="text-end">SL</th><th style="width:140px" class="text-end">Đơn giá (trước thuế)</th><th class="text-end">Thuế %</th><th class="text-end">Thành tiền</th><th>Ghi chú</th><th></th></tr></thead>
                <tbody class="gt-lines"><tr class="gt-empty"><td colspan="11" class="text-center text-muted py-4">Chưa có hàng — gõ ở ô tìm phía trên.</td></tr></tbody>
                <tfoot><tr><th colspan="5" class="text-end">Tổng</th><th class="text-end gt-tqty">0</th><th></th><th></th><th class="text-end gt-tamount">0</th><th colspan="2"></th></tr></tfoot>
            </table>
        </div>
    </div>
</div>
<script>
(function ($) {
    var $w = $('.gt-wrap'), id = +$w.data('id'), nonce = $w.data('nonce'), cur = <?php echo (int) get_current_blog_id(); ?>, lines = [], sites = [], loaded = null;
    var fmt = function (n) { return (Math.round(+n || 0)).toLocaleString('vi-VN'); };
    function ajax(action, data) { return $.post(ajaxurl, $.extend({ action: 'tgs_gt_' + action, nonce: nonce }, data || {})); }
    function msg(type, text) { $w.find('.gt-msg').removeClass('d-none alert-success alert-danger alert-warning').addClass('alert-' + type).text(text); }
    function zoneOptions($sel, zones, value) {
        $sel.empty();
        if (!zones.length) $sel.append('<option value="">(website chưa có phân kho — đồng bộ phân kho)</option>');
        zones.forEach(function (z) { $sel.append($('<option>').val(z.code).text(z.code + ' — ' + z.name + (z.isDefault ? ' (mặc định)' : ''))); });
        if (value) $sel.val(value);
    }
    function loadZones(blog, $sel, value) { return ajax('options', { blog_id: blog }).done(function (r) { if (r.success) zoneOptions($sel, r.data.zones, value); }); }
    function render() {
        var $b = $w.find('.gt-lines').empty(), tq = 0, ta = 0;
        if (!lines.length) $b.append('<tr><td colspan="11" class="text-center text-muted py-4">Chưa có hàng — gõ ở ô tìm phía trên.</td></tr>');
        lines.forEach(function (l, i) {
            var amt = (+l.quantity || 0) * (+l.price || 0) * (1 + (+l.tax_percent || 0) / 100);
            tq += +l.quantity || 0; ta += amt;
            $b.append('<tr data-i="' + i + '"><td>' + (i + 1) + '</td><td class="font-monospace">' + l.sku + '</td><td>' + (l.product_name || '') + '</td><td>' + (l.unit_name || '') + '</td>'
                + '<td class="text-end ' + ((+l.stock || 0) < (+l.quantity || 0) ? 'text-danger' : 'text-muted') + '">' + (l.stock === undefined ? '' : fmt(l.stock)) + '</td>'
                + '<td><input type="number" min="0" step="any" class="form-control form-control-sm text-end gt-q" value="' + l.quantity + '"></td>'
                + '<td><input type="number" min="0" step="any" class="form-control form-control-sm text-end gt-p" value="' + l.price + '"></td>'
                + '<td class="text-end">' + (+l.tax_percent || 0) + '</td><td class="text-end">' + fmt(amt) + '</td>'
                + '<td><input class="form-control form-control-sm gt-n" value="' + $('<div>').text(l.note || '').html() + '"></td>'
                + '<td><button type="button" class="btn btn-sm btn-icon btn-outline-danger gt-del"><i class="bx bx-trash"></i></button></td></tr>');
        });
        $w.find('.gt-tqty').text(fmt(tq)); $w.find('.gt-tamount').text(fmt(ta));
    }
    $w.on('input', '.gt-q,.gt-p,.gt-n', function () {
        var i = +$(this).closest('tr').data('i');
        if ($(this).hasClass('gt-q')) lines[i].quantity = this.value;
        if ($(this).hasClass('gt-p')) lines[i].price = this.value;
        if ($(this).hasClass('gt-n')) lines[i].note = this.value;
    }).on('change', '.gt-q,.gt-p', render)
      .on('click', '.gt-del', function () { lines.splice(+$(this).closest('tr').data('i'), 1); render(); });

    var timer;
    $w.find('.gt-search').on('input', function () {
        var q = this.value.trim(); clearTimeout(timer);
        if (q.length < 2) { $w.find('.gt-results').empty(); return; }
        timer = setTimeout(function () {
            ajax('products', { search: q }).done(function (r) {
                var $r = $w.find('.gt-results').empty();
                (r.success ? r.data.items : []).forEach(function (p) {
                    $('<button type="button" class="list-group-item list-group-item-action">').html('<b class="font-monospace">' + p.sku + '</b> ' + $('<div>').text(p.name).html() + ' <span class="float-end text-muted">tồn ' + fmt(p.stock) + ' ' + (p.unit || '') + '</span>')
                        .on('click', function () {
                            var ex = lines.find(function (l) { return l.sku === p.sku; });
                            if (ex) ex.quantity = (+ex.quantity || 0) + 1;
                            else lines.push({ product_id: p.product_id, sku: p.sku, product_name: p.name, unit_name: p.unit, quantity: 1, price: p.price, tax_percent: p.tax, stock: p.stock, note: '' });
                            $r.empty(); $w.find('.gt-search').val('').focus(); render();
                        }).appendTo($r);
                });
            });
        }, 250);
    });

    $w.find('.gt-to-site').on('change', function () { var b = +this.value; if (b) loadZones(b, $w.find('.gt-to-zone'), loaded && +loaded.to_blog_id === b ? loaded.to_zone : ''); else zoneOptions($w.find('.gt-to-zone'), [], ''); });

    $w.find('.gt-save').on('click', function () {
        var approve = +$(this).data('approve');
        if (!$w.find('.gt-to-site').val()) return msg('warning', 'Chưa chọn nơi nhận');
        if (!lines.length) return msg('warning', 'Phiếu chưa có hàng');
        if (approve && !confirm('Lưu và duyệt xuất? Tồn bên xuất trừ ngay, bên nhận thấy phiếu chờ nhận.')) return;
        $w.find('.gt-save').prop('disabled', true);
        ajax('save', {
            transfer_id: id || 0, to_blog_id: $w.find('.gt-to-site').val(), from_zone: $w.find('.gt-from-zone').val(), to_zone: $w.find('.gt-to-zone').val(),
            doc_date: $w.find('.gt-date').val(), transfer_type: $w.find('.gt-type').val(), note: $w.find('.gt-note').val(), approve: approve,
            items: JSON.stringify(lines.map(function (l) { return { product_id: l.product_id, sku: l.sku, product_name: l.product_name, unit_name: l.unit_name, quantity: l.quantity, price: l.price, tax_percent: l.tax_percent, note: l.note }; }))
        }).done(function (r) {
            $w.find('.gt-save').prop('disabled', false);
            if (!r.success) return msg('danger', r.data && r.data.message || 'Lưu thất bại');
            window.location.href = '<?php echo esc_url_raw(tgs_url('ticket-transfer-export-detail')); ?>&id=' + r.data.transfer_id;
        }).fail(function () { $w.find('.gt-save').prop('disabled', false); msg('danger', 'Lỗi mạng'); });
    });

    ajax('options', { blog_id: cur }).done(function (r) {
        if (!r.success) return msg('danger', r.data && r.data.message || 'Không tải được danh mục');
        sites = r.data.sites;
        var me = sites.find(function (s) { return s.blogId === cur; }) || {};
        $w.find('.gt-from-site').val((me.name || ('blog ' + cur)) + (me.branchCode ? ' — ' + me.branchCode : ' (chưa nối chi nhánh)'));
        zoneOptions($w.find('.gt-from-zone'), r.data.zones, '');
        var $to = $w.find('.gt-to-site');
        sites.filter(function (s) { return s.blogId !== cur; }).forEach(function (s) { $to.append($('<option>').val(s.blogId).text(s.name + ' — ' + (s.branchCode || 'chưa nối chi nhánh') + ' (blog ' + s.blogId + ')')); });
        if (id) {
            ajax('get', { transfer_id: id }).done(function (g) {
                if (!g.success) return msg('danger', g.data && g.data.message);
                loaded = g.data;
                $w.find('.gt-code').text(loaded.transfer_code);
                $w.find('.gt-from-zone').val(loaded.from_zone); $w.find('.gt-date').val(loaded.doc_date); $w.find('.gt-type').val(loaded.transfer_type); $w.find('.gt-note').val(loaded.note || '');
                $to.val(loaded.to_blog_id).trigger('change');
                lines = loaded.items.map(function (it) { return { product_id: it.product_id, sku: it.sku, product_name: it.product_name, unit_name: it.unit_name, quantity: +it.quantity, price: +it.price, tax_percent: +it.tax_percent, note: it.note }; });
                if (+loaded.import_status === 1) msg('warning', 'Phiếu bên nhận đã nhận — sửa thì tồn hai bên tự cập nhật theo số mới.');
                render();
            });
        } else {
            prefillFromPo($to);
        }
    });

    /**
     * Điền sẵn từ PO / quét tồn (tgs_po_adjustment): dữ liệu để ở sessionStorage
     * (tgs_poa_copy_items | tgs_poa_ticket_prefill) {ticket_type, person: {id = blog nhận}, items: [{sku, quantity, note}], note}.
     */
    function prefillFromPo($to) {
        var data = null, key = null;
        ['tgs_poa_copy_items', 'tgs_poa_ticket_prefill'].some(function (k) {
            try { var v = JSON.parse(sessionStorage.getItem(k) || 'null'); if (v && /transfer|internal_(export|return)/.test(v.ticket_type || '')) { data = v; key = k; return true; } } catch (e) {}
            return false;
        });
        if (!data) return;
        try { sessionStorage.removeItem(key); } catch (e) {}
        if (/return/.test(data.ticket_type)) $w.find('.gt-type').val('2');
        if (data.note || data.po_code) $w.find('.gt-note').val(data.note || ('Tạo từ PO ' + data.po_code));
        var dest = data.person && +data.person.id;
        if (dest && dest !== cur) $to.val(dest).trigger('change');
        var items = (data.items || []).filter(function (it) { return (it.sku || it.product_sku); });
        var pending = items.length;
        if (!pending) return;
        msg('success', 'Đã điền sẵn ' + pending + ' dòng từ PO — kiểm tra phân kho, số lượng rồi lưu.');
        items.forEach(function (it) {
            var sku = String(it.sku || it.product_sku);
            ajax('products', { search: sku }).done(function (r) {
                var p = (r.success ? r.data.items : []).find(function (x) { return String(x.sku).toUpperCase() === sku.toUpperCase(); });
                lines.push(p
                    ? { product_id: p.product_id, sku: p.sku, product_name: p.name, unit_name: p.unit, quantity: +it.quantity || 1, price: p.price, tax_percent: p.tax, stock: p.stock, note: it.note || '' }
                    : { sku: sku, product_name: it.name || it.product_name || sku, unit_name: '', quantity: +it.quantity || 1, price: 0, tax_percent: 0, note: it.note || '' });
            }).always(function () { if (--pending === 0) render(); });
        });
    }
})(jQuery);
</script>
