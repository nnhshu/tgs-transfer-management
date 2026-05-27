<?php
/**
 * Partial: Modal + JS xác nhận lệch SL nhập vs SL chứng từ
 *
 * Dùng cho các trang tạo phiếu mua nội bộ / nhận hoàn nội bộ
 * (transfer-import-add.php, transfer-return-receive-add.php).
 *
 * Cách dùng:
 *   1. include partial này 1 lần trong template.
 *   2. Sau khi render bảng sản phẩm, gọi
 *      TgsTransferMismatch.markRows('#productsTableBody');
 *   3. Trong submit handler, thay vì gọi AJAX ngay → wrap:
 *      TgsTransferMismatch.confirmIfMismatch('#productsTableBody', function () {
 *          // gọi AJAX tạo phiếu ở đây
 *      });
 *
 * Mỗi <tr> trong tbody phải có:
 *   - data-sku
 *   - data-import-qty   (SL nhập thực tế)
 *   - data-doc-qty      (SL chứng từ — 0 hoặc không có nghĩa không có CT)
 *   - data-product-name (Tên SP để hiển thị trong modal)
 *
 * @package tgs-transfer-management
 */
if (!defined('ABSPATH')) { exit; }
?>
<style>
    tr.tgs-row-mismatch { background-color: #fff3cd !important; }
    tr.tgs-row-mismatch td { border-color: #ffeeba !important; }
    .tgs-mismatch-badge { font-size: 11px; padding: 2px 6px; }
</style>

<!-- Modal xác nhận lệch SL chứng từ -->
<div class="modal fade" id="tgsTransferMismatchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="bx bx-error-circle me-1"></i>
                    Cảnh báo lệch số lượng so với chứng từ
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Có <strong id="tgsMismatchCount">0</strong> sản phẩm có
                    <strong>SL nhập</strong> khác với <strong>SL chứng từ</strong> (SL CT)
                    được kế thừa từ phiếu nguồn. Bạn có chắc chắn muốn tạo phiếu?
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>SKU</th>
                                <th>Sản phẩm</th>
                                <th class="text-end">SL nhập</th>
                                <th class="text-end">SL CT</th>
                                <th class="text-end">Lệch</th>
                            </tr>
                        </thead>
                        <tbody id="tgsMismatchModalBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bx bx-x me-1"></i> Hủy, kiểm tra lại
                </button>
                <button type="button" class="btn btn-warning" id="tgsMismatchConfirmBtn">
                    <i class="bx bx-check me-1"></i> Tôi đã xem, vẫn tạo phiếu
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function ($) {
    'use strict';
    if (window.TgsTransferMismatch) return; // tránh double-include

    function escapeHtml(s) {
        if (s === null || typeof s === 'undefined') return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function collectMismatches(tbodySelector) {
        var out = [];
        $(tbodySelector).find('tr[data-sku]').each(function () {
            var $tr     = $(this);
            var imp     = parseFloat($tr.attr('data-import-qty')) || 0;
            var doc     = parseFloat($tr.attr('data-doc-qty')) || 0;
            if (doc <= 0) return; // không có CT để so sánh
            var diff    = imp - doc;
            if (Math.abs(diff) < 0.0001) return;
            out.push({
                sku:  $tr.attr('data-sku') || '',
                name: $tr.attr('data-product-name') || '',
                imp:  imp,
                doc:  doc,
                diff: diff
            });
        });
        return out;
    }

    function markRows(tbodySelector) {
        $(tbodySelector).find('tr[data-sku]').each(function () {
            var $tr   = $(this);
            var imp   = parseFloat($tr.attr('data-import-qty')) || 0;
            var doc   = parseFloat($tr.attr('data-doc-qty')) || 0;
            $tr.removeClass('tgs-row-mismatch');
            $tr.find('.tgs-mismatch-badge').remove();
            if (doc <= 0) return;
            var diff = imp - doc;
            if (Math.abs(diff) < 0.0001) return;
            $tr.addClass('tgs-row-mismatch');
            var sign = diff > 0 ? '+' : '';
            var badge = '<br><span class="badge bg-warning text-dark tgs-mismatch-badge" '
                      + 'title="SL nhập (' + imp + ') − SL CT (' + doc + ') = ' + sign + diff + '">'
                      + 'Lệch ' + sign + diff + '</span>';
            // Gắn badge vào cell SL CT (cột thứ 7 trong bảng productsTable)
            var $docCell = $tr.find('td').eq(6);
            if ($docCell.length) $docCell.append(badge);
        });
    }

    function confirmIfMismatch(tbodySelector, onConfirm) {
        var list = collectMismatches(tbodySelector);
        if (list.length === 0) {
            onConfirm();
            return;
        }
        var html = '';
        list.forEach(function (m) {
            var sign = m.diff > 0 ? '+' : '';
            var diffClass = m.diff > 0 ? 'text-warning' : 'text-danger';
            html += '<tr>'
                  + '<td><code>' + escapeHtml(m.sku) + '</code></td>'
                  + '<td>' + escapeHtml(m.name) + '</td>'
                  + '<td class="text-end">' + m.imp + '</td>'
                  + '<td class="text-end">' + m.doc + '</td>'
                  + '<td class="text-end fw-bold ' + diffClass + '">' + sign + m.diff + '</td>'
                  + '</tr>';
        });
        $('#tgsMismatchModalBody').html(html);
        $('#tgsMismatchCount').text(list.length);

        var modalEl = document.getElementById('tgsTransferMismatchModal');
        var modal   = bootstrap.Modal.getOrCreateInstance(modalEl);

        // Reset handler để tránh stack nhiều lần
        $('#tgsMismatchConfirmBtn').off('click.tgsmm').on('click.tgsmm', function () {
            modal.hide();
            onConfirm();
        });

        modal.show();
    }

    window.TgsTransferMismatch = {
        collect:           collectMismatches,
        markRows:          markRows,
        confirmIfMismatch: confirmIfMismatch
    };
})(jQuery);
</script>
