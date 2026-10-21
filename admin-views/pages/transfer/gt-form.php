<?php
/**
 * Phiếu chuyển kho — "Nhập- xuất kho nội bộ" (?id= để mở phiếu đã có). Bố cục như form phiếu của phần mềm cũ:
 *   thanh công cụ trên cùng · đầu phiếu gọn hai bên · lưới "Chi tiết phiếu" nhập thẳng trên ô (luôn có một dòng trống cuối:
 *   gõ mã hàng → gợi ý → Enter chọn, nhảy sang Số lượng → Enter xuống dòng mới; dán nhiều dòng "mã hàng<Tab>số lượng" từ
 *   Excel) · dòng tổng.
 *   Bên XUẤT: Ghi = lưu và xuất kho luôn (tồn bên xuất trừ). Bên NHẬP: ghi Số lượng nhập → Xác nhận đã nhận đủ hàng.
 *   Phiếu đã xác nhận chỉ xem; muốn sửa phải bỏ xác nhận trước. Chỉ cộng / trừ số lượng.
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
        .gt-wrap .gt-tb { background: #f1f3f6; border: 1px solid #d5dae1; border-radius: 6px; padding: .3rem .5rem; }
        .gt-wrap .gt-tb .btn { padding: .2rem .6rem; font-size: .85rem; }
        .gt-wrap .gt-hd { background: #fff; border: 1px solid #d5dae1; border-radius: 6px; padding: .6rem .8rem; }
        .gt-wrap .gt-hd .row > div { display: flex; align-items: center; gap: .5rem; margin-bottom: .35rem; }
        .gt-wrap .gt-hd label { width: 110px; flex: 0 0 110px; margin: 0; font-size: .85rem; font-weight: 600; color: #344; }
        .gt-wrap .gt-hd .form-control, .gt-wrap .gt-hd .form-select { font-size: .85rem; }
        .gt-wrap .gt-ro .form-control, .gt-wrap .gt-ro .form-select { pointer-events: none; background: #f3f4f6; }
        .gt-wrap .gt-grid { border: 1px solid #b8c4d6; border-radius: 6px; overflow: auto; max-height: 60vh; background: #fff; }
        .gt-wrap .gt-grid table { margin: 0; border-collapse: collapse; width: 100%; font-size: .85rem; }
        .gt-wrap .gt-grid th { background: #dfe8f5; position: sticky; top: 0; z-index: 2; padding: .3rem .4rem; border: 1px solid #c3cfe0; white-space: nowrap; font-weight: 600; }
        .gt-wrap .gt-grid td { border: 1px solid #e1e6ee; padding: 0; height: 30px; white-space: nowrap; }
        .gt-wrap .gt-grid td.gt-txt { padding: 0 .4rem; }
        .gt-wrap .gt-grid td input { width: 100%; height: 30px; border: 0; padding: 0 .4rem; background: transparent; outline: none; font-size: .85rem; }
        .gt-wrap .gt-grid td input:focus { background: #fffbe0; box-shadow: inset 0 0 0 2px #4a90e2; }
        .gt-wrap .gt-grid td input.text-end { text-align: right; }
        .gt-wrap .gt-grid tr.gt-new td { background: #fbfcfe; }
        .gt-wrap .gt-grid tr:hover td { background: #f3f8ff; }
        .gt-wrap .gt-grid tfoot td { background: #fff7c2; font-weight: 600; padding: .3rem .4rem; position: sticky; bottom: 0; }
        .gt-wrap .gt-grid .gt-x { border: 0; background: none; color: #c0392b; padding: 0 .5rem; height: 30px; }
        .gt-wrap .gt-sugg { position: absolute; z-index: 50; background: #fff; border: 1px solid #9fb3cf; box-shadow: 0 6px 18px rgba(0,0,0,.15); max-height: 280px; overflow: auto; min-width: 520px; font-size: .85rem; }
        .gt-wrap .gt-sugg div { padding: .25rem .5rem; cursor: pointer; white-space: nowrap; }
        .gt-wrap .gt-sugg div.on, .gt-wrap .gt-sugg div:hover { background: #2f6fd6; color: #fff; }
        .gt-wrap .gt-sugg small { opacity: .75; margin-left: .5rem; }
    </style>

    <div class="d-flex align-items-center gap-1 flex-wrap gt-tb mb-2">
        <strong class="me-3"><i class="bx bx-transfer-alt me-1"></i>Nhập- xuất kho nội bộ</strong>
        <a class="btn btn-sm btn-outline-success" href="<?php echo esc_url(tgs_url('transfer-export-add')); ?>"><i class="bx bx-plus me-1"></i>Thêm</a>
        <button type="button" class="btn btn-sm btn-primary gt-save" style="display:none"><i class="bx bx-save me-1"></i>Ghi</button>
        <button type="button" class="btn btn-sm btn-outline-danger gt-delete" style="display:none"><i class="bx bx-x me-1"></i>Xóa</button>
        <button type="button" class="btn btn-sm btn-success gt-confirm" style="display:none"><i class="bx bx-check-double me-1"></i>Xác nhận đã nhận đủ hàng</button>
        <button type="button" class="btn btn-sm btn-outline-warning gt-unconfirm" style="display:none"><i class="bx bx-undo me-1"></i>Bỏ xác nhận</button>
        <button type="button" class="btn btn-sm btn-outline-secondary gt-print"><i class="bx bx-printer me-1"></i>In</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url(tgs_url('ticket-transfer-exports')); ?>"><i class="bx bx-exit me-1"></i>Đóng</a>
    </div>
    <div class="alert py-2 gt-msg" style="display:none"></div>

    <div class="gt-hd mb-2">
        <div class="row g-0 gx-4">
            <div class="col-lg-6">
                <div><label>Lý do</label><select class="form-select form-select-sm gt-reason"></select><a href="#" class="gt-reason-refresh" title="Kéo lại danh mục lý do từ phần mềm kế toán"><i class="bx bx-refresh"></i></a></div>
                <div><label>Chi nhánh xuất</label><select class="form-select form-select-sm gt-from-site"></select></div>
                <div><label>Kho xuất</label><select class="form-select form-select-sm gt-from-zone"></select></div>
                <div><label>Diễn giải</label><input class="form-control form-control-sm gt-note"></div>
            </div>
            <div class="col-lg-6">
                <div><label>Số phiếu</label><input class="form-control form-control-sm gt-code" disabled placeholder="(tự sinh khi ghi)"><label style="width:auto;flex:0 0 auto">Ngày</label><input type="date" class="form-control form-control-sm gt-date" style="max-width:170px" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></div>
                <div><label>Chi nhánh nhập</label><select class="form-select form-select-sm gt-to-site"></select></div>
                <div><label>Kho nhập</label><select class="form-select form-select-sm gt-to-zone"></select></div>
                <div><label>Trạng thái</label><input class="form-control form-control-sm gt-status" disabled value="Mới"><label style="width:auto;flex:0 0 auto">Chứng từ gốc</label><input class="form-control form-control-sm gt-src" disabled style="max-width:170px"></div>
            </div>
        </div>
    </div>

    <div class="d-flex align-items-center mb-1">
        <strong class="me-auto">Chi tiết phiếu</strong>
        <small class="text-muted gt-help">Gõ mã hàng / tên / mã vạch ở dòng trống → Enter chọn → Số lượng → Enter xuống dòng. Dán được nhiều dòng "mã hàng ⇥ số lượng" từ Excel.</small>
    </div>
    <div class="gt-grid" data-ds-no-grid data-ds-no-filter data-ds-no-colcfg data-ds-no-export data-ds-no-resize>
        <table data-ds-no-grid data-ds-no-filter data-ds-no-colcfg data-ds-no-export data-ds-no-resize>
            <thead><tr>
                <th style="width:42px">#</th><th style="width:150px">Mã hàng</th><th>Tên hàng</th><th style="width:90px">Kho xuất</th><th style="width:90px">Kho nhập</th>
                <th style="width:70px">ĐVT</th><th style="width:70px" class="text-end">Tồn</th><th style="width:95px" class="text-end">Số lượng</th><th style="width:105px" class="text-end">Số lượng nhập</th><th style="width:200px">Ghi chú</th><th style="width:34px"></th>
            </tr></thead>
            <tbody class="gt-lines"></tbody>
            <tfoot><tr><td class="text-center gt-tcount">0</td><td colspan="6" class="text-end">Tổng</td><td class="text-end gt-tqty">0</td><td class="text-end gt-trecv"></td><td colspan="2"></td></tr></tfoot>
        </table>
    </div>
    <div class="gt-sugg" style="display:none"></div>
</div>
<script>
(function ($) {
    var $w = $('.gt-wrap'), id = +$w.data('id'), nonce = $w.data('nonce'), cur = +$w.data('blog'), sa = +$w.data('sa') === 1, canAll = sa, sites = [];
    var lines = [], loaded = null, mode = 'new'; // new | edit (bên xuất sửa được) | receive (bên nhập chờ xác nhận) | view
    var listUrl = '<?php echo esc_url_raw(tgs_url('ticket-transfer-exports')); ?>', formUrl = '<?php echo esc_url_raw(tgs_url('transfer-export-add')); ?>';
    var fmt = function (n) { return (+n || 0).toLocaleString('vi-VN', { maximumFractionDigits: 3 }); };
    var esc = function (s) { return $('<div>').text(s == null ? '' : s).html(); };
    function ajax(action, data) { return $.post(ajaxurl, $.extend({ action: 'tgs_gt_' + action, nonce: nonce }, data || {})); }
    function msg(type, text) { $w.find('.gt-msg').removeClass('alert-success alert-danger alert-warning').addClass('alert-' + type).text(text).show(); }
    function editable() { return mode === 'new' || mode === 'edit'; }
    function zoneOptions($sel, zones, value) {
        $sel.empty();
        if (!zones.length) $sel.append('<option value="">(chưa có kho — đồng bộ phân kho)</option>');
        zones.forEach(function (z) { $sel.append($('<option>').val(z.code).text(z.code + ' — ' + z.name)); });
        if (value) { if (!$sel.find('option').filter(function () { return this.value === value; }).length) $sel.append($('<option>').val(value).text(value)); $sel.val(value); }
    }
    function loadZones(blog, $sel, value) { return ajax('options', { blog_id: blog }).done(function (r) { if (r.success) zoneOptions($sel, r.data.zones, value); }); }

    /* ---------------- lưới chi tiết ---------------- */
    function recv(l) { return l.received === '' || l.received == null ? l.quantity : l.received; }
    function totals() {
        var tq = 0, tr = 0;
        lines.forEach(function (l) { tq += +l.quantity || 0; tr += +recv(l) || 0; });
        $w.find('.gt-tcount').text(lines.length); $w.find('.gt-tqty').text(fmt(tq));
        $w.find('.gt-trecv').text(mode === 'receive' || (loaded && +loaded.import_status === 1) ? fmt(tr) : '');
    }
    function rowHtml(l, i) {
        var kx = esc($w.find('.gt-from-zone').val() || ''), kn = esc($w.find('.gt-to-zone').val() || '');
        var ed = editable(), rq = '';
        if (mode === 'receive') rq = '<input class="text-end gt-r" inputmode="decimal" value="' + esc(recv(l)) + '">';
        else if (loaded && +loaded.import_status === 1) rq = '<span class="px-2">' + fmt(recv(l)) + '</span>';
        return '<tr data-i="' + i + '"><td class="gt-txt text-center text-muted">' + (i + 1) + '</td>'
            + '<td class="gt-txt font-monospace">' + esc(l.sku) + '</td><td class="gt-txt">' + esc(l.product_name || '') + '</td>'
            + '<td class="gt-txt">' + kx + '</td><td class="gt-txt">' + kn + '</td><td class="gt-txt">' + esc(l.unit_name || '') + '</td>'
            + '<td class="gt-txt text-end text-muted">' + (l.stock === undefined ? '' : fmt(l.stock)) + '</td>'
            + '<td>' + (ed ? '<input class="text-end gt-q" inputmode="decimal" value="' + esc(l.quantity) + '">' : '<span class="px-2 d-block text-end">' + fmt(l.quantity) + '</span>') + '</td>'
            + '<td class="text-end">' + rq + '</td>'
            + '<td>' + (ed ? '<input class="gt-n" value="' + esc(l.note || '') + '">' : '<span class="px-2">' + esc(l.note || '') + '</span>') + '</td>'
            + '<td>' + (ed ? '<button type="button" class="gt-x" title="Xoá dòng (Ctrl+Delete)">✕</button>' : '') + '</td></tr>';
    }
    function render(focusNew) {
        var $b = $w.find('.gt-lines').empty();
        lines.forEach(function (l, i) { $b.append(rowHtml(l, i)); });
        if (editable()) {
            $b.append('<tr class="gt-new"><td class="gt-txt text-center text-muted">*</td><td colspan="2"><input class="gt-code-in" placeholder="Gõ mã hàng / tên / mã vạch…" autocomplete="off"></td><td colspan="8"></td></tr>');
            if (focusNew) $b.find('.gt-code-in').focus();
        }
        totals();
    }
    function addProduct(p, qty) {
        var ex = lines.find(function (l) { return String(l.sku).toUpperCase() === String(p.sku).toUpperCase(); });
        if (ex) { ex.quantity = (+ex.quantity || 0) + (qty || 1); return lines.indexOf(ex); }
        lines.push({ product_id: p.product_id, sku: p.sku, product_name: p.name, unit_name: p.unit, quantity: qty || 1, price: p.price, tax_percent: p.tax, stock: p.stock, note: '' });
        return lines.length - 1;
    }
    function focusQty(i) { var $i = $w.find('.gt-lines tr[data-i="' + i + '"] .gt-q'); $i.focus().select(); }

    // gợi ý mã hàng
    var $sg = $w.find('.gt-sugg'), sugg = [], sel = -1, timer = null, lastQ = '';
    function hideSugg() { $sg.hide(); sugg = []; sel = -1; }
    function showSugg($inp, items) {
        sugg = items; sel = items.length ? 0 : -1;
        if (!items.length) { $sg.html('<div class="text-muted">Không thấy mã hàng</div>'); }
        else $sg.html(items.map(function (p, k) { return '<div data-k="' + k + '" class="' + (k === 0 ? 'on' : '') + '"><b class="font-monospace">' + esc(p.sku) + '</b> ' + esc(p.name) + '<small>tồn ' + fmt(p.stock) + ' ' + esc(p.unit || '') + '</small></div>'; }).join(''));
        var o = $inp.offset(), wo = $w.offset();
        $sg.css({ left: o.left - wo.left, top: o.top - wo.top + $inp.outerHeight() }).show();
    }
    function search(q) { return ajax('products', { search: q }).then(function (r) { return r.success ? r.data.items : []; }); }
    function pick(p) {
        hideSugg();
        var i = addProduct(p, 1);
        render(false); focusQty(i);
    }
    $w.on('input', '.gt-code-in', function () {
        var $i = $(this), q = this.value.trim(); clearTimeout(timer);
        if (q.length < 2) return hideSugg();
        timer = setTimeout(function () { lastQ = q; search(q).then(function (items) { if (lastQ === q) showSugg($i, items); }); }, 200);
    }).on('keydown', '.gt-code-in', function (e) {
        var $i = $(this);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!sugg.length) return; e.preventDefault();
            sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + sugg.length) % sugg.length;
            $sg.children().removeClass('on').eq(sel).addClass('on')[0].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var q = this.value.trim(); if (!q) return;
            clearTimeout(timer);
            search(q).then(function (items) {
                var exact = items.find(function (p) { return String(p.sku).toUpperCase() === q.toUpperCase() || String(p.barcode) === q; });
                var chosen = exact || (sugg.length && sel >= 0 ? sugg[sel] : null) || (items.length === 1 ? items[0] : null);
                if (chosen) pick(chosen); else showSugg($i, items);
            });
        } else if (e.key === 'Escape') { hideSugg(); }
    }).on('paste', '.gt-code-in', function (e) {
        // Dán từ Excel: mỗi dòng "mã hàng<Tab>số lượng" (số lượng có thể bỏ trống = 1)
        var text = (e.originalEvent.clipboardData || window.clipboardData).getData('text') || '';
        var rows = text.split(/\r?\n/).map(function (r) { return r.split('\t'); }).filter(function (c) { return c[0] && c[0].trim(); });
        if (rows.length < 2 && !(rows[0] && rows[0].length > 1)) return; // dán 1 mã → để ô tự tìm
        e.preventDefault(); hideSugg();
        var left = rows.length, miss = [];
        msg('warning', 'Đang thêm ' + left + ' dòng…');
        rows.forEach(function (c) {
            var sku = c[0].trim(), qty = parseFloat(String(c[1] || '1').replace(/\./g, '').replace(',', '.')) || 1;
            search(sku).then(function (items) {
                var p = items.find(function (x) { return String(x.sku).toUpperCase() === sku.toUpperCase() || String(x.barcode) === sku; });
                if (p) addProduct(p, qty); else miss.push(sku);
            }).always(function () {
                if (--left === 0) { render(true); msg(miss.length ? 'warning' : 'success', miss.length ? 'Không thấy mã: ' + miss.join(', ') : 'Đã thêm ' + rows.length + ' dòng.'); }
            });
        });
    }).on('mousedown', '.gt-sugg div[data-k]', function (e) { e.preventDefault(); pick(sugg[+$(this).data('k')]); })
      .on('blur', '.gt-code-in', function () { setTimeout(hideSugg, 150); });

    // sửa ô số lượng / ghi chú / số lượng nhập
    $w.on('input', '.gt-q,.gt-n,.gt-r', function () {
        var i = +$(this).closest('tr').data('i'), v = this.value;
        if ($(this).hasClass('gt-q')) lines[i].quantity = v;
        if ($(this).hasClass('gt-n')) lines[i].note = v;
        if ($(this).hasClass('gt-r')) lines[i].received = v;
        totals();
    }).on('keydown', '.gt-q,.gt-n,.gt-r', function (e) {
        var $tr = $(this).closest('tr'), cls = '.' + this.className.split(' ').filter(function (c) { return /^gt-[qnr]$/.test(c); })[0];
        if (e.key === 'Enter' || e.key === 'ArrowDown') {
            e.preventDefault();
            var $next = $tr.next('tr').find(cls);
            if ($next.length) $next.focus().select(); else $w.find('.gt-code-in').focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault(); $tr.prev('tr').find(cls).focus().select();
        } else if (e.key === 'Delete' && e.ctrlKey && editable()) {
            e.preventDefault(); lines.splice(+$tr.data('i'), 1); render(true);
        }
    }).on('click', '.gt-x', function () { lines.splice(+$(this).closest('tr').data('i'), 1); render(true); });
    $w.find('.gt-from-zone,.gt-to-zone').on('change', function () { render(false); });

    /* ---------------- chế độ / nút ---------------- */
    function applyMode() {
        $w.find('.gt-hd').toggleClass('gt-ro', !editable());
        $w.find('.gt-save').toggle(editable());
        $w.find('.gt-confirm').toggle(mode === 'receive');
        $w.find('.gt-unconfirm').toggle(!!(loaded && +loaded.import_status === 1 && (canAll || +loaded.to_blog_id === cur)));
        $w.find('.gt-delete').toggle(!!(loaded && mode === 'edit'));
        $w.find('.gt-from-site').prop('disabled', !(editable() && canAll && !loaded));
        $w.find('.gt-help').toggle(editable());
        render(editable() && !loaded);
    }

    /* ---------------- danh mục ---------------- */
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
        loadZones(b, $w.find('.gt-from-zone'), '').always(function () { render(false); });
        fillSites($w.find('.gt-to-site'), +keepTo === b ? '' : keepTo, b);
        if (+keepTo === b) zoneOptions($w.find('.gt-to-zone'), [], '');
    });
    $w.find('.gt-to-site').on('change', function () {
        var b = +this.value;
        if (b) loadZones(b, $w.find('.gt-to-zone'), loaded && +loaded.to_blog_id === b ? loaded.to_zone : '').always(function () { render(false); });
        else { zoneOptions($w.find('.gt-to-zone'), [], ''); render(false); }
    });

    /* ---------------- ghi / thao tác ---------------- */
    $w.find('.gt-save').on('click', function () {
        if (!$w.find('.gt-to-site').val()) return msg('warning', 'Chưa chọn chi nhánh nhập');
        if ($w.find('.gt-to-site').val() === $w.find('.gt-from-site').val()) return msg('warning', 'Chi nhánh nhập trùng chi nhánh xuất');
        if (!lines.length) return msg('warning', 'Phiếu chưa có hàng');
        $w.find('.gt-save').prop('disabled', true);
        ajax('save', {
            transfer_id: id || 0, from_blog_id: $w.find('.gt-from-site').val(), reason_code: $w.find('.gt-reason').val() || '', to_blog_id: $w.find('.gt-to-site').val(),
            from_zone: $w.find('.gt-from-zone').val(), to_zone: $w.find('.gt-to-zone').val(),
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
        lines.forEach(function (l) { received[l.item_id] = recv(l); });
        act('approve_import', 'Xác nhận đã nhận đủ hàng? Tồn bên nhập cộng theo Số lượng nhập.', { received: JSON.stringify(received) });
    });
    $w.find('.gt-unconfirm').on('click', function () { act('unapprove_import', 'Bỏ xác nhận? Tồn bên nhập trừ lại.'); });
    $w.find('.gt-delete').on('click', function () { act('delete', 'Xóa phiếu chuyển kho? Tồn hai bên tự trả lại.'); });
    $w.find('.gt-print').on('click', function () { window.print(); });
    $(document).on('keydown.gtform', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 's' && editable()) { e.preventDefault(); $w.find('.gt-save').click(); } });

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
            search(sku).then(function (list) {
                var p = list.find(function (x) { return String(x.sku).toUpperCase() === sku.toUpperCase(); });
                if (p) { var i = addProduct(p, +it.quantity || 1); lines[i].note = it.note || ''; }
                else lines.push({ sku: sku, product_name: it.name || it.product_name || sku, unit_name: '', quantity: +it.quantity || 1, price: 0, tax_percent: 0, note: it.note || '' });
            }).always(function () { if (--pending === 0) render(false); });
        });
    }

    /* ---------------- nạp ---------------- */
    ajax('options', { blog_id: cur }).done(function (r) {
        if (!r.success) return msg('danger', r.data && r.data.message || 'Không tải được danh mục');
        sites = r.data.sites; canAll = !!r.data.canAll;
        var $to = $w.find('.gt-to-site');
        if (!id) {
            fillSites($w.find('.gt-from-site'), cur);
            fillSites($to, '', cur);
            zoneOptions($w.find('.gt-from-zone'), r.data.zones, '');
            zoneOptions($w.find('.gt-to-zone'), [], '');
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
