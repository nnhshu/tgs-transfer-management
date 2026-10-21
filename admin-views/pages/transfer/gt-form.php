<?php
/**
 * Phiếu chuyển kho — "Nhập-Xuất kho nội bộ" (?id= để mở phiếu đã có). Bố cục theo form phiếu chuyển kho của phần mềm cũ:
 *   thanh công cụ gọn: Ghi · Ghi và Đóng · Đóng (phiếu đã ghi thêm Xóa / In tuỳ quyền);
 *   khối trái 2 cột: Chi nhánh xuất | Chi nhánh nhập · Kho xuất | Kho nhập · Diễn giải;
 *   khối giữa: Số phiếu · Ngày · Trạng thái;
 *   khối phải "Thủ kho nhập xác nhận": ô tích "Xác nhận đã nhận đủ hàng" (bên nhập tích = duyệt nhận, bỏ tích = bỏ xác nhận);
 *   hàng dưới: Lý do xuất;
 *   nhóm "Chi tiết phiếu": ô Mã hàng (gõ mã / tên / mã vạch → Enter thêm dòng; dán nhiều dòng "mã hàng ⇥ số lượng" từ Excel)
 *   rồi lưới (sửa Số lượng / Số lượng nhập / Ghi chú ngay trên ô) · dòng tổng.
 * Ghi = lưu và xuất kho luôn (tồn bên xuất trừ). Phiếu đã xác nhận chỉ xem. Chỉ cộng / trừ số lượng.
 *
 * @package tgs-transfer-management
 */
if (!defined('ABSPATH')) {
    exit;
}
$gt_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$gt_nonce = wp_create_nonce(TGS_GT_Ajax::NONCE);
?>
<div class="container-fluid flex-grow-1 py-2 gt-wrap" data-id="<?php echo (int) $gt_id; ?>" data-nonce="<?php echo esc_attr($gt_nonce); ?>" data-blog="<?php echo (int) get_current_blog_id(); ?>" data-sa="<?php echo is_super_admin() ? 1 : 0; ?>">
    <style>
        .gt-wrap { font-size: 12.5px; }
        .gt-wrap .gt-hide { display: none !important; }
        .gt-wrap .gt-tb { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; background: linear-gradient(#f7f9fc, #e7edf5); border: 1px solid #c6d1e0; border-radius: 4px; padding: 2px 4px; margin-bottom: 6px; }
        .gt-wrap .gt-tb .gt-title { font-weight: 700; margin-right: 10px; }
        .gt-wrap .gt-tb button, .gt-wrap .gt-tb a { display: inline-flex; align-items: center; gap: 3px; border: 1px solid transparent; background: none; padding: 2px 8px; border-radius: 3px; font-size: 12.5px; color: #1d2b3a; text-decoration: none; line-height: 20px; }
        .gt-wrap .gt-tb button:hover, .gt-wrap .gt-tb a:hover { border-color: #9db4d4; background: #fff; }
        .gt-wrap .gt-tb i { font-size: 15px; }
        .gt-wrap .gt-tb .sep { width: 1px; height: 18px; background: #c6d1e0; margin: 0 4px; }
        .gt-wrap fieldset { border: 1px solid #c6d1e0; border-radius: 4px; padding: 2px 8px 6px; margin: 0; background: #fff; }
        .gt-wrap legend { float: none; width: auto; font-size: 12px; font-weight: 600; color: #2a4d7f; padding: 0 4px; margin: 0; }
        .gt-wrap .gt-row { display: flex; align-items: center; gap: 6px; margin-top: 4px; }
        .gt-wrap .gt-row label { flex: 0 0 92px; margin: 0; color: #223; }
        .gt-wrap .gt-row label.w2 { flex-basis: 96px; padding-left: 8px; }
        .gt-wrap .gt-row .ctl { flex: 1 1 0; min-width: 0; }
        .gt-wrap input.form-control, .gt-wrap select.form-select, .gt-wrap textarea.form-control { font-size: 12.5px; height: 25px; min-height: 25px; padding: 1px 6px; border-radius: 3px; border-color: #b9c4d3; }
        .gt-wrap select.form-select { padding-right: 22px; background-position: right 5px center; }
        .gt-wrap textarea.form-control { height: 44px; }
        .gt-wrap .gt-ro .form-control, .gt-wrap .gt-ro .form-select { pointer-events: none; background-color: #f1f3f6; }
        .gt-wrap .gt-status { font-weight: 700; color: #b02a37; min-height: 25px; display: flex; align-items: center; }
        .gt-wrap .gt-status.ok { color: #1e7e34; }
        .gt-wrap .gt-detail { border: 1px solid #c6d1e0; border-radius: 4px; background: #fff; margin-top: 6px; }
        .gt-wrap .gt-detail-hd { display: flex; align-items: center; gap: 8px; padding: 4px 6px; background: #eef3fa; border-bottom: 1px solid #c6d1e0; position: relative; }
        .gt-wrap .gt-detail-hd b { color: #2a4d7f; }
        .gt-wrap .gt-grid { overflow: auto; max-height: 58vh; }
        .gt-wrap .gt-grid table { width: 100%; border-collapse: collapse; }
        .gt-wrap .gt-grid th { background: #dfe8f5; position: sticky; top: 0; z-index: 2; padding: 3px 5px; border: 1px solid #c3cfe0; white-space: nowrap; font-weight: 600; }
        .gt-wrap .gt-grid td { border: 1px solid #e1e6ee; padding: 0 5px; height: 24px; white-space: nowrap; }
        .gt-wrap .gt-grid td.ed { padding: 0; }
        .gt-wrap .gt-grid td input { width: 100%; height: 23px; border: 0; padding: 0 5px; background: transparent; outline: none; font-size: 12.5px; }
        .gt-wrap .gt-grid td input:focus { background: #fffbe0; box-shadow: inset 0 0 0 1px #4a90e2; }
        .gt-wrap .gt-grid tbody tr:hover td { background: #f3f8ff; }
        .gt-wrap .gt-grid tfoot td { background: #fff7c2; font-weight: 700; position: sticky; bottom: 0; }
        .gt-wrap .gt-grid .gt-x { border: 0; background: none; color: #c0392b; padding: 0 4px; }
        .gt-wrap .gt-sugg { position: absolute; z-index: 50; background: #fff; border: 1px solid #9fb3cf; box-shadow: 0 6px 18px rgba(0,0,0,.15); max-height: 280px; overflow: auto; min-width: 560px; }
        .gt-wrap .gt-sugg div { padding: 3px 6px; cursor: pointer; white-space: nowrap; }
        .gt-wrap .gt-sugg div.on, .gt-wrap .gt-sugg div:hover { background: #2f6fd6; color: #fff; }
        .gt-wrap .gt-sugg small { opacity: .75; margin-left: 6px; }
        .gt-wrap .gt-msg { padding: 3px 8px; margin-bottom: 6px; font-size: 12.5px; }
        @media print { .gt-wrap .gt-tb, .gt-wrap .gt-add, .gt-wrap .gt-help, .gt-wrap .gt-x { display: none !important; } }
    </style>

    <div class="gt-tb">
        <span class="gt-title"><i class="bx bx-transfer-alt"></i> Nhập-Xuất kho nội bộ</span>
        <button type="button" class="gt-edit gt-hide"><i class="bx bx-edit text-primary"></i>Sửa</button>
        <button type="button" class="gt-save gt-hide"><i class="bx bx-save text-primary"></i>Ghi</button>
        <button type="button" class="gt-save-close gt-hide"><i class="bx bx-save"></i>Ghi và Đóng</button>
        <button type="button" class="gt-print gt-hide"><i class="bx bx-printer"></i>In</button>
        <button type="button" class="gt-delete gt-hide"><i class="bx bx-x text-danger"></i>Xóa</button>
        <button type="button" class="gt-cancel gt-hide"><i class="bx bx-undo"></i>Hủy sửa</button>
        <span class="sep"></span>
        <a href="<?php echo esc_url(tgs_url('ticket-transfer-exports')); ?>"><i class="bx bx-exit text-danger"></i>Đóng</a>
    </div>
    <div class="alert gt-msg gt-hide"></div>

    <div class="d-flex gap-2 align-items-stretch flex-wrap gt-hd">
        <fieldset style="flex:1 1 560px">
            <legend>Thông tin chuyển kho</legend>
            <div class="gt-row"><label>Chi nhánh xuất</label><select class="form-select ctl gt-from-site"></select><label class="w2">Chi nhánh nhập</label><select class="form-select ctl gt-to-site"></select></div>
            <div class="gt-row"><label>Kho xuất</label><select class="form-select ctl gt-from-zone"></select><label class="w2">Kho nhập</label><select class="form-select ctl gt-to-zone"></select></div>
            <div class="gt-row" style="align-items:flex-start"><label style="padding-top:3px">Diễn giải</label><textarea class="form-control ctl gt-note"></textarea></div>
        </fieldset>
        <fieldset style="flex:0 0 250px">
            <legend>Phiếu</legend>
            <div class="gt-row"><label style="flex-basis:62px">Số phiếu</label><input class="form-control ctl gt-code fw-bold" disabled placeholder="(tự sinh)"></div>
            <div class="gt-row"><label style="flex-basis:62px">Ngày</label><input type="date" class="form-control ctl gt-date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></div>
            <div class="gt-row"><label style="flex-basis:62px">Trạng thái</label><span class="ctl gt-status">Mới</span></div>
            <div class="gt-row"><label style="flex-basis:62px">CT gốc</label><input class="form-control ctl gt-src" disabled></div>
        </fieldset>
        <fieldset style="flex:0 0 250px">
            <legend>Thủ kho nhập xác nhận</legend>
            <div class="form-check mt-2"><input class="form-check-input gt-approved" type="checkbox" id="gtApproved" disabled><label class="form-check-label fw-semibold" for="gtApproved">Xác nhận đã nhận đủ hàng</label></div>
            <div class="text-muted mt-1 gt-approved-info" style="font-size:11.5px"></div>
        </fieldset>
    </div>
    <div class="gt-row gt-hd2" style="max-width:560px"><label>Lý do xuất</label><select class="form-select ctl gt-reason"></select><a href="#" class="gt-reason-refresh" title="Kéo lại danh mục lý do từ phần mềm kế toán"><i class="bx bx-refresh"></i></a></div>

    <div class="gt-detail">
        <div class="gt-detail-hd">
            <b>Chi tiết phiếu</b>
            <span class="gt-add d-flex align-items-center gap-1"><span class="fw-semibold ms-2">Mã hàng</span><input class="form-control gt-code-in" style="width:280px" placeholder="Gõ mã / tên / mã vạch — Enter" autocomplete="off">
                <label class="btn btn-sm btn-outline-success mb-0 ms-2 py-0 px-2" style="font-size:12.5px;line-height:23px" title="Nhập chi tiết phiếu từ file Excel: Mã hàng | Số lượng | Ghi chú"><i class="bx bx-spreadsheet me-1"></i>Nhập Excel<input type="file" class="gt-xls d-none" accept=".xlsx,.xls,.csv"></label>
                <a href="#" class="gt-xls-tpl ms-1" style="font-size:12px" title="Tải file Excel mẫu">File mẫu</a></span>
            <small class="text-muted ms-auto gt-help">Enter thêm dòng · ↑↓ chọn gợi ý · dán / Nhập Excel nhiều dòng (Mã hàng, Số lượng, Ghi chú) · Ctrl+Delete xoá dòng · Ctrl+S ghi</small>
            <div class="gt-sugg gt-hide"></div>
        </div>
        <div class="gt-grid">
            <table data-ds-no-grid data-ds-no-filter data-ds-no-colcfg data-ds-no-export data-ds-no-resize>
                <thead><tr>
                    <th style="width:36px">#</th><th style="width:80px">Kho xuất</th><th style="width:80px">Kho nhập</th><th style="width:130px">Mã hàng</th><th>Tên hàng</th>
                    <th style="width:60px">ĐVT</th><th style="width:70px" class="text-end">Tồn</th><th style="width:90px" class="text-end">Số lượng</th><th style="width:100px" class="text-end">Số lượng nhập</th><th style="width:180px">Ghi chú</th><th style="width:26px"></th>
                </tr></thead>
                <tbody class="gt-lines"></tbody>
                <tfoot><tr><td class="text-center gt-tcount">0</td><td colspan="6" class="text-end">Tổng</td><td class="text-end gt-tqty">0</td><td class="text-end gt-trecv"></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
    </div>
</div>
<script>
(function ($) {
    var $w = $('.gt-wrap'), id = +$w.data('id'), nonce = $w.data('nonce'), cur = +$w.data('blog'), sa = +$w.data('sa') === 1, canAll = sa, sites = [];
    var lines = [], loaded = null, mode = 'new', canEdit = false; // new | edit (bên xuất sửa được) | receive (bên nhập chờ xác nhận) | view
    var listUrl = '<?php echo esc_url_raw(tgs_url('ticket-transfer-exports')); ?>', formUrl = '<?php echo esc_url_raw(tgs_url('transfer-export-add')); ?>';
    var fmt = function (n) { return (+n || 0).toLocaleString('vi-VN', { maximumFractionDigits: 3 }); };
    var esc = function (s) { return $('<div>').text(s == null ? '' : s).html(); };
    function ajax(action, data) { return $.post(ajaxurl, $.extend({ action: 'tgs_gt_' + action, nonce: nonce }, data || {})); }
    function msg(type, text) { $w.find('.gt-msg').removeClass('alert-success alert-danger alert-warning gt-hide').addClass('alert-' + type).text(text); }
    function editable() { return mode === 'new' || mode === 'edit'; }
    function zoneOptions($sel, zones, value, emptyText) {
        $sel.empty();
        if (!zones.length) $sel.append($('<option value="">').text(emptyText || '(chưa có kho — đồng bộ phân kho)'));
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
    function render() {
        var $b = $w.find('.gt-lines').empty(), ed = editable(), kx = esc($w.find('.gt-from-zone').val() || ''), kn = esc($w.find('.gt-to-zone').val() || '');
        if (!lines.length) $b.append('<tr><td colspan="11" class="text-center text-muted" style="height:60px">' + (ed ? 'Chưa có hàng — gõ mã hàng ở ô "Mã hàng" phía trên rồi Enter.' : 'Phiếu không có dòng hàng.') + '</td></tr>');
        lines.forEach(function (l, i) {
            var rq = '';
            if (mode === 'receive') rq = '<input class="text-end gt-r" inputmode="decimal" value="' + esc(recv(l)) + '">';
            else if (loaded && +loaded.import_status === 1) rq = fmt(recv(l));
            $b.append('<tr data-i="' + i + '"><td class="text-center text-muted">' + (i + 1) + '</td><td>' + kx + '</td><td>' + kn + '</td>'
                + '<td class="font-monospace">' + esc(l.sku) + '</td><td>' + esc(l.product_name || '') + '</td><td>' + esc(l.unit_name || '') + '</td>'
                + '<td class="text-end text-muted">' + (l.stock === undefined ? '' : fmt(l.stock)) + '</td>'
                + (ed ? '<td class="ed"><input class="text-end gt-q" inputmode="decimal" value="' + esc(l.quantity) + '"></td>' : '<td class="text-end">' + fmt(l.quantity) + '</td>')
                + (mode === 'receive' ? '<td class="ed">' + rq + '</td>' : '<td class="text-end">' + rq + '</td>')
                + (ed ? '<td class="ed"><input class="gt-n" value="' + esc(l.note || '') + '"></td>' : '<td>' + esc(l.note || '') + '</td>')
                + '<td class="text-center">' + (ed ? '<button type="button" class="gt-x" title="Xoá dòng (Ctrl+Delete)">✕</button>' : '') + '</td></tr>');
        });
        totals();
    }
    function addProduct(p, qty) {
        var ex = lines.find(function (l) { return String(l.sku).toUpperCase() === String(p.sku).toUpperCase(); });
        if (ex) { ex.quantity = (+ex.quantity || 0) + (qty || 1); return lines.indexOf(ex); }
        lines.push({ product_id: p.product_id, sku: p.sku, product_name: p.name, unit_name: p.unit, quantity: qty || 1, price: p.price, tax_percent: p.tax, stock: p.stock, note: '' });
        return lines.length - 1;
    }
    function focusQty(i) { $w.find('.gt-lines tr[data-i="' + i + '"] .gt-q').focus().select(); }

    // ô Mã hàng + gợi ý
    var $in = $w.find('.gt-code-in'), $sg = $w.find('.gt-sugg'), sugg = [], sel = -1, timer = null, lastQ = '';
    function hideSugg() { $sg.addClass('gt-hide'); sugg = []; sel = -1; }
    function showSugg(items) {
        sugg = items; sel = items.length ? 0 : -1;
        $sg.html(items.length ? items.map(function (p, k) { return '<div data-k="' + k + '" class="' + (k === 0 ? 'on' : '') + '"><b class="font-monospace">' + esc(p.sku) + '</b> ' + esc(p.name) + '<small>' + esc(p.unit || '') + (p.stock !== undefined ? ' · tồn ' + fmt(p.stock) : '') + '</small></div>'; }).join('') : '<div class="text-muted">Không thấy mã hàng</div>');
        $sg.css({ left: $in.position().left, top: $in.position().top + $in.outerHeight() + 2 }).removeClass('gt-hide');
    }
    /*
     * Danh mục mặt hàng giữ tại trình duyệt (localStorage, kiểm phiên bản mỗi lần mở form) → tìm tức thì, không gọi máy chủ
     * mỗi phím. Chưa nạp xong thì tạm hỏi máy chủ.
     */
    var CAT = null, CAT_KEY = 'tgs_gt_catalog_v1', catReady = $.Deferred();
    function norm(x) { return String(x || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd'); }
    function buildCat(rows) {
        CAT = rows.map(function (x) { return { product_id: x[0], sku: x[1], name: x[2], unit: x[3], barcode: x[4], price: x[5], tax: x[6], k: norm(x[1] + ' ' + x[2] + ' ' + x[4]) }; });
        catReady.resolve();
    }
    function loadCatalog() {
        var cached = null;
        try { cached = JSON.parse(localStorage.getItem(CAT_KEY) || 'null'); } catch (e) {}
        if (cached && cached.rows) buildCat(cached.rows);
        ajax('catalog', { ver: cached && cached.ver || '' }).done(function (r) {
            if (!r.success || r.data.same) return;
            buildCat(r.data.rows);
            try { localStorage.setItem(CAT_KEY, JSON.stringify(r.data)); } catch (e) {}
        });
    }
    function localSearch(q) {
        var raw = String(q).trim(), n = norm(raw), toks = n.split(/\s+/).filter(Boolean), exact = [], pre = [], other = [];
        for (var i = 0; i < CAT.length; i++) {
            var p = CAT[i], ps = p.sku.toLowerCase();
            if (ps === n || (p.barcode && p.barcode === raw)) exact.push(p);
            else if (ps.indexOf(n) === 0) { if (pre.length < 30) pre.push(p); }
            else if (other.length < 30 && toks.every(function (t) { return p.k.indexOf(t) >= 0; })) other.push(p);
        }
        return exact.concat(pre, other).slice(0, 30);
    }
    function search(q) {
        if (CAT) return $.Deferred().resolve(localSearch(q)).promise();
        return ajax('products', { search: q }).then(function (r) { return r.success ? r.data.items : []; });
    }
    /** Tồn kho tại chi nhánh xuất của các mã — điền thẳng vào ô Tồn, không vẽ lại lưới (giữ con trỏ đang gõ). */
    function fetchStock(skus) {
        skus = (skus || lines.map(function (l) { return l.sku; })).filter(Boolean);
        if (!skus.length) return;
        ajax('stock', { blog_id: $w.find('.gt-from-site').val() || cur, skus: JSON.stringify(skus) }).done(function (r) {
            if (!r.success) return;
            lines.forEach(function (l, i) {
                if (r.data[l.sku] === undefined) return;
                l.stock = r.data[l.sku];
                $w.find('.gt-lines tr[data-i="' + i + '"] td').eq(6).text(fmt(l.stock));
            });
        });
    }
    function pick(p) { hideSugg(); $in.val(''); var i = addProduct(p, 1); render(); focusQty(i); fetchStock([p.sku]); }
    $in.on('input', function () {
        var q = this.value.trim(); clearTimeout(timer);
        if (q.length < 2) return hideSugg();
        timer = setTimeout(function () { lastQ = q; search(q).then(function (items) { if (lastQ === q) showSugg(items); }); }, CAT ? 0 : 200);
    }).on('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!sugg.length) return; e.preventDefault();
            sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + sugg.length) % sugg.length;
            $sg.children().removeClass('on').eq(sel).addClass('on')[0].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var q = this.value.trim(); if (!q) return;
            clearTimeout(timer);
            if (sugg.length && sel >= 0 && lastQ === q) return pick(sugg[sel]);
            search(q).then(function (items) {
                var exact = items.find(function (p) { return String(p.sku).toUpperCase() === q.toUpperCase() || String(p.barcode) === q; });
                if (exact || items.length === 1) pick(exact || items[0]); else { lastQ = q; showSugg(items); }
            });
        } else if (e.key === 'Escape') { hideSugg(); }
    }).on('paste', function (e) {
        // Dán từ Excel: mỗi dòng "mã hàng<Tab>số lượng" (bỏ trống số lượng = 1)
        var text = (e.originalEvent.clipboardData || window.clipboardData).getData('text') || '';
        var rows = text.split(/\r?\n/).map(function (r) { return r.split('\t'); }).filter(function (c) { return c[0] && c[0].trim(); });
        if (rows.length < 2 && !(rows[0] && rows[0].length > 1)) return;
        e.preventDefault(); hideSugg();
        addRows(rows.map(function (c) { return { sku: c[0], qty: c[1], note: c[2] }; }));
    }).on('blur', function () { setTimeout(hideSugg, 150); });

    /** Số lượng từ ô Excel / chữ dán: 12 · "12" · "1.200" · "1,5" · "1,200.5" (trống = 1). */
    function parseQty(v) {
        if (typeof v === 'number') return v > 0 ? v : 1;
        var raw = String(v == null ? '' : v).trim();
        if (!raw) return 1;
        var n = /,\d{1,3}$/.test(raw) && !/\.\d{1,3}$/.test(raw) ? raw.replace(/\./g, '').replace(',', '.') : raw.replace(/,/g, '');
        return parseFloat(n) || 1;
    }
    /** Thêm nhiều dòng [{sku, qty, note}] (dán / Nhập Excel): khớp theo mã hàng hoặc mã vạch; mã trùng cộng dồn số lượng. */
    function addRows(rows) {
        rows = rows.filter(function (r) { return String(r.sku == null ? '' : r.sku).trim(); });
        if (!rows.length) return msg('warning', 'Không có dòng nào có mã hàng.');
        if (!CAT) { msg('warning', 'Đang tải danh mục mặt hàng… sẽ tự thêm ' + rows.length + ' dòng khi xong.'); catReady.done(function () { addRows(rows); }); return; }
        var left = rows.length, miss = [], added = 0;
        msg('warning', 'Đang thêm ' + left + ' dòng…');
        rows.forEach(function (r) {
            var sku = String(r.sku).trim(), qty = parseQty(r.qty), note = String(r.note == null ? '' : r.note).trim();
            search(sku).then(function (items) {
                var p = items.find(function (x) { return String(x.sku).toUpperCase() === sku.toUpperCase() || String(x.barcode) === sku; });
                if (!p) { miss.push(sku); return; }
                var i = addProduct(p, qty); added++;
                if (note) lines[i].note = lines[i].note ? lines[i].note + '; ' + note : note;
            }).always(function () {
                if (--left > 0) return;
                render(); fetchStock(); $in.focus();
                msg(miss.length ? 'warning' : 'success', 'Đã thêm ' + added + ' dòng.' + (miss.length ? ' Không thấy ' + miss.length + ' mã: ' + miss.slice(0, 30).join(', ') + (miss.length > 30 ? '…' : '') : ''));
            });
        });
    }

    /* ---------------- Nhập Excel ---------------- */
    function withXlsx(cb) {
        if (window.XLSX) return cb();
        $.getScript('https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js').done(cb).fail(function () { msg('danger', 'Không tải được thư viện đọc Excel.'); });
    }
    $w.find('.gt-xls').on('change', function () {
        var f = this.files && this.files[0], input = this;
        if (!f) return;
        withXlsx(function () {
            var fr = new FileReader();
            fr.onload = function (ev) {
                try {
                    var wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array' });
                    var data = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, defval: '', raw: true });
                    // Tìm dòng tiêu đề trong 10 dòng đầu: cột Mã hàng / Số lượng / Ghi chú; không có thì A = mã, B = SL, C = ghi chú.
                    var hi = -1, ci = 0, qi = 1, ni = 2;
                    for (var r = 0; r < Math.min(10, data.length) && hi < 0; r++) {
                        var cols = data[r].map(function (c) { return norm(c).trim(); });
                        var c1 = cols.findIndex(function (c) { return /^(ma hang|ma sp|ma san pham|ma|sku|code|item code)$/.test(c); });
                        if (c1 < 0) continue;
                        hi = r; ci = c1;
                        qi = cols.findIndex(function (c) { return /^(so luong|sl|qty|quantity|sl xuat|so luong xuat)$/.test(c); });
                        ni = cols.findIndex(function (c) { return /^(ghi chu|note|dien giai)$/.test(c); });
                    }
                    var rows = data.slice(hi + 1).map(function (row) { return { sku: row[ci], qty: qi >= 0 ? row[qi] : 1, note: ni >= 0 ? row[ni] : '' }; });
                    addRows(rows);
                } catch (e) { msg('danger', 'Không đọc được file Excel: ' + e.message); }
                input.value = '';
            };
            fr.readAsArrayBuffer(f);
        });
    });
    $w.find('.gt-xls-tpl').on('click', function (e) {
        e.preventDefault();
        withXlsx(function () {
            var ws = XLSX.utils.aoa_to_sheet([['Mã hàng', 'Số lượng', 'Ghi chú'], ['100861007', 10, ''], ['100861008', 5, 'ví dụ ghi chú']]);
            ws['!cols'] = [{ wch: 16 }, { wch: 10 }, { wch: 30 }];
            var wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Chi tiết phiếu');
            XLSX.writeFile(wb, 'mau-phieu-chuyen-kho.xlsx');
        });
    });
    $sg.on('mousedown', 'div[data-k]', function (e) { e.preventDefault(); pick(sugg[+$(this).data('k')]); });

    // sửa ô trên lưới
    $w.on('input', '.gt-q,.gt-n,.gt-r', function () {
        var i = +$(this).closest('tr').data('i'), v = this.value;
        if ($(this).hasClass('gt-q')) lines[i].quantity = v;
        if ($(this).hasClass('gt-n')) lines[i].note = v;
        if ($(this).hasClass('gt-r')) lines[i].received = v;
        totals();
    }).on('keydown', '.gt-q,.gt-n,.gt-r', function (e) {
        var $tr = $(this).closest('tr'), cls = $(this).hasClass('gt-q') ? '.gt-q' : ($(this).hasClass('gt-n') ? '.gt-n' : '.gt-r');
        if (e.key === 'Enter') { e.preventDefault(); if (cls === '.gt-q') { $in.focus(); return; } var $n = $tr.next('tr').find(cls); if ($n.length) $n.focus().select(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); $tr.next('tr').find(cls).focus().select(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); $tr.prev('tr').find(cls).focus().select(); }
        else if (e.key === 'Delete' && e.ctrlKey && editable()) { e.preventDefault(); lines.splice(+$tr.data('i'), 1); render(); $in.focus(); }
    }).on('click', '.gt-x', function () { lines.splice(+$(this).closest('tr').data('i'), 1); render(); });
    $w.find('.gt-from-zone,.gt-to-zone').on('change', render);

    /* ---------------- chế độ ---------------- */
    function applyMode() {
        var ed = editable();
        $w.find('.gt-hd, .gt-hd2').toggleClass('gt-ro', !ed);
        $w.find('.gt-save, .gt-save-close').toggleClass('gt-hide', !ed);
        $w.find('.gt-delete').toggleClass('gt-hide', !(loaded && canEdit && mode !== 'edit' && +loaded.import_status !== 1));
        $w.find('.gt-edit').toggleClass('gt-hide', !(loaded && canEdit && mode !== 'edit'));
        $w.find('.gt-cancel').toggleClass('gt-hide', !(loaded && mode === 'edit'));
        $w.find('.gt-print').toggleClass('gt-hide', !loaded);
        $w.find('.gt-add, .gt-help').toggleClass('gt-hide', !ed);
        $w.find('.gt-from-site').prop('disabled', !(ed && canAll && !loaded));
        var confirmed = loaded && +loaded.import_status === 1;
        var canUnconfirm = confirmed && (canAll || +loaded.to_blog_id === cur);
        // Tích được khi: bên nhập đang chờ nhận; hoặc kế toán xác nhận thay (phiếu đã xuất, có chi nhánh nhập); bỏ tích khi có quyền.
        var canConfirm = mode === 'receive' || (loaded && !confirmed && canAll && +loaded.export_status === 1 && +loaded.to_blog_id > 0);
        $w.find('.gt-approved').prop('checked', !!confirmed).prop('disabled', !(canConfirm || canUnconfirm));
        $w.find('.gt-approved-info').text(confirmed ? ('Đã xác nhận: ' + (loaded.import_approved_at || '')) : (mode === 'receive' ? 'Ghi Số lượng nhập trên lưới rồi tích để xác nhận.' : ''));
        render();
        if (ed && !loaded) $in.focus();
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
        loadZones(b, $w.find('.gt-from-zone'), '').always(function () { render(); fetchStock(); });
        fillSites($w.find('.gt-to-site'), +keepTo === b ? '' : keepTo, b);
        if (+keepTo === b) zoneOptions($w.find('.gt-to-zone'), [], '', '— Chọn chi nhánh nhập trước —');
    });
    $w.find('.gt-to-site').on('change', function () {
        var b = +this.value;
        if (b) loadZones(b, $w.find('.gt-to-zone'), loaded && +loaded.to_blog_id === b ? loaded.to_zone : '').always(render);
        else { zoneOptions($w.find('.gt-to-zone'), [], '', '— Chọn chi nhánh nhập trước —'); render(); }
    });

    /* ---------------- ghi / thao tác ---------------- */
    function save(close) {
        if (!$w.find('.gt-to-site').val()) return msg('warning', 'Chưa chọn chi nhánh nhập');
        if ($w.find('.gt-to-site').val() === $w.find('.gt-from-site').val()) return msg('warning', 'Chi nhánh nhập trùng chi nhánh xuất');
        if (!lines.length) return msg('warning', 'Phiếu chưa có hàng');
        $w.find('.gt-save, .gt-save-close').prop('disabled', true);
        ajax('save', {
            transfer_id: id || 0, from_blog_id: $w.find('.gt-from-site').val(), reason_code: $w.find('.gt-reason').val() || '', to_blog_id: $w.find('.gt-to-site').val(),
            from_zone: $w.find('.gt-from-zone').val(), to_zone: $w.find('.gt-to-zone').val(),
            doc_date: $w.find('.gt-date').val(), transfer_type: loaded ? loaded.transfer_type : 1, note: $w.find('.gt-note').val(), approve: 1,
            items: JSON.stringify(lines.map(function (l) { return { product_id: l.product_id, sku: l.sku, product_name: l.product_name, unit_name: l.unit_name, quantity: l.quantity, price: l.price, tax_percent: l.tax_percent, note: l.note }; }))
        }).done(function (r) {
            $w.find('.gt-save, .gt-save-close').prop('disabled', false);
            if (!r.success) return msg('danger', r.data && r.data.message || 'Ghi thất bại');
            window.location.href = close ? listUrl : formUrl + '&id=' + r.data.transfer_id + '&saved=1';
        }).fail(function () { $w.find('.gt-save, .gt-save-close').prop('disabled', false); msg('danger', 'Lỗi mạng'); });
    }
    $w.find('.gt-save').on('click', function () { save(false); });
    $w.find('.gt-edit').on('click', function () { mode = 'edit'; applyMode(); fetchStock(); $in.focus(); });
    $w.find('.gt-cancel').on('click', function () { location.href = formUrl + '&id=' + id; });
    $w.find('.gt-save-close').on('click', function () { save(true); });
    function act(doVal, confirmText, extra) {
        if (confirmText && !confirm(confirmText)) { applyMode(); return; }
        ajax('action', $.extend({ transfer_id: id, do: doVal }, extra || {})).done(function (r) {
            if (!r.success) { msg('danger', r.data && r.data.message || 'Thao tác thất bại'); applyMode(); return; }
            if (doVal === 'delete') { window.location.href = listUrl; return; }
            window.location.href = formUrl + '&id=' + id + '&saved=1';
        });
    }
    $w.find('.gt-approved').on('change', function () {
        if (this.checked) {
            var received = {};
            lines.forEach(function (l) { received[l.item_id] = recv(l); });
            act('approve_import', 'Xác nhận đã nhận đủ hàng? Tồn bên nhập cộng theo Số lượng nhập.', { received: JSON.stringify(received) });
        } else {
            act('unapprove_import', 'Bỏ xác nhận? Tồn bên nhập trừ lại.');
        }
    });
    $w.find('.gt-delete').on('click', function () { act('delete', 'Xóa phiếu chuyển kho? Tồn hai bên tự trả lại.'); });
    $w.find('.gt-print').on('click', function () { window.print(); });
    $(document).on('keydown.gtform', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 's' && editable()) { e.preventDefault(); save(false); } });

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
            }).always(function () { if (--pending === 0) { render(); fetchStock(); } });
        });
    }

    /* ---------------- nạp ---------------- */
    loadCatalog();
    ajax('options', { blog_id: cur }).done(function (r) {
        if (!r.success) return msg('danger', r.data && r.data.message || 'Không tải được danh mục');
        sites = r.data.sites; canAll = !!r.data.canAll;
        var $to = $w.find('.gt-to-site');
        if (!id) {
            fillSites($w.find('.gt-from-site'), cur);
            fillSites($to, '', cur);
            zoneOptions($w.find('.gt-from-zone'), r.data.zones, '');
            zoneOptions($w.find('.gt-to-zone'), [], '', '— Chọn chi nhánh nhập trước —');
            // Lý do mặc định của chuyển kho (XCKho)
            reasonOptions(r.data.reasons, (r.data.reasons.find(function (x) { return x.isDefault; }) || r.data.reasons.find(function (x) { return x.code === 'XCKho'; }) || {}).code);
            applyMode();
            prefillFromPo($to);
            return;
        }
        ajax('get', { transfer_id: id }).done(function (g) {
            if (!g.success) return msg('danger', g.data && g.data.message);
            loaded = g.data;
            var t = loaded, confirmed = +t.import_status === 1 || (+t.to_blog_id <= 0 && +t.export_status === 1);
            $w.find('.gt-code').val(t.transfer_code); $w.find('.gt-date').val(t.doc_date); $w.find('.gt-note').val(t.note || ''); $w.find('.gt-src').val(t.source_label || '');
            $w.find('.gt-status').text(+t.import_status === 2 ? 'Từ chối nhận' : (confirmed ? 'Đã xác nhận' : (+t.export_status === 1 ? 'Đang chờ xác nhận' : 'Nháp (chưa xuất)'))).toggleClass('ok', confirmed);
            fillSites($w.find('.gt-from-site'), t.from_blog_id);
            fillSites($to, t.to_blog_id, +t.from_blog_id);
            reasonOptions(r.data.reasons, t.reason_code || '');
            lines = t.items.map(function (it) { return { item_id: +it.item_id, product_id: it.product_id, sku: it.sku, product_name: it.product_name, unit_name: it.unit_name, quantity: +it.quantity, received: it.received_qty == null ? '' : +it.received_qty, price: +it.price, tax_percent: +it.tax_percent, note: it.note }; });
            // Đang đứng ở chi nhánh nhập → nhận hàng; ở chi nhánh xuất (hoặc quản trị) → sửa; đã xác nhận / từ chối → chỉ xem.
            // Chờ nhận + đang đứng ở chi nhánh nhập → nhận hàng; bên xuất / kế toán → sửa được (cả phiếu đã xác nhận: tồn hai bên tự
            // tính lại theo số mới); bị từ chối hoặc không có quyền → chỉ xem.
            // Mở phiếu = XEM; bấm Sửa mới sửa (bên xuất / kế toán; phiếu bị từ chối thì không). Chờ nhận + đang đứng ở chi nhánh
            // nhập → xem kèm ô Số lượng nhập + ô tích xác nhận.
            canEdit = +t.import_status !== 2 && (+t.from_blog_id === cur || canAll);
            mode = !confirmed && +t.import_status !== 2 && +t.to_blog_id === cur && +t.export_status === 1 ? 'receive' : 'view';
            $.when(loadZones(+t.from_blog_id, $w.find('.gt-from-zone'), t.from_zone), +t.to_blog_id > 0 ? loadZones(+t.to_blog_id, $w.find('.gt-to-zone'), t.to_zone) : zoneOptions($w.find('.gt-to-zone'), [], t.to_zone)).always(applyMode);
            if (/[?&]saved=1/.test(location.search)) msg('success', 'Đã ghi phiếu.');
        });
    });
})(jQuery);
</script>
