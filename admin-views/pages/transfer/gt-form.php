<?php
/**
 * Phiếu chuyển kho — "Nhập- xuất kho nội bộ" (?id= để mở phiếu đã có).
 *   Bên XUẤT: chọn kho xuất, chi nhánh + kho nhập, diễn giải, chi tiết phiếu → Ghi (lưu và xuất kho luôn: tồn bên xuất trừ).
 *   Bên NHẬP: ghi Số lượng nhập (mặc định = số lượng) → "Xác nhận đã nhận đủ hàng" (tồn bên nhập cộng) / Bỏ xác nhận.
 * Phiếu đã xác nhận chỉ xem; muốn sửa phải bỏ xác nhận trước. Chỉ cộng / trừ số lượng.
 *
 * @package tgs-transfer-management
 */
if (!defined('ABSPATH')) {
    exit;
}
$gt_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$gt_nonce = wp_create_nonce(TGS_GT_Ajax::NONCE);
?>
<div class="container-fluid flex-grow-1 container-p-y gt-wrap" data-id="<?php echo (int) $gt_id; ?>" data-nonce="<?php echo esc_attr($gt_nonce); ?>" data-blog="<?php echo (int) get_current_blog_id(); ?>" data-sa="<?php echo is_super_admin() ? 1 : 0; ?>">
    <style>
        .gt-wrap fieldset { border: 1px solid #cfd4da; border-radius: 6px; padding: .25rem .9rem .6rem; }
        .gt-wrap legend { float: none; width: auto; font-size: .95rem; font-weight: 600; padding: 0 .4rem; margin: 0; }
        .gt-wrap .gt-hd label { font-size: .85rem; margin-bottom: .1rem; }
        .gt-wrap table td, .gt-wrap table th { font-size: .85rem; white-space: nowrap; }
        .gt-wrap .gt-ro input, .gt-wrap .gt-ro select, .gt-wrap .gt-ro textarea { pointer-events: none; background: #f3f4f6; }
    </style>
    <div class="card mb-3">
        <div class="card-body d-flex align-items-center gap-2 flex-wrap py-2">
            <h5 class="mb-0 me-auto"><i class="bx bx-transfer-alt me-1"></i>Nhập- xuất kho nội bộ</h5>
            <button type="button" class="btn btn-sm btn-primary gt-save" style="display:none"><i class="bx bx-save me-1"></i>Ghi</button>
            <button type="button" class="btn btn-sm btn-success gt-confirm" style="display:none"><i class="bx bx-check-double me-1"></i>Xác nhận đã nhận đủ hàng</button>
            <button type="button" class="btn btn-sm btn-outline-warning gt-unconfirm" style="display:none"><i class="bx bx-undo me-1"></i>Bỏ xác nhận</button>
            <button type="button" class="btn btn-sm btn-outline-danger gt-delete" style="display:none"><i class="bx bx-x me-1"></i>Xóa</button>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url(tgs_url('ticket-transfer-exports')); ?>"><i class="bx bx-exit me-1"></i>Đóng</a>
        </div>
    </div>
    <div class="alert d-none gt-msg py-2"></div>
    <div class="card mb-3"><div class="card-body gt-hd">
        <fieldset>
            <legend>Nhập- xuất kho nội bộ</legend>
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label">Số phiếu</label><input class="form-control form-control-sm gt-code" disabled placeholder="(tự sinh khi ghi)"></div>
                <div class="col-md-3"><label class="form-label">Ngày</label><input type="date" class="form-control form-control-sm gt-date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></div>
                <div class="col-md-3"><label class="form-label">Trạng thái</label><input class="form-control form-control-sm gt-status" disabled value="Mới"></div>
                <div class="col-md-3"><label class="form-label">Chứng từ gốc</label><input class="form-control form-control-sm gt-src" disabled></div>
                <div class="col-md-3"><label class="form-label">Chi nhánh xuất</label><select class="form-select form-select-sm gt-from-site"></select></div>
                <div class="col-md-3"><label class="form-label">Kho xuất</label><select class="form-select form-select-sm gt-from-zone"></select></div>
                <div class="col-md-3"><label class="form-label">Chi nhánh nhập</label><select class="form-select form-select-sm gt-to-site"><option value="">— Chọn —</option></select></div>
                <div class="col-md-3"><label class="form-label">Kho nhập</label><select class="form-select form-select-sm gt-to-zone"></select></div>
                <div class="col-md-3"><label class="form-label">Lý do <a href="#" class="gt-reason-refresh small ms-1" title="Kéo lại danh mục lý do từ phần mềm kế toán"><i class="bx bx-refresh"></i></a></label><select class="form-select form-select-sm gt-reason"></select></div>
                <div class="col-md-9"><label class="form-label">Diễn giải</label><textarea class="form-control form-control-sm gt-note" rows="1"></textarea></div>
            </div>
        </fieldset>
    </div></div>
    <div class="card">
        <div class="card-header d-flex align-items-center gap-2 py-2">
            <h6 class="mb-0 me-auto">Chi tiết phiếu</h6>
            <div class="position-relative gt-add" style="width:420px;max-width:100%">
                <input class="form-control form-control-sm gt-search" placeholder="Mã hàng / tên / mã vạch…" autocomplete="off">
                <div class="list-group position-absolute w-100 shadow gt-results" style="z-index:20;max-height:320px;overflow:auto"></div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle">
                <thead class="table-light"><tr><th>#</th><th>Kho xuất</th><th>Kho nhập</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th><th style="width:110px" class="text-end">Số lượng</th><th style="width:120px" class="text-end">Số lượng nhập</th><th>Ghi chú</th><th></th></tr></thead>
                <tbody class="gt-lines"></tbody>
                <tfoot><tr><th colspan="6" class="text-end">Tổng</th><th class="text-end gt-tqty">0</th><th class="text-end gt-trecv"></th><th colspan="2"></th></tr></tfoot>
            </table>
        </div>
    </div>
</div>
<script>
(function ($) {
    var $w = $('.gt-wrap'), id = +$w.data('id'), nonce = $w.data('nonce'), cur = +$w.data('blog'), sa = +$w.data('sa') === 1, canAll = sa, sites = [];
    var lines = [], loaded = null, mode = 'new'; // new | edit (bên xuất sửa được) | receive (bên nhập chờ xác nhận) | view
    var listUrl = '<?php echo esc_url_raw(tgs_url('ticket-transfer-exports')); ?>', formUrl = '<?php echo esc_url_raw(tgs_url('transfer-export-add')); ?>';
    var fmt = function (n) { return (+n || 0).toLocaleString('vi-VN', { maximumFractionDigits: 3 }); };
    var esc = function (s) { return $('<div>').text(s == null ? '' : s).html(); };
    function ajax(action, data) { return $.post(ajaxurl, $.extend({ action: 'tgs_gt_' + action, nonce: nonce }, data || {})); }
    function msg(type, text) { $w.find('.gt-msg').removeClass('d-none alert-success alert-danger alert-warning').addClass('alert-' + type).text(text); }
    function zoneOptions($sel, zones, value) {
        $sel.empty();
        if (!zones.length) $sel.append('<option value="">(chưa có kho — đồng bộ phân kho)</option>');
        zones.forEach(function (z) { $sel.append($('<option>').val(z.code).text(z.code + ' — ' + z.name)); });
        if (value) { if (!$sel.find('option').filter(function () { return this.value === value; }).length) $sel.append($('<option>').val(value).text(value)); $sel.val(value); }
    }
    function loadZones(blog, $sel, value) { return ajax('options', { blog_id: blog }).done(function (r) { if (r.success) zoneOptions($sel, r.data.zones, value); }); }
    function editable() { return mode === 'new' || mode === 'edit'; }
    function render() {
        var $b = $w.find('.gt-lines').empty(), tq = 0, tr = 0, kx = $w.find('.gt-from-zone').val() || '', kn = $w.find('.gt-to-zone').val() || '';
        if (!lines.length) $b.append('<tr><td colspan="10" class="text-center text-muted py-4">Chưa có hàng — gõ mã hàng ở ô phía trên.</td></tr>');
        lines.forEach(function (l, i) {
            tq += +l.quantity || 0; tr += +(l.received === '' || l.received == null ? l.quantity : l.received) || 0;
            var q = editable() ? '<input type="number" min="0" step="any" class="form-control form-control-sm text-end gt-q" value="' + l.quantity + '">' : fmt(l.quantity);
            var rq = mode === 'receive' ? '<input type="number" min="0" step="any" class="form-control form-control-sm text-end gt-r" value="' + (l.received === '' || l.received == null ? l.quantity : l.received) + '">'
                : (loaded && +loaded.import_status === 1 ? fmt(l.received == null || l.received === '' ? l.quantity : l.received) : '');
            var n = editable() ? '<input class="form-control form-control-sm gt-n" value="' + esc(l.note || '') + '">' : esc(l.note || '');
            $b.append('<tr data-i="' + i + '"><td>' + (i + 1) + '</td><td>' + esc(kx) + '</td><td>' + esc(kn) + '</td><td class="font-monospace">' + esc(l.sku) + '</td><td>' + esc(l.product_name || '') + '</td><td>' + esc(l.unit_name || '') + '</td>'
                + '<td class="text-end">' + q + '</td><td class="text-end">' + rq + '</td><td>' + n + '</td>'
                + '<td>' + (editable() ? '<button type="button" class="btn btn-sm btn-icon btn-outline-danger gt-del"><i class="bx bx-trash"></i></button>' : '') + '</td></tr>');
        });
        $w.find('.gt-tqty').text(fmt(tq)); $w.find('.gt-trecv').text(mode === 'receive' || (loaded && +loaded.import_status === 1) ? fmt(tr) : '');
    }
    function applyMode() {
        $w.find('.gt-hd').toggleClass('gt-ro', !editable());
        $w.find('.gt-add').toggle(editable());
        $w.find('.gt-save').toggle(editable());
        $w.find('.gt-confirm').toggle(mode === 'receive');
        $w.find('.gt-unconfirm').toggle(!!(loaded && +loaded.import_status === 1 && (canAll || +loaded.to_blog_id === cur)));
        $w.find('.gt-delete').toggle(!!(loaded && mode === 'edit'));
        $w.find('.gt-from-site').prop('disabled', !(editable() && canAll && !loaded));
        render();
    }
    $w.on('input', '.gt-q,.gt-n,.gt-r', function () {
        var i = +$(this).closest('tr').data('i');
        if ($(this).hasClass('gt-q')) lines[i].quantity = this.value;
        if ($(this).hasClass('gt-n')) lines[i].note = this.value;
        if ($(this).hasClass('gt-r')) lines[i].received = this.value;
    }).on('change', '.gt-q,.gt-r', render)
      .on('click', '.gt-del', function () { lines.splice(+$(this).closest('tr').data('i'), 1); render(); });
    $w.find('.gt-from-zone,.gt-to-zone').on('change', render);

    var timer;
    $w.find('.gt-search').on('input', function () {
        var q = this.value.trim(); clearTimeout(timer);
        if (q.length < 2) { $w.find('.gt-results').empty(); return; }
        timer = setTimeout(function () {
            ajax('products', { search: q }).done(function (r) {
                var $r = $w.find('.gt-results').empty();
                (r.success ? r.data.items : []).forEach(function (p) {
                    $('<button type="button" class="list-group-item list-group-item-action py-1">').html('<b class="font-monospace">' + esc(p.sku) + '</b> ' + esc(p.name) + ' <span class="float-end text-muted">tồn ' + fmt(p.stock) + ' ' + esc(p.unit || '') + '</span>')
                        .on('click', function () {
                            var ex = lines.find(function (l) { return l.sku === p.sku; });
                            if (ex) ex.quantity = (+ex.quantity || 0) + 1;
                            else lines.push({ product_id: p.product_id, sku: p.sku, product_name: p.name, unit_name: p.unit, quantity: 1, price: p.price, tax_percent: p.tax, note: '' });
                            $r.empty(); $w.find('.gt-search').val('').focus(); render();
                        }).appendTo($r);
                });
            });
        }, 250);
    });
    $w.find('.gt-to-site').on('change', function () { var b = +this.value; if (b) loadZones(b, $w.find('.gt-to-zone'), loaded && +loaded.to_blog_id === b ? loaded.to_zone : '').always(render); else { zoneOptions($w.find('.gt-to-zone'), [], ''); render(); } });

    $w.find('.gt-save').on('click', function () {
        if (!$w.find('.gt-to-site').val()) return msg('warning', 'Chưa chọn chi nhánh nhập');
        if ($w.find('.gt-to-site').val() === $w.find('.gt-from-site').val()) return msg('warning', 'Chi nhánh nhập trùng chi nhánh xuất');
        if (!lines.length) return msg('warning', 'Phiếu chưa có hàng');
        $w.find('.gt-save').prop('disabled', true);
        ajax('save', {
            transfer_id: id || 0, from_blog_id: $w.find('.gt-from-site').val(), reason_code: $w.find('.gt-reason').val() || '', to_blog_id: $w.find('.gt-to-site').val(), from_zone: $w.find('.gt-from-zone').val(), to_zone: $w.find('.gt-to-zone').val(),
            doc_date: $w.find('.gt-date').val(), transfer_type: loaded ? loaded.transfer_type : 1, note: $w.find('.gt-note').val(), approve: 1,
            items: JSON.stringify(lines.map(function (l) { return { product_id: l.product_id, sku: l.sku, product_name: l.product_name, unit_name: l.unit_name, quantity: l.quantity, price: l.price, tax_percent: l.tax_percent, note: l.note }; }))
        }).done(function (r) {
            $w.find('.gt-save').prop('disabled', false);
            if (!r.success) return msg('danger', r.data && r.data.message || 'Ghi thất bại');
            window.location.href = formUrl + '&id=' + r.data.transfer_id + '&saved=1';
        }).fail(function () { $w.find('.gt-save').prop('disabled', false); msg('danger', 'Lỗi mạng'); });
    });
    function act(doVal, confirmText, extra) {
        if (confirmText && !confirm(confirmText)) return;
        ajax('action', $.extend({ transfer_id: id, do: doVal }, extra || {})).done(function (r) {
            if (!r.success) return msg('danger', r.data && r.data.message || 'Thao tác thất bại');
            if (doVal === 'delete') { window.location.href = listUrl; return; }
            window.location.href = formUrl + '&id=' + id + '&saved=1';
        });
    }
    $w.find('.gt-confirm').on('click', function () {
        var received = {};
        lines.forEach(function (l) { received[l.item_id] = l.received === '' || l.received == null ? l.quantity : l.received; });
        act('approve_import', 'Xác nhận đã nhận đủ hàng? Tồn bên nhập cộng theo Số lượng nhập.', { received: JSON.stringify(received) });
    });
    $w.find('.gt-unconfirm').on('click', function () { act('unapprove_import', 'Bỏ xác nhận? Tồn bên nhập trừ lại.'); });
    $w.find('.gt-delete').on('click', function () { act('delete', 'Xóa phiếu chuyển kho? Tồn hai bên tự trả lại.'); });

    /**
     * Điền sẵn từ PO / quét tồn (tgs_po_adjustment): sessionStorage tgs_poa_copy_items | tgs_poa_ticket_prefill
     * {ticket_type, person: {id = blog nhận}, items: [{sku, quantity, note}], note, po_code}.
     */
    function prefillFromPo($to) {
        var data = null, key = null;
        ['tgs_poa_copy_items', 'tgs_poa_ticket_prefill'].some(function (k) {
            try { var v = JSON.parse(sessionStorage.getItem(k) || 'null'); if (v && /transfer|internal_(export|return)/.test(v.ticket_type || '')) { data = v; key = k; return true; } } catch (e) {}
            return false;
        });
        if (!data) return;
        try { sessionStorage.removeItem(key); } catch (e) {}
        if (data.note || data.po_code) $w.find('.gt-note').val(data.note || ('Tạo từ PO ' + data.po_code));
        var dest = data.person && +data.person.id;
        if (dest && dest !== cur) $to.val(String(dest)).trigger('change');
        var items = (data.items || []).filter(function (it) { return it.sku || it.product_sku; }), pending = items.length;
        if (!pending) return;
        msg('success', 'Đã điền sẵn ' + pending + ' dòng từ PO — kiểm tra kho, số lượng rồi Ghi.');
        items.forEach(function (it) {
            var sku = String(it.sku || it.product_sku);
            ajax('products', { search: sku }).done(function (r) {
                var p = (r.success ? r.data.items : []).find(function (x) { return String(x.sku).toUpperCase() === sku.toUpperCase(); });
                lines.push(p ? { product_id: p.product_id, sku: p.sku, product_name: p.name, unit_name: p.unit, quantity: +it.quantity || 1, price: p.price, tax_percent: p.tax, note: it.note || '' }
                    : { sku: sku, product_name: it.name || it.product_name || sku, unit_name: '', quantity: +it.quantity || 1, price: 0, tax_percent: 0, note: it.note || '' });
            }).always(function () { if (--pending === 0) render(); });
        });
    }

    function reasonOptions(list, value) {
        var $r = $w.find('.gt-reason').empty();
        if (!list.length) $r.append('<option value="">(chưa có danh mục lý do)</option>');
        list.forEach(function (x) { $r.append($('<option>').val(x.code).text(x.code + ' — ' + x.name)); });
        if (value) { if (!$r.find('option').filter(function () { return this.value === value; }).length) $r.append($('<option>').val(value).text(value)); $r.val(value); }
    }
    $w.find('.gt-reason-refresh').on('click', function (e) {
        e.preventDefault();
        var keep = $w.find('.gt-reason').val();
        ajax('options', { blog_id: cur, refresh_reasons: 1 }).done(function (r) { if (r.success) { reasonOptions(r.data.reasons, keep); msg('success', 'Đã kéo lại danh mục lý do (' + r.data.reasons.length + ').'); } });
    });
    var name = function (b) { var s = sites.find(function (x) { return x.blogId === b; }); return s ? s.name + (s.branchCode ? ' — ' + s.branchCode : '') : (b > 0 ? 'blog ' + b : 'Ngoài hệ thống'); };
    function fillSites($sel, value, skip) {
        $sel.empty();
        if ($sel.hasClass('gt-to-site')) $sel.append('<option value="">— Chọn —</option>');
        sites.forEach(function (s) { if (s.blogId !== skip) $sel.append($('<option>').val(s.blogId).text(name(s.blogId))); });
        if (value !== undefined && value !== null && value !== '') {
            if (!$sel.find('option[value="' + value + '"]').length) $sel.append($('<option>').val(value).text(name(+value)));
            $sel.val(String(value));
        }
    }
    $w.find('.gt-from-site').on('change', function () {
        var b = +this.value, keepTo = $w.find('.gt-to-site').val();
        loadZones(b, $w.find('.gt-from-zone'), '').always(render);
        fillSites($w.find('.gt-to-site'), +keepTo === b ? '' : keepTo, b);
        if (+keepTo === b) zoneOptions($w.find('.gt-to-zone'), [], '');
    });

    ajax('options', { blog_id: cur }).done(function (r) {
        if (!r.success) return msg('danger', r.data && r.data.message || 'Không tải được danh mục');
        sites = r.data.sites; canAll = !!r.data.canAll;
        var $to = $w.find('.gt-to-site');
        if (!id) {
            fillSites($w.find('.gt-from-site'), cur);
            fillSites($to, '', cur);
            zoneOptions($w.find('.gt-from-zone'), r.data.zones, '');
            reasonOptions(r.data.reasons, (r.data.reasons.find(function (x) { return x.isDefault; }) || {}).code);
            applyMode();
            prefillFromPo($to);
            return;
        }
        ajax('get', { transfer_id: id }).done(function (g) {
            if (!g.success) return msg('danger', g.data && g.data.message);
            loaded = g.data;
            var t = loaded, confirmed = +t.import_status === 1 || (+t.to_blog_id <= 0 && +t.export_status === 1);
            $w.find('.gt-code').val(t.transfer_code); $w.find('.gt-date').val(t.doc_date); $w.find('.gt-note').val(t.note || ''); $w.find('.gt-src').val(t.source_label || '');
            $w.find('.gt-status').val(+t.import_status === 2 ? 'Từ chối nhận' : (confirmed ? 'Đã xác nhận' : (+t.export_status === 1 ? 'Đang chờ xác nhận' : 'Nháp (chưa xuất)')));
            fillSites($w.find('.gt-from-site'), t.from_blog_id);
            fillSites($to, t.to_blog_id, +t.from_blog_id);
            reasonOptions(r.data.reasons, t.reason_code || '');
            lines = t.items.map(function (it) { return { item_id: +it.item_id, product_id: it.product_id, sku: it.sku, product_name: it.product_name, unit_name: it.unit_name, quantity: +it.quantity, received: it.received_qty == null ? '' : +it.received_qty, price: +it.price, tax_percent: +it.tax_percent, note: it.note }; });
            // Đang đứng ở chi nhánh nhập → nhận hàng; ở chi nhánh xuất (hoặc quản trị) → sửa; đã xác nhận / từ chối → chỉ xem.
            if (confirmed || +t.import_status === 2) mode = 'view';
            else if (+t.to_blog_id === cur && +t.export_status === 1) mode = 'receive';
            else if (+t.from_blog_id === cur || canAll) mode = 'edit';
            else mode = 'view';
            $.when(loadZones(+t.from_blog_id, $w.find('.gt-from-zone'), t.from_zone), +t.to_blog_id > 0 ? loadZones(+t.to_blog_id, $w.find('.gt-to-zone'), t.to_zone) : zoneOptions($w.find('.gt-to-zone'), [], t.to_zone)).always(applyMode);
            if (/[?&]saved=1/.test(location.search)) msg('success', 'Đã ghi phiếu.');
        });
    });
})(jQuery);
</script>
