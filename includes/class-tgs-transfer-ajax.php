<?php

/**
 * TGS Transfer AJAX Handler
 *
 * Xử lý xuất/nhập hàng giữa các shop trong multisite
 *
 * @package tgs_transfer_management
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Transfer_Ajax
{
    /**
     * Constructor - đăng ký các AJAX actions
     */
    public static function init()
    {
        // Xuất hàng
        add_action('wp_ajax_tgs_transfer_get_products', [__CLASS__, 'get_products']);
        add_action('wp_ajax_tgs_transfer_check_products_sync', [__CLASS__, 'check_products_sync']);
        // add_action('wp_ajax_tgs_transfer_create_export', [__CLASS__, 'create_export']); /không xài nữa , vì có phần giao diện chung bên plugin shop rồi
        add_action('wp_ajax_tgs_transfer_approve_export', [__CLASS__, 'approve_export']);
        add_action('wp_ajax_tgs_transfer_reject_export', [__CLASS__, 'reject_export']);

        // Nhập hàng
        add_action('wp_ajax_tgs_transfer_get_pending_imports', [__CLASS__, 'get_pending_imports']);
        add_action('wp_ajax_tgs_transfer_create_import', [__CLASS__, 'create_import']);
        add_action('wp_ajax_tgs_transfer_approve_import', [__CLASS__, 'approve_import']);
        add_action('wp_ajax_tgs_transfer_reject_import', [__CLASS__, 'reject_import']);

        // Trả hàng nội bộ
        add_action('wp_ajax_tgs_transfer_get_pending_returns', [__CLASS__, 'get_pending_returns']);
        add_action('wp_ajax_tgs_transfer_create_return', [__CLASS__, 'create_return']);
        add_action('wp_ajax_tgs_transfer_create_return_receive', [__CLASS__, 'create_return_receive']);
        add_action('wp_ajax_tgs_transfer_approve_return', [__CLASS__, 'approve_return']);

        // Danh sách phiếu
        add_action('wp_ajax_tgs_transfer_get_exports_list', [__CLASS__, 'get_exports_list']);
        add_action('wp_ajax_tgs_transfer_get_imports_list', [__CLASS__, 'get_imports_list']);
        add_action('wp_ajax_tgs_transfer_get_detail', [__CLASS__, 'get_detail']);

        // Transfer detail
        add_action('wp_ajax_tgs_transfer_get_transfer_detail', [__CLASS__, 'get_transfer_detail']);
        add_action('wp_ajax_tgs_transfer_get_items', [__CLASS__, 'get_transfer_items']);
        add_action('wp_ajax_tgs_transfer_update_lot_conditions', [__CLASS__, 'update_lot_conditions']);

        // Report
        add_action('wp_ajax_tgs_transfer_get_report_data', [__CLASS__, 'get_report_data']);
    }

    /**
     * Lấy danh sách sản phẩm có tồn kho để xuất
     */
    public static function get_products()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        $current_blog_id = get_current_blog_id();

        $products = TGS_Transfer_Global_Products::query_products_for_transfer($current_blog_id);

        wp_send_json_success(['products' => $products]);
    }

    /**
     * Kiểm tra sản phẩm đã được đồng bộ đến shop đích chưa
     */
    public static function check_products_sync()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        $destination_blog_id = intval($_POST['destination_blog_id'] ?? 0);
        $product_ids = $_POST['product_ids'] ?? [];

        if (!$destination_blog_id || empty($product_ids)) {
            wp_send_json_error(['message' => 'Thiếu thông tin']);
        }

        $current_blog_id = get_current_blog_id();
        $product_ids = array_values(array_unique(array_filter(array_map('intval', (array) $product_ids))));

        $products = TGS_Transfer_Global_Products::get_products_by_ids($product_ids, $current_blog_id);
        $synced = array_map(static function ($product) {
            return (int) ($product['global_product_name_id'] ?? 0);
        }, $products);
        $synced = array_values(array_filter(array_unique($synced)));

        // Catalog dùng chung bảng global nên không còn bước đồng bộ sản phẩm local sang shop đích.

        wp_send_json_success([
            'synced' => $synced,
            'need_sync' => [],
            'missing_global' => array_values(array_diff($product_ids, $synced)),
            'global_catalog' => true,
            'destination_blog_id' => $destination_blog_id
        ]);
    }

    /**
     * Duyệt phiếu xuất - thực hiện:
     * 1. Cập nhật trạng thái lot thành PENDING (chờ nhận)
     * 2. Không ghi tồn catalog local; tồn non-tracking tính từ ledger/API theo SKU
     * 3. Validate sản phẩm global dùng chung
     * 4. Tạo thông báo cho shop đích
     */
    public static function approve_export()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $ledger_type = intval($_POST['ledger_type'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

        // Lấy thông tin phiếu con xuất kho (type 2 - SALE)
        $child_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_SALE));

        if (!$child_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu xuất kho']);
        }

        if ($child_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED) {
            wp_send_json_error(['message' => 'Phiếu đã được duyệt trước đó']);
        }

        // Tìm phiếu cha TRANSFER_EXPORT (type 12) hoặc INTERNAL_RETURN (type 14)
        $parent_id = intval($child_ledger->local_ledger_parent_id);
        if (!$parent_id) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu cha']);
        }

        // Check cả 2 loại phiếu cha: xuất nội bộ (12) và trả nội bộ (14)
        $parent_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type IN (%d, %d)
        ", $parent_id, TGS_LEDGER_TYPE_TRANSFER_EXPORT, TGS_LEDGER_TYPE_INTERNAL_RETURN));

        if (!$parent_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu xuất/trả nội bộ']);
        }

        // Lấy thông tin transfer từ phiếu cha
        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$transfer_table}
            WHERE source_ledger_id = %d
            AND source_blog_id = %d
        ", $parent_id, $current_blog_id));

        if (!$transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy thông tin transfer']);
        }

        $destination_blog_id = $transfer->destination_blog_id;

        // Lấy các item từ phiếu con xuất kho
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*
            FROM {$ledger_item_table} li
            WHERE li.local_ledger_id = %d
        ", $ledger_id));
        $items = TGS_Transfer_Global_Products::enrich_ledger_items($items, $current_blog_id);

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    // Cập nhật trạng thái lot thành PENDING (đang chờ nhận ở shop đích)
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];

                    foreach ($lot_ids as $lot_id) {
                        // Lấy lot hiện tại để biết previous_status
                        $current_lot = TGS_Global_Lots_Helper::get_lot_by_id($lot_id);
                        $previous_status = $current_lot ? $current_lot->local_product_lot_is_active : TGS_PRODUCT_LOT_PENDING;

                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main_and_sku($lot_id, $item->local_product_name_id);

                        $wpdb->update($lots_table, [
                            'source_blog_id' => $current_blog_id, // Shop mẹ xuất đi
                            'to_blog_id' => $destination_blog_id, // Shop con đích
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING,
                            'local_exported_date' => time(),
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        // ========== GHI LOG VÀO product_lot_meta ==========
                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'transfer_export_approved', [
                            'previous_status' => $previous_status,
                            'new_status' => TGS_PRODUCT_LOT_PENDING,
                            'source_blog_id' => $current_blog_id,
                            'destination_blog_id' => $destination_blog_id,
                            'ledger_id' => $ledger_id,
                            'ledger_code' => $child_ledger->local_ledger_code ?? ''
                        ]);
                    }
                } else {
                    // ========== KHÔNG TRỪ TỒN KHO KHI DUYỆT PHIẾU XUẤT TRANSFER ==========
                    // Theo yêu cầu fileyeucauthuy5: Đã trừ ngay khi tạo phiếu rồi
                    // Khi duyệt chỉ cần giữ nguyên, không làm gì thêm cho sản phẩm non-tracking
                }

                // Catalog sản phẩm là global, chỉ validate nhanh theo SKU/global id.
                if (!self::sync_product_to_destination($item, $destination_blog_id, $current_blog_id)) {
                    $sku = $item->local_product_sku ?? '';
                    throw new Exception("Không tìm thấy sản phẩm global cho SKU '{$sku}'");
                }
            }

            // ========== CẬP NHẬT BATCH_DISTRIBUTION - Transfer Export ==========
            // Đánh dấu transferred_out tại nơi xuất
            if (class_exists('TGS_Batch_Distribution')) {
                $items_with_batch = self::collect_items_with_batch($items);
                if (!empty($items_with_batch)) {
                    TGS_Batch_Distribution::on_transfer_export_approved($current_blog_id, $items_with_batch);

                    // Không ghi batch_movement ở đây — sẽ ghi 1 lần duy nhất
                    // khi approve_import (hàng thực sự đến đích) để tránh duplicate.
                }
            }

            // Cập nhật trạng thái phiếu con xuất kho
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_APPROVED,
                'local_ledger_status' => TGS_LEDGER_STATUS_APPROVED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật transfer_ledger - sẵn sàng cho shop đích nhận
            $wpdb->update($transfer_table, [
                'transfer_status' => TGS_TRANSFER_STATUS_PENDING, // Vẫn pending, chờ shop đích nhận
                'transfer_note' => $transfer->transfer_note . "\n[Duyệt xuất kho] " . date('d/m/Y H:i') . ": " . $note
            ], ['transfer_ledger_id' => $transfer->transfer_ledger_id]);

            $wpdb->query('COMMIT');

            // Thêm log duyệt phiếu xuất kho
            $dest_shop_name = get_blog_option($destination_blog_id, 'blogname');

            // Xác định message dựa trên loại phiếu cha
            $parent_type = intval($parent_ledger->local_ledger_type);
            $is_return = ($parent_type === TGS_LEDGER_TYPE_INTERNAL_RETURN);
            $log_message = !empty($note) ? $note : 'Duyệt phiếu xuất kho (chuyển đến shop: ' . $dest_shop_name . ')';
            $success_message = $is_return
                ? 'Duyệt phiếu trả nội bộ thành công. Shop mẹ có thể nhận hàng trả.'
                : 'Duyệt phiếu xuất nội bộ thành công. Nơi nhận có thể nhận hàng.';

            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'approve', [
                'destination_blog_id' => $destination_blog_id,
                'destination_shop_name' => $dest_shop_name,
                'items_count' => count($items),
                'note' => $note,
                'parent_ledger_id' => $parent_id,
                'parent_ledger_code' => $parent_ledger->local_ledger_code ?? '',
                'is_return' => $is_return
            ], $log_message);

            /*
             * ─── TỰ SINH PHIẾU MUA NỘI BỘ BÊN SHOP NHẬN ─────────────────────
             *
             * Đặt SAU COMMIT, cố ý. Việc duyệt xuất ở nơi xuất đã xong và đã ghi
             * xuống DB; phần dưới đây thao tác trên database của SITE KHÁC nên
             * không nằm chung transaction được — MySQL transaction chỉ bao được
             * kết nối hiện tại, không bao chéo blog.
             *
             * Chỉ áp cho phiếu xuất nội bộ. Phiếu TRẢ nội bộ có luồng nhận riêng
             * (create_return_receive) với cấu hình khác, không gộp vào đây.
             */
            $auto_import = null;
            if (!$is_return) {
                $auto_import = self::auto_create_destination_import(
                    $transfer,
                    $destination_blog_id,
                    $current_blog_id
                );

                if (is_array($auto_import) && empty($auto_import['ok'])) {
                    /*
                     * Không chặn việc duyệt: hàng đã trừ kho bên bán rồi. Ghi log
                     * để truy được, nơi nhận vẫn vào màn "Chờ nhận nội bộ" bấm
                     * tạo tay như luồng cũ.
                     */
                    TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'auto_import_failed', [
                        'destination_blog_id' => $destination_blog_id,
                        'error' => $auto_import['message'] ?? '',
                    ], 'Không tự tạo được phiếu nhận nội bộ bên nơi nhận: ' . ($auto_import['message'] ?? ''));

                    $success_message .= ' (Chưa tự tạo được phiếu bên nơi nhận, nơi nhận vào màn "Chờ nhận nội bộ" tạo giúp.)';
                } elseif (is_array($auto_import) && empty($auto_import['skipped'])) {
                    $success_message .= ' Đã tự tạo phiếu nhận nội bộ '
                        . ($auto_import['ledger_code'] ?? '') . ' chờ nơi nhận duyệt.';

                    TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'auto_import_created', [
                        'destination_blog_id'   => $destination_blog_id,
                        'dest_ledger_id'        => $auto_import['ledger_id'] ?? 0,
                        'dest_ledger_code'      => $auto_import['ledger_code'] ?? '',
                        'dest_auto_import_code' => $auto_import['auto_import_code'] ?? '',
                    ], 'Tự tạo phiếu nhận nội bộ bên nơi nhận: ' . ($auto_import['ledger_code'] ?? ''));
                }
            }

            wp_send_json_success([
                'message' => $success_message,
                'auto_import' => $auto_import,
            ]);
        } catch (Exception $e) {

            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPER: Thu thập items_with_batch (hỗ trợ cả tracking + non-tracking)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Thu thập items_with_batch từ danh sách ledger items.
     * - Non-tracking: lấy batch_id trực tiếp từ item->batch_id
     * - Tracking: lấy batch_id từ wp_global_product_lots (mỗi lot có batch_id)
     *   → group by batch_id, đếm số lots = quantity
     *
     * @param array $items Danh sách ledger items (objects từ DB)
     * @return array [['batch_id' => X, 'quantity' => Y], ...]
     */
    private static function collect_items_with_batch($items)
    {
        global $wpdb;
        $result = [];
        $tracking_lot_ids = [];

        foreach ($items as $item) {
            $is_tracking = intval($item->local_product_is_tracking ?? 0) === 1;
            $bid = intval($item->batch_id ?? 0);

            if (!$is_tracking && $bid > 0) {
                // Non-tracking: dùng batch_id từ ledger item
                $result[] = [
                    'batch_id' => $bid,
                    'quantity' => floatval($item->quantity ?? 0),
                ];
            } elseif ($is_tracking) {
                // Tracking: thu thập lot_ids để query batch_id sau
                $lot_ids = json_decode($item->list_product_lots ?? '[]', true) ?: [];
                $tracking_lot_ids = array_merge($tracking_lot_ids, array_map('intval', $lot_ids));
            }
        }

        // Query batch_id từ lots cho tracking products
        $tracking_lot_ids = array_unique(array_filter($tracking_lot_ids));
        if (!empty($tracking_lot_ids)) {
            $lots_table = defined('TGS_TABLE_GLOBAL_PRODUCT_LOTS')
                ? TGS_TABLE_GLOBAL_PRODUCT_LOTS
                : $wpdb->base_prefix . 'global_product_lots';
            $placeholders = implode(',', array_fill(0, count($tracking_lot_ids), '%d'));
            $lot_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT global_product_lot_id, batch_id FROM {$lots_table} WHERE global_product_lot_id IN ({$placeholders})",
                ...$tracking_lot_ids
            ));

            // Group by batch_id → count lots (mỗi lot = 1 unit cho tracking)
            $batch_count = [];
            foreach ($lot_rows as $lot) {
                $lot_bid = intval($lot->batch_id ?? 0);
                if ($lot_bid > 0) {
                    if (!isset($batch_count[$lot_bid])) $batch_count[$lot_bid] = 0;
                    $batch_count[$lot_bid]++;
                }
            }

            foreach ($batch_count as $batch_id => $qty) {
                $result[] = [
                    'batch_id' => $batch_id,
                    'quantity' => $qty,
                ];
            }
        }

        return $result;
    }

    private static function parse_nullable_float($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        return is_numeric($value) ? floatval($value) : null;
    }

    /**
     * Copy snapshot DVT/kg từ item nguồn sang item đích.
     * Nếu nhận một phần, scale SL DVT và tổng kg theo quantity thực nhận.
     */
    private static function build_copied_unit_snapshot($source_item, $target_quantity, $source_quantity)
    {
        $target_quantity = floatval($target_quantity);
        $source_quantity = floatval($source_quantity);

        $unit_ratio = self::parse_nullable_float($source_item->local_ledger_item_unit_ratio ?? null);
        if ($unit_ratio === null || $unit_ratio <= 0) {
            $unit_ratio = 1.0;
        }

        $unit_quantity = self::parse_nullable_float($source_item->local_ledger_item_unit_quantity ?? null);
        if ($unit_quantity !== null && $source_quantity > 0 && abs($target_quantity - $source_quantity) > 0.0001) {
            $unit_quantity = $unit_quantity * ($target_quantity / $source_quantity);
        } elseif ($unit_quantity === null && $target_quantity > 0 && $unit_ratio > 0) {
            $unit_quantity = $target_quantity / $unit_ratio;
        }

        $total_weight_kg = self::parse_nullable_float($source_item->local_ledger_item_total_weight_kg ?? null);
        if ($total_weight_kg !== null && $source_quantity > 0 && abs($target_quantity - $source_quantity) > 0.0001) {
            $total_weight_kg = $total_weight_kg * ($target_quantity / $source_quantity);
        }

        return [
            'unit_quantity' => $unit_quantity,
            'unit_ratio' => $unit_ratio,
            'unit_name' => $source_item->local_ledger_item_unit_name ?? ($source_item->local_product_unit ?? ''),
            'total_weight_kg' => $total_weight_kg,
        ];
    }

    /**
     * Ghi batch_movement records cho transfer.
     *
     * @param array $items_with_batch [['batch_id' => X, 'quantity' => Y], ...]
     * @param int   $from_blog_id     Nơi xuất
     * @param int   $to_blog_id       Shop đích
     * @param int   $movement_type    1=điều chuyển, 2=trả lại, 3=hủy
     * @param int   $source_ledger_id Ledger ID tham chiếu
     * @param int   $source_ledger_blog_id Blog ID của ledger
     */
    private static function record_batch_movements($items_with_batch, $from_blog_id, $to_blog_id, $movement_type, $source_ledger_id, $source_ledger_blog_id)
    {
        global $wpdb;
        $table = defined('TGS_TABLE_GLOBAL_BATCH_MOVEMENT') ? TGS_TABLE_GLOBAL_BATCH_MOVEMENT : 'wp_global_batch_movement';
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        // Gom theo batch_id trước
        $batch_qty = [];
        foreach ($items_with_batch as $item) {
            $bid = intval($item['batch_id'] ?? 0);
            if ($bid <= 0) continue;
            if (!isset($batch_qty[$bid])) $batch_qty[$bid] = 0;
            $batch_qty[$bid] += floatval($item['quantity'] ?? 0);
        }

        foreach ($batch_qty as $batch_id => $quantity) {
            // Chống trùng: nếu đã có movement cùng batch + from + to + ledger + type
            // trong vòng 5 phút gần đây → bỏ qua
            $dup = $wpdb->get_var($wpdb->prepare(
                "SELECT movement_id FROM {$table}
                 WHERE batch_id = %d AND from_blog_id = %d AND to_blog_id = %d
                   AND movement_type = %d AND source_ledger_id = %d
                   AND is_deleted = 0
                   AND created_at >= DATE_SUB(%s, INTERVAL 5 MINUTE)
                 LIMIT 1",
                $batch_id, $from_blog_id, $to_blog_id,
                $movement_type, $source_ledger_id ?: 0, $now
            ));
            if ($dup) continue;

            $wpdb->insert($table, [
                'batch_id' => $batch_id,
                'from_blog_id' => $from_blog_id ?: null,
                'to_blog_id' => $to_blog_id ?: null,
                'quantity' => $quantity,
                'movement_type' => $movement_type,
                'source_ledger_id' => $source_ledger_id ?: null,
                'source_ledger_blog_id' => $source_ledger_blog_id ?: null,
                'user_id' => $user_id,
                'is_deleted' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Resolve batch_id cho 1 item.
     * - Non-tracking: lấy từ item->batch_id
     * - Tracking: lấy batch_id từ lot đầu tiên trong list_product_lots
     *   (tất cả lots cùng item thường cùng batch)
     *
     * @param object $source_item Ledger item object
     * @param bool   $is_tracking Sản phẩm có tracking lot/HSD
     * @return int|null batch_id hoặc null
     */
    private static function resolve_batch_id_for_item($source_item, $is_tracking)
    {
        // Non-tracking: lấy trực tiếp
        $bid = intval($source_item->batch_id ?? 0);
        if ($bid > 0) return $bid;

        // Tracking: lấy từ lot đầu tiên
        if ($is_tracking) {
            $lot_ids = json_decode($source_item->list_product_lots ?? '[]', true) ?: [];
            if (!empty($lot_ids)) {
                global $wpdb;
                $first_lot_id = intval($lot_ids[0]);
                $lots_table = defined('TGS_TABLE_GLOBAL_PRODUCT_LOTS')
                    ? TGS_TABLE_GLOBAL_PRODUCT_LOTS
                    : $wpdb->base_prefix . 'global_product_lots';
                $lot_batch = $wpdb->get_var($wpdb->prepare(
                    "SELECT batch_id FROM {$lots_table} WHERE global_product_lot_id = %d",
                    $first_lot_id
                ));
                if ($lot_batch) return intval($lot_batch);
            }
        }

        return null;
    }

    /**
     * Validate sản phẩm global cho nơi xuất/đích.
     * Giữ tên hàm cũ để không làm gãy các đoạn gọi nội bộ.
     *
     * @param object $item Thông tin item (chứa local_product_name_id, local_product_sku)
     * @param int $destination_blog_id Blog ID của shop đích
     * @param int $source_blog_id Blog ID của nơi xuất
     */
    private static function sync_product_to_destination($item, $destination_blog_id, $source_blog_id)
    {
        // Không còn copy catalog giữa các site. Tất cả sản phẩm dùng chung global catalog/API.
        return TGS_Transfer_Global_Products::product_exists_for_item($item, $destination_blog_id)
            || TGS_Transfer_Global_Products::product_exists_for_item($item, $source_blog_id);
    }

    /**
     * Lấy danh sách phiếu đang chờ nhận từ shop mẹ
     *
     * Logic mới: Shop con có bảng transfer_ledger của riêng mình
     * - Query transfer_ledger của shop con hiện tại
     * - Switch sang shop mẹ (source_blog_id) để lấy local_ledger_approver_status
     */
    /**
     * Phương thức internal dùng chung cho get_pending_imports và get_pending_returns
     *
     * @param array $config Cấu hình:
     *   - transfer_type: null (all) hoặc TGS_TRANSFER_TYPE_INTERNAL (1) hoặc TGS_TRANSFER_TYPE_RETURN (2)
     */
    private static function do_get_pending_transfers_internal($config)
    {
        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $pending_transfers = [];

        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        // Build query với điều kiện transfer_type tùy chọn
        $query = "
            SELECT t.transfer_ledger_id as transfer_id,
                   t.source_blog_id,
                   t.source_ledger_id,
                   t.destination_blog_id,
                   t.destination_ledger_id,
                   t.transfer_status,
                   t.transfer_note as note,
                   t.created_at,
                   t.transfer_type
            FROM {$transfer_table} t
            WHERE t.destination_blog_id = %d
            AND (t.destination_ledger_id IS NULL OR t.destination_ledger_id = 0)
            AND t.transfer_status != %d
        ";

        $params = [$current_blog_id, TGS_TRANSFER_STATUS_ACCEPTED];

        // Thêm điều kiện transfer_type nếu có
        if (isset($config['transfer_type']) && $config['transfer_type'] !== null) {
            $query .= " AND t.transfer_type = %d";
            $params[] = $config['transfer_type'];
        }

        $transfers = $wpdb->get_results($wpdb->prepare($query, ...$params));

        foreach ($transfers as $transfer) {
            $source_blog_id = intval($transfer->source_blog_id);

            if (!$source_blog_id) continue;

            // Switch sang nơi xuất để lấy thông tin phiếu
            switch_to_blog($source_blog_id);

            $source_ledger_table = $wpdb->prefix . 'local_ledger';
            $source_ledger_item_table = $wpdb->prefix . 'local_ledger_item';

            // Lấy thông tin phiếu nguồn
            $source_ledger = $wpdb->get_row($wpdb->prepare("
                SELECT local_ledger_code,
                       local_ledger_total_amount,
                       local_ledger_note,
                       local_ledger_approver_status,
                       local_ledger_item_id
                FROM {$source_ledger_table}
                WHERE local_ledger_id = %d
            ", $transfer->source_ledger_id));

            if ($source_ledger) {
                $transfer->local_ledger_code = $source_ledger->local_ledger_code;
                $transfer->local_ledger_total_amount = $source_ledger->local_ledger_total_amount;
                $transfer->local_ledger_note = $source_ledger->local_ledger_note;
                $transfer->local_ledger_approver_status = $source_ledger->local_ledger_approver_status;

                // Tên nơi xuất
                $transfer->source_shop_name = get_bloginfo('name');

                // Đếm số sản phẩm từ local_ledger_item_id
                $item_ids = [];
                if (!empty($source_ledger->local_ledger_item_id)) {
                    $item_ids = json_decode($source_ledger->local_ledger_item_id, true) ?: [];
                }

                $items_count = 0;
                if (!empty($item_ids)) {
                    $item_ids_str = implode(',', array_map('intval', $item_ids));
                    $items_count = $wpdb->get_var("
                        SELECT COUNT(*) FROM {$source_ledger_item_table}
                        WHERE local_ledger_item_id IN ({$item_ids_str})
                        AND (is_deleted = 0 OR is_deleted IS NULL)
                    ");
                }
                $transfer->items_count = intval($items_count);

                // Kiểm tra trạng thái duyệt của phiếu xuất tự động (phiếu con)
                $auto_export_ledger = $wpdb->get_row($wpdb->prepare("
                    SELECT local_ledger_id, local_ledger_approver_status
                    FROM {$source_ledger_table}
                    WHERE local_ledger_parent_id = %d
                    AND local_ledger_type = %d
                ", $transfer->source_ledger_id, TGS_LEDGER_TYPE_SALE));

                // Set trạng thái hiển thị
                if ($auto_export_ledger) {
                    $transfer->transfer_status = ($auto_export_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED)
                        ? TGS_TRANSFER_STATUS_ACCEPTED : TGS_TRANSFER_STATUS_PENDING;
                } else {
                    // Fallback: nếu không tìm thấy phiếu con thì check phiếu cha
                    $transfer->transfer_status = ($source_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED)
                        ? TGS_TRANSFER_STATUS_ACCEPTED : TGS_TRANSFER_STATUS_PENDING;
                }

                $pending_transfers[] = $transfer;
            }

            restore_current_blog();
        }

        wp_send_json_success($pending_transfers);
    }

    /**
     * Lấy danh sách phiếu chờ nhập (nhận nội bộ)
     * Gọi đến do_get_pending_transfers_internal
     */
    public static function get_pending_imports()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        // Gọi hàm dùng chung - không filter theo transfer_type (lấy tất cả loại INTERNAL)
        self::do_get_pending_transfers_internal([
            'transfer_type' => TGS_TRANSFER_TYPE_INTERNAL // 1 = sale/internal
        ]);
    }

    /**
     * Phương thức internal dùng chung cho cả create_import và create_return_receive
     *
     * @param array $config Cấu hình để phân biệt giữa các loại phiếu:
     *   - transfer_type: TGS_TRANSFER_TYPE_INTERNAL (1) hoặc TGS_TRANSFER_TYPE_RETURN (2)
     *   - parent_ledger_type: Type của phiếu cha (13=TRANSFER_IMPORT, 15=INTERNAL_RETURN_RECEIVE)
     *   - source_parent_type: Type phiếu nguồn để check (12=TRANSFER_EXPORT, 14=INTERNAL_RETURN)
     *   - parent_code_prefix: Tiền tố mã phiếu cha (MNB, NTN)
     *   - child_code_prefix: Tiền tố mã phiếu con (AMN, ANT)
     *   - log_action: Tên action cho lot log
     *   - redirect_view: View để redirect sau khi tạo
     *   - success_message: Thông báo thành công
     *   - ticket_log_type: Loại ticket log
     *   - labels: Array các label error/success message
     */
    /*
     * Chế độ chạy ngầm.
     *
     * Hàm do_create_import_internal() vốn chỉ dùng cho AJAX: đọc $_POST rồi kết
     * thúc request bằng wp_send_json_*(). Nay nó còn được gọi thẳng từ
     * approve_export() để tự sinh phiếu nhận nội bộ bên nơi nhận — mà
     * wp_send_json_*() gọi wp_die(), tức là sẽ giết luôn cả request duyệt xuất
     * đang chạy dở của nơi xuất.
     *
     * Cờ này quyết định cách "trả lời": bật thì trả về mảng cho code gọi tự xử,
     * tắt thì giữ nguyên hành vi cũ.
     */
    private static $import_silent = false;

    /** Trả lỗi: mảng khi chạy ngầm, JSON + kết thúc request khi qua AJAX */
    private static function import_reply_error($message)
    {
        if (self::$import_silent) {
            return ['ok' => false, 'message' => $message];
        }
        wp_send_json_error(['message' => $message]);
    }

    /** Trả thành công: tương tự import_reply_error() */
    private static function import_reply_success($data)
    {
        if (self::$import_silent) {
            return array_merge(['ok' => true], $data);
        }
        wp_send_json_success($data);
    }

    /**
     * @param array      $config Cấu hình loại phiếu (import nội bộ / nhận trả)
     * @param array|null $args   Truyền mảng để chạy ngầm, bỏ trống để đọc $_POST.
     *                           Khoá: transfer_id, note, items, created_by_user_id
     */
    private static function do_create_import_internal($config, $args = null)
    {
        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $silent = is_array($args);
        self::$import_silent = $silent;

        /*
         * Chạy ngầm thì người tạo là "hệ thống" (user_id = 0), KHÔNG mượn danh
         * người vừa bấm duyệt bên nơi xuất: họ thường không có tài khoản ở shop
         * nhận, ghi tên họ vào log bên đó là sai chủ thể.
         */
        $current_user_id = $silent
            ? intval($args['created_by_user_id'] ?? 0)
            : get_current_user_id();

        $transfer_id = $silent
            ? intval($args['transfer_id'] ?? 0)
            : intval($_POST['transfer_id'] ?? 0);

        $import_note = $silent
            ? sanitize_textarea_field((string) ($args['note'] ?? ''))
            : sanitize_textarea_field($_POST['note'] ?? $_POST['import_note'] ?? '');

        // Bỏ trống items = nhận TOÀN BỘ theo số lượng gốc của phiếu xuất
        $items_json = $silent
            ? (string) ($args['items'] ?? '')
            : (isset($_POST['items']) ? wp_unslash($_POST['items']) : '');

        // advance_meta: danh sách file chứng từ được copy từ nơi xuất
        $advance_meta_raw = (!$silent && !empty($_POST['advance_meta']))
            ? wp_unslash($_POST['advance_meta'])
            : '';

        if (!$transfer_id) {
            return self::import_reply_error('Thiếu ID transfer');
        }

        // Parse items từ frontend (nếu có)
        $custom_items = [];
        if (!empty($items_json)) {
            $custom_items = json_decode($items_json, true);
            if (!is_array($custom_items)) {
                $custom_items = [];
            }
        }

        // Build lookup maps. Ưu tiên source_ledger_item_id để tách đúng hàng chính/hàng tặng cùng SKU.
        $custom_items_by_source_id = [];
        $custom_items_by_sku = [];
        foreach ($custom_items as $ci) {
            $source_item_id = intval($ci['source_ledger_item_id'] ?? 0);
            if ($source_item_id > 0) {
                $custom_items_by_source_id[$source_item_id] = $ci;
            }
            if (!empty($ci['sku'])) {
                $custom_items_by_sku[$ci['sku']] = $ci;
            }
        }

        // Step 1: Query bảng transfer_ledger của shop hiện tại
        $local_transfer_table = $wpdb->prefix . 'transfer_ledger';

        // Query transfer_ledger dựa trên transfer_type
        $local_transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$local_transfer_table}
            WHERE transfer_ledger_id = %d
            AND transfer_type = %d
        ", $transfer_id, $config['transfer_type']));

        if (!$local_transfer) {
            return self::import_reply_error($config['labels']['transfer_not_found']);
        }

        // Kiểm tra đã tạo phiếu đích chưa
        if (!empty($local_transfer->destination_ledger_id)) {
            return self::import_reply_error($config['labels']['already_created']);
        }

        $source_blog_id = intval($local_transfer->source_blog_id);
        $source_ledger_id = intval($local_transfer->source_ledger_id);

        // Step 2: Switch sang nơi xuất để lấy thông tin
        switch_to_blog($source_blog_id);

        $source_ledger_table = $wpdb->prefix . 'local_ledger';
        $source_ledger_item_table = $wpdb->prefix . 'local_ledger_item';

        $source_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$source_ledger_table}
            WHERE local_ledger_id = %d
        ", $source_ledger_id));

        if (!$source_ledger) {
            restore_current_blog();
            return self::import_reply_error($config['labels']['source_not_found']);
        }

        // Kiểm tra phiếu xuất tự động (phiếu con) đã duyệt chưa
        $auto_export_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_id, local_ledger_approver_status
            FROM {$source_ledger_table}
            WHERE local_ledger_parent_id = %d
            AND local_ledger_type = %d
        ", $source_ledger_id, TGS_LEDGER_TYPE_SALE));

        if ($auto_export_ledger) {
            if ($auto_export_ledger->local_ledger_approver_status != TGS_APPROVER_STATUS_APPROVED) {
                restore_current_blog();
                return self::import_reply_error($config['labels']['auto_export_not_approved']);
            }
        } else {
            if ($source_ledger->local_ledger_approver_status != TGS_APPROVER_STATUS_APPROVED) {
                restore_current_blog();
                return self::import_reply_error($config['labels']['source_not_approved']);
            }
        }

        // Lấy các item từ local_ledger_item_id (JSON array của item IDs)
        $item_ids = [];
        if (!empty($source_ledger->local_ledger_item_id)) {
            $item_ids = json_decode($source_ledger->local_ledger_item_id, true) ?: [];
        }

        $source_items = [];
        if (!empty($item_ids)) {
            $item_ids_str = implode(',', array_map('intval', $item_ids));
            $source_items = $wpdb->get_results("
                SELECT li.*
                FROM {$source_ledger_item_table} li
                WHERE li.local_ledger_item_id IN ({$item_ids_str})
                AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
            ");
            $source_items = TGS_Transfer_Global_Products::enrich_ledger_items($source_items, $source_blog_id);
        }

        $source_shop_name = get_bloginfo('name');

        // Lưu nguyên nguyồn phần mềm của phiếu gốc shop kia để copy y hệt sang phiếu mới
        $source_ledger_software_source = $source_ledger->local_ledger_software_source ?? null;

        restore_current_blog();

        if (empty($source_items)) {
            return self::import_reply_error($config['labels']['no_items']);
        }

        // Step 3: Quay về shop hiện tại để tạo phiếu
        $wpdb->query('START TRANSACTION');

        try {
            $ledger_table = $wpdb->prefix . 'local_ledger';
            $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

            // Tạo mã phiếu
            $parent_ledger_code = $config['parent_code_prefix'] . '-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
            $auto_import_code = $config['child_code_prefix'] . '-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));

            // Tính tổng giá trị và xử lý các item
            $total_amount = 0;
            $import_items_data = [];
            $total_max_qty = 0;
            $total_import_qty = 0;

            foreach ($source_items as $source_item) {
                $sku = TGS_Transfer_Global_Products::row_sku((array) $source_item);
                $source_item_id = intval($source_item->local_ledger_item_id ?? 0);
                $custom_item = $source_item_id > 0 && isset($custom_items_by_source_id[$source_item_id])
                    ? $custom_items_by_source_id[$source_item_id]
                    : ($custom_items_by_sku[$sku] ?? []);
                $is_tracking = intval($source_item->local_product_is_tracking) === 1;
                $max_quantity = intval($source_item->quantity);

                $import_quantity = isset($custom_item['import_quantity'])
                    ? intval($custom_item['import_quantity'])
                    : $max_quantity;

                // Xử lý lot_ids cho tracking products
                $lot_barcodes_to_import = [];
                if ($is_tracking && !empty($source_item->list_product_lots)) {
                    $all_lot_ids = json_decode($source_item->list_product_lots, true) ?: [];

                    if (isset($custom_item['selected_lots']) && is_array($custom_item['selected_lots']) && !empty($custom_item['selected_lots'])) {
                        $lot_ids_to_import = array_values(array_intersect(
                            $custom_item['selected_lots'],
                            $all_lot_ids
                        ));
                        $import_quantity = count($lot_ids_to_import);
                    } else {
                        $lot_ids_to_import = array_slice($all_lot_ids, 0, $import_quantity);
                    }

                    foreach ($lot_ids_to_import as $lot_id) {
                        $lot = $wpdb->get_row($wpdb->prepare("
                            SELECT global_product_lot_barcode FROM {$lots_table}
                            WHERE global_product_lot_id = %d
                        ", $lot_id));
                        if ($lot) {
                            $lot_barcodes_to_import[] = $lot->global_product_lot_barcode;
                        }
                    }
                }

                if ($import_quantity < 0) $import_quantity = 0;
                if ($import_quantity > $max_quantity) $import_quantity = $max_quantity;

                $total_max_qty += $max_quantity;
                $total_import_qty += $import_quantity;

                if ($import_quantity <= 0) {
                    continue;
                }

                $global_product_id = TGS_Transfer_Global_Products::row_product_id((array) $source_item);
                if ($global_product_id <= 0 || !TGS_Transfer_Global_Products::product_exists_for_item($source_item, $current_blog_id)) {
                    throw new Exception("Không tìm thấy sản phẩm global cho SKU '{$sku}'");
                }

                $price = floatval($source_item->price ?? 0);
                $tax_percent = floatval($source_item->local_ledger_item_tax_percent ?? 0);
                $discount_percent = floatval($source_item->local_ledger_item_discount ?? 0);

                $subtotal_no_vat = $import_quantity * $price;
                $discount_amount = round($subtotal_no_vat * ($discount_percent / 100));
                $after_discount = $subtotal_no_vat - $discount_amount;
                $tax_amount = round($after_discount * ($tax_percent / 100));
                $subtotal = round($after_discount + $tax_amount);

                $total_amount += $subtotal;

                $item_note = isset($custom_item['item_note'])
                    ? sanitize_textarea_field($custom_item['item_note'])
                    : ($source_item->local_ledger_item_note ?? '');
                $unit_snapshot = self::build_copied_unit_snapshot($source_item, $import_quantity, $max_quantity);
                $is_gift = intval($source_item->local_ledger_item_gift_type ?? 0) === 1;

                $import_items_data[] = [
                    'product_id' => $global_product_id,
                    'quantity' => $import_quantity,
                    'price' => $price,
                    'tax_percent' => $tax_percent,
                    'tax_amount' => $tax_amount,
                    'discount_type' => 'percent',
                    'discount_value' => $discount_percent,
                    'discount_amount' => $discount_amount,
                    'subtotal' => $subtotal,
                    'lot_barcodes' => $lot_barcodes_to_import,
                    'is_tracking' => $is_tracking,
                    'is_gift' => $is_gift ? 1 : 0,
                    'source_item' => $source_item,
                    'local_product' => $source_item,
                    'max_quantity' => $max_quantity,
                    'note' => $item_note,
                    'batch_id' => self::resolve_batch_id_for_item($source_item, $is_tracking),
                    'sku' => $sku,
                    'unit' => $source_item->local_product_unit ?? '',
                    'unit_quantity' => $unit_snapshot['unit_quantity'],
                    'unit_ratio' => $unit_snapshot['unit_ratio'],
                    'unit_name' => $unit_snapshot['unit_name'],
                    'total_weight_kg' => $unit_snapshot['total_weight_kg'],
                    'doc_quantity' => floatval($source_item->local_ledger_item_doc_quantity ?? 0),
                    'software_source' => $source_item->local_ledger_item_software_source ?? $source_ledger_software_source,
                ];
            }

            if (empty($import_items_data)) {
                throw new Exception($config['labels']['select_items']);
            }

            $is_partial = ($total_import_qty < $total_max_qty);

            // ========== BƯỚC 1: Tạo phiếu CHA ==========
            $note_suffix = $is_partial
                ? "\n[{$config['labels']['note_suffix_partial']}: {$source_ledger->local_ledger_code}] - Nhận 1 phần: {$total_import_qty}/{$total_max_qty}"
                : "\n[{$config['labels']['note_suffix_full']}: {$source_ledger->local_ledger_code}]";

            // Tạo title từ template (nếu có)
            $parent_title = '';
            if (!empty($config['parent_title_template'])) {
                $parent_title = sprintf($config['parent_title_template'], $parent_ledger_code);
            }

            // Xử lý advance_meta: giữ file từ nơi xuất hoặc dùng danh sách frontend gửi lên
            $advance_meta_to_save = null;
            if (!empty($advance_meta_raw)) {
                // Validate JSON từ frontend
                $decoded = json_decode($advance_meta_raw, true);
                if (is_array($decoded)) {
                    $advance_meta_to_save = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            } elseif (!empty($source_ledger->local_ledger_advance_meta)) {
                // Fallback: copy advance_meta từ nơi xuất
                $source_meta = json_decode($source_ledger->local_ledger_advance_meta, true);
                if (is_array($source_meta) && !empty($source_meta['doc_files'])) {
                    $advance_meta_to_save = wp_json_encode(['doc_files' => $source_meta['doc_files']], JSON_UNESCAPED_UNICODE);
                }
            }

            $wpdb->insert($ledger_table, [
                'local_ledger_code' => $parent_ledger_code,
                'local_ledger_title' => $parent_title,
                'local_ledger_type' => $config['parent_ledger_type'],
                'local_ledger_note' => $import_note . $note_suffix,
                'local_ledger_total_amount' => $total_amount,
                'local_ledger_status' => TGS_LEDGER_STATUS_PENDING,
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_PENDING,
                'local_ledger_software_source' => $source_ledger_software_source,
                'local_ledger_advance_meta' => $advance_meta_to_save,
                'user_id' => $current_user_id,
                'is_deleted' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);

            $parent_ledger_id = $wpdb->insert_id;

            if (!$parent_ledger_id) {
                throw new Exception($config['labels']['parent_error']);
            }

            // ========== BƯỚC 2: Tạo phiếu CON (Nhập tự động) ==========
            // Tạo title cho phiếu con từ template (nếu có)
            $child_title = '';
            if (!empty($config['child_title_template'])) {
                $child_title = sprintf($config['child_title_template'], $parent_ledger_code);
            }

            $auto_import_ledger_data = [
                'local_ledger_code' => $auto_import_code,
                'local_ledger_title' => $child_title,
                'local_ledger_type' => TGS_LEDGER_TYPE_PURCHASE,
                'local_ledger_note' => 'Nhập tự động từ phiếu: ' . $parent_ledger_code,
                'local_ledger_total_amount' => $total_amount,
                'local_ledger_status' => TGS_LEDGER_STATUS_PENDING,
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_PENDING,
                'local_ledger_software_source' => $source_ledger_software_source,
                'user_id' => $current_user_id,
            ];

            $auto_import_result = TGS_Shop_Base_Import_Export::create_import_ledger(
                $auto_import_ledger_data,
                $import_items_data,
                $parent_ledger_id
            );

            $auto_import_ledger_id = $auto_import_result['ledger_id'];
            $auto_import_item_ids = $auto_import_result['items'];

            // ========== BƯỚC 3: Cập nhật phiếu CHA với item IDs ==========
            $items_json_encoded = json_encode($auto_import_item_ids, JSON_UNESCAPED_UNICODE);
            $wpdb->update($ledger_table, [
                'local_ledger_item_id' => $items_json_encoded
            ], ['local_ledger_id' => $parent_ledger_id]);

            // ========== BƯỚC 4: Cập nhật transfer_ledger ở nơi xuất ==========
            switch_to_blog($source_blog_id);

            $source_transfer_table_name = $wpdb->prefix . 'transfer_ledger';
            $wpdb->update($source_transfer_table_name, [
                'destination_ledger_id' => $parent_ledger_id,
                'destination_ledger_item_id' => $items_json_encoded,
            ], [
                'source_ledger_id' => $source_ledger_id,
                'transfer_type' => $config['transfer_type']
            ]);

            restore_current_blog();

            // ========== BƯỚC 5: Cập nhật transfer_ledger ở shop hiện tại ==========
            $wpdb->update($local_transfer_table, [
                'destination_ledger_id' => $parent_ledger_id,
                'destination_ledger_item_id' => $items_json_encoded,
            ], ['transfer_ledger_id' => $transfer_id]);

            $wpdb->query('COMMIT');

            // ========== GHI LOG LOT ==========
            foreach ($import_items_data as $item_data) {
                if ($item_data['is_tracking'] && !empty($item_data['lot_barcodes'])) {
                    foreach ($item_data['lot_barcodes'] as $lot_barcode) {
                        $lot = $wpdb->get_row($wpdb->prepare("
                            SELECT global_product_lot_id FROM {$lots_table}
                            WHERE global_product_lot_barcode = %s
                        ", $lot_barcode));
                        if ($lot) {
                            TGS_Global_Lots_Helper::add_lot_log($lot->global_product_lot_id, $config['log_action'], [
                                'source_blog_id' => $source_blog_id,
                                'destination_blog_id' => $current_blog_id,
                                'parent_ledger_id' => $parent_ledger_id,
                                'auto_import_ledger_id' => $auto_import_ledger_id,
                                'ledger_code' => $parent_ledger_code,
                                'source_ledger_id' => $source_ledger_id,
                                'source_ledger_code' => $source_ledger->local_ledger_code ?? '',
                                'is_partial' => $is_partial
                            ]);
                        }
                    }
                }
            }

            // ========== THÊM TICKET LOG ==========
            TGS_Shop_Ticket_Helper::add_ticket_log($parent_ledger_id, 'create', [
                'source_blog_id' => $source_blog_id,
                'source_shop_name' => $source_shop_name,
                'items_count' => count($import_items_data),
                'total_amount' => $total_amount,
                'is_partial' => $is_partial,
                'auto_import_ledger_id' => $auto_import_ledger_id,
                'auto_import_code' => $auto_import_code,
                'transfer_type' => $config['ticket_log_type'] ?? 'import'
            ], $config['labels']['ticket_log_desc'] . ': ' . $source_shop_name);

            // ========== HOOK: tgs_ticket_created — doc_tracker ghi nhận lệch chứng từ ==========
            if (!empty($config['doc_tracker_ticket_type'])) {
                $hook_items = [];
                foreach ($import_items_data as $item_data) {
                    $hook_items[] = [
                        'product_id'   => intval($item_data['product_id'] ?? 0),
                        'quantity'     => floatval($item_data['quantity'] ?? 0),
                        'doc_quantity' => floatval($item_data['doc_quantity'] ?? 0),
                        'software_source' => $item_data['software_source'] ?? null,
                    ];
                }
                do_action('tgs_ticket_created', $parent_ledger_id, $config['doc_tracker_ticket_type'], [
                    'items'           => $hook_items,
                    'blog_id'         => get_current_blog_id(),
                    'user_id'         => $current_user_id,
                    'ticket_code'     => $parent_ledger_code,
                    'child_ledger_id' => $auto_import_ledger_id,
                    'software_source' => $source_ledger_software_source ?? null,
                ]);
            }

            return self::import_reply_success([
                'message' => $config['success_message'],
                'ledger_id' => $parent_ledger_id,
                'auto_import_ledger_id' => $auto_import_ledger_id,
                'ledger_code' => $parent_ledger_code,
                'auto_import_code' => $auto_import_code,
                'is_partial' => $is_partial,
                'total_imported' => $total_import_qty,
                'total_max' => $total_max_qty,
                'redirect_url' => admin_url('admin.php?page=tgs-shop-management&view=' . $config['redirect_view'] . '&id=' . $parent_ledger_id)
            ]);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return self::import_reply_error($e->getMessage());
        }
    }

    /**
     * Tạo phiếu nhận nội bộ (nhập từ nơi xuất)
     * Gọi đến do_create_import_internal với config cho IMPORT flow
     */
    public static function create_import()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        // Gọi hàm dùng chung với config cho phiếu nhận nội bộ
        self::do_create_import_internal(self::internal_import_config());
    }

    /**
     * Cấu hình phiếu nhận nội bộ (MNB) + phiếu nhập tự động (AMN).
     *
     * Tách riêng vì nay có HAI đường gọi tới cùng cấu hình này:
     *   - create_import()  : nơi nhận tự bấm tạo ở màn "chờ nhận nội bộ"
     *   - auto_create_destination_import() : tự sinh khi nơi xuất duyệt xuất
     */
    private static function internal_import_config()
    {
        return [
            'transfer_type' => TGS_TRANSFER_TYPE_INTERNAL,        // 1
            'parent_ledger_type' => TGS_LEDGER_TYPE_TRANSFER_IMPORT, // 13
            'source_parent_type' => TGS_LEDGER_TYPE_TRANSFER_EXPORT, // 12
            'parent_code_prefix' => 'MNB',                        // Nhận Nội Bộ
            'child_code_prefix' => 'AMN',                         // Auto Mua Nội bộ
            'parent_title_template' => 'Thông tin phiếu nhận nội bộ %s', // %s = code
            'child_title_template' => 'Nhập tự động từ %s', // %s = parent code
            'log_action' => 'transfer_import_created',
            'redirect_view' => 'ticket-transfer-import-detail',
            'success_message' => 'Tạo phiếu nhận nội bộ thành công',
            'ticket_log_type' => 'import',
            'doc_tracker_ticket_type' => 'internal_purchase',
            'labels' => [
                'transfer_not_found' => 'Không tìm thấy phiếu chuyển',
                'already_created' => 'Phiếu này đã được tạo phiếu nhập trước đó',
                'source_not_found' => 'Không tìm thấy phiếu xuất nguồn',
                'auto_export_not_approved' => 'Phiếu xuất tự động chưa được nơi xuất duyệt',
                'source_not_approved' => 'Phiếu xuất chưa được nơi xuất duyệt',
                'no_items' => 'Không có sản phẩm trong phiếu xuất',
                'select_items' => 'Vui lòng chọn ít nhất 1 sản phẩm để nhập',
                'parent_error' => 'Lỗi tạo phiếu nhập từ mẹ (phiếu cha)',
                'note_suffix_partial' => 'Từ phiếu xuất',
                'note_suffix_full' => 'Từ phiếu xuất',
                'ticket_log_desc' => 'Tạo phiếu nhận nội bộ từ shop'
            ]
        ];
    }

    /**
     * Tự sinh phiếu nhận nội bộ (chờ duyệt) + phiếu nhập bên shop NHẬN,
     * ngay khi nơi xuất duyệt phiếu xuất kho.
     *
     * Trước đây nơi nhận phải vào màn "Chờ nhận nội bộ", bấm xem rồi bấm tạo —
     * thao tác thừa vì gần như luôn nhận đủ đúng những gì nơi xuất đã xuất.
     *
     * Chạy trên blog của nơi nhận (switch_to_blog) vì mọi thứ bên trong đều
     * dùng $wpdb->prefix của site hiện tại: transfer_ledger, local_ledger,
     * local_ledger_item… đều là bảng riêng của từng shop.
     *
     * KHÔNG ném lỗi ra ngoài: nơi xuất đã duyệt xong và hàng đã trừ kho, hỏng
     * bước này thì cùng lắm nơi nhận vào màn chờ bấm tạo tay như cũ. Chặn cả
     * việc duyệt lại vì lỗi ở site khác là thiệt hơn nhiều.
     *
     * @return array|null Kết quả để ghi log / trả kèm response.
     */
    private static function auto_create_destination_import($transfer, $destination_blog_id, $source_blog_id)
    {
        if (empty($destination_blog_id) || empty($transfer->source_ledger_id)) {
            return null;
        }

        $result = null;
        switch_to_blog($destination_blog_id);

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'transfer_ledger';

            /*
             * Dòng transfer_ledger BÊN SHOP NHẬN là một bản ghi riêng, có
             * transfer_ledger_id khác hẳn bên nguồn. Nối hai bên bằng cặp
             * (source_blog_id, source_ledger_id) — chính là cách
             * create_transfer_records() ở plugin shop đã ghi lúc tạo phiếu.
             */
            $dest = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table}
                  WHERE source_blog_id = %d AND source_ledger_id = %d AND transfer_type = %d
                  ORDER BY transfer_ledger_id DESC LIMIT 1",
                $source_blog_id,
                intval($transfer->source_ledger_id),
                TGS_TRANSFER_TYPE_INTERNAL
            ));

            if (!$dest) {
                $result = ['ok' => false, 'message' => 'Không tìm thấy bản ghi transfer ở nơi nhận'];
            } elseif (!empty($dest->destination_ledger_id)) {
                // Đã có phiếu rồi (duyệt lại, hoặc nơi nhận vừa bấm tạo tay)
                $result = ['ok' => true, 'skipped' => true, 'message' => 'Nơi nhận đã có phiếu nhận nội bộ'];
            } else {
                $result = self::do_create_import_internal(self::internal_import_config(), [
                    'transfer_id'        => intval($dest->transfer_ledger_id),
                    'created_by_user_id' => 0, // hệ thống, không mượn danh người duyệt bên nơi xuất
                    'note'               => 'Tự động tạo khi nơi xuất duyệt phiếu xuất kho.',
                    'items'              => '', // rỗng = nhận toàn bộ theo số lượng gốc
                ]);
            }
        } catch (Exception $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        self::$import_silent = false; // trả cờ về mặc định cho phần sau của request
        restore_current_blog();

        return $result;
    }

    /**
     * Duyệt phiếu nhập - thực hiện:
     * 1. Chuyển lot sang ACTIVE trong kho hiện tại
     * 2. Không ghi tồn catalog local; tồn non-tracking tính từ ledger/API theo SKU
     * 3. Cập nhật transfer_status thành ACCEPTED hoặc PARTIAL
     */
    public static function approve_import()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

        // Lấy thông tin phiếu con nhập kho (type 1 - PURCHASE)
        $child_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_PURCHASE));

        if (!$child_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu nhập kho']);
        }

        if ($child_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED) {
            wp_send_json_error(['message' => 'Phiếu đã được duyệt trước đó']);
        }

        // Tìm phiếu cha TRANSFER_IMPORT (type 13) hoặc INTERNAL_RETURN_RECEIVE (type 15)
        $parent_id = intval($child_ledger->local_ledger_parent_id);
        if (!$parent_id) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu cha']);
        }

        // Check cả 2 loại phiếu cha: nhận nội bộ (13) và nhận trả nội bộ (15)
        $parent_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type IN (%d, %d)
        ", $parent_id, TGS_LEDGER_TYPE_TRANSFER_IMPORT, TGS_LEDGER_TYPE_INTERNAL_RETURN_RECEIVE));

        if (!$parent_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu nhận/nhận trả nội bộ']);
        }

        // Lấy các item từ phiếu con nhập kho
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*
            FROM {$ledger_item_table} li
            WHERE li.local_ledger_id = %d
        ", $ledger_id));
        $items = TGS_Transfer_Global_Products::enrich_ledger_items($items, $current_blog_id);

        // Tìm transfer_ledger thông qua phiếu cha để xác định is_partial
        $local_transfer_table = $wpdb->prefix . 'transfer_ledger';
        $local_transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$local_transfer_table}
            WHERE destination_ledger_id = %d
        ", $parent_id));

        // Xác định có phải nhập 1 phần không bằng cách so sánh với phiếu xuất gốc
        $is_partial = false;
        $all_source_lot_ids_for_partial = []; // Lưu để dùng cho việc cập nhật lot status = 5

        if ($local_transfer) {
            $source_blog_id = intval($local_transfer->source_blog_id);
            $source_ledger_id = intval($local_transfer->source_ledger_id);

            // Switch sang shop mẹ để lấy thông tin phiếu xuất gốc
            switch_to_blog($source_blog_id);

            $source_ledger_table = $wpdb->prefix . 'local_ledger';
            $source_ledger_item_table = $wpdb->prefix . 'local_ledger_item';

            // Lấy local_ledger_item_id từ phiếu cha xuất
            $source_ledger_data = $wpdb->get_row($wpdb->prepare("
                SELECT local_ledger_item_id FROM {$source_ledger_table}
                WHERE local_ledger_id = %d
            ", $source_ledger_id));

            // Lấy tổng quantity VÀ danh sách lot IDs từ các items (qua local_ledger_item_id)
            $source_items_data = [];
            if ($source_ledger_data && !empty($source_ledger_data->local_ledger_item_id)) {
                $source_item_ids = json_decode($source_ledger_data->local_ledger_item_id, true) ?: [];
                if (!empty($source_item_ids)) {
                    $source_item_ids_str = implode(',', array_map('intval', $source_item_ids));
                    $source_items_data = $wpdb->get_results("
                        SELECT quantity, list_product_lots FROM {$source_ledger_item_table}
                        WHERE local_ledger_item_id IN ({$source_item_ids_str})
                          AND (is_deleted = 0 OR is_deleted IS NULL)
                    ", ARRAY_A);
                }
            }

            restore_current_blog();

            // Tính tổng quantity và thu thập lot IDs từ phiếu xuất gốc
            $source_total = 0;
            foreach ($source_items_data as $source_item) {
                $source_total += floatval($source_item['quantity']);
                $lots = !empty($source_item['list_product_lots']) ? json_decode($source_item['list_product_lots'], true) : [];
                $lots = $lots ?: [];
                $all_source_lot_ids_for_partial = array_merge($all_source_lot_ids_for_partial, array_map('intval', $lots));
            }
            $all_source_lot_ids_for_partial = array_unique(array_filter($all_source_lot_ids_for_partial));

            // Tính tổng quantity và thu thập lot IDs đã nhập
            $imported_total = 0;
            $imported_lot_ids_for_partial = [];
            foreach ($items as $item) {
                $imported_total += floatval($item->quantity);
                $lots = !empty($item->list_product_lots) ? json_decode($item->list_product_lots, true) : [];
                $lots = $lots ?: [];
                $imported_lot_ids_for_partial = array_merge($imported_lot_ids_for_partial, array_map('intval', $lots));
            }
            $imported_lot_ids_for_partial = array_unique(array_filter($imported_lot_ids_for_partial));

            // So sánh - là partial nếu:
            // 1. Tổng quantity nhập < tổng quantity xuất, HOẶC
            // 2. Số lượng lot nhập < số lượng lot xuất (cho tracking products)
            $is_partial = ($imported_total < floatval($source_total)) ||
                (count($imported_lot_ids_for_partial) < count($all_source_lot_ids_for_partial));
        }

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    // Cập nhật lot thành ACTIVE hoặc DAMAGED (nếu condition = 1)
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];
                    $source_blog_id = $local_transfer ? intval($local_transfer->source_blog_id) : 0;

                    foreach ($lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main_and_sku($lot_id, $item->local_product_name_id);

                        // Lấy thông tin condition của lot để xác định status
                        $lot_condition = $wpdb->get_var($wpdb->prepare(
                            "SELECT global_product_lot_condition FROM {$lots_table} WHERE global_product_lot_id = %d",
                            $lot_id
                        ));

                        // Nếu condition = 3 (lỗi) thì set is_active = 3 (DAMAGED/đã hủy)
                        // Ngược lại set is_active = 1 (ACTIVE)
                        $new_lot_status = (intval($lot_condition) === 3)
                            ? TGS_PRODUCT_LOT_DAMAGED
                            : TGS_PRODUCT_LOT_ACTIVE;

                        // Cập nhật lot về shop hiện tại và status tương ứng
                        // Note: to_blog_id đã đúng rồi (được set từ lúc shop mẹ duyệt xuất), không cần update
                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => $new_lot_status,
                            'local_product_name_id' => $item->local_product_name_id,
                            'local_imported_date' => time(),
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        // ========== GHI LOG VÀO product_lot_meta ==========
                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'transfer_import_approved', [
                            'previous_status' => TGS_PRODUCT_LOT_PENDING,
                            'new_status' => $new_lot_status,
                            'lot_condition' => intval($lot_condition),
                            'is_damaged' => (intval($lot_condition) === 3),
                            'source_blog_id' => $source_blog_id,
                            'to_blog_id' => $current_blog_id,
                            'ledger_id' => $ledger_id,
                            'ledger_code' => $child_ledger->local_ledger_code ?? ''
                        ]);
                    }
                } else {
                    // Không ghi tồn vào catalog local. Tồn non-tracking được tính từ ledger/API theo SKU.
                }
            }

            // ========== CẬP NHẬT BATCH_DISTRIBUTION - Transfer Import ==========
            // Tăng qty tại shop đích (hàng đã nhận)
            if (class_exists('TGS_Batch_Distribution')) {
                $items_with_batch = self::collect_items_with_batch($items);
                if (!empty($items_with_batch)) {
                    TGS_Batch_Distribution::on_transfer_import_approved($current_blog_id, $items_with_batch);

                    // Ghi batch_movement cho transfer import
                    $source_blog_id_for_mv = $local_transfer ? intval($local_transfer->source_blog_id) : 0;
                    self::record_batch_movements($items_with_batch, $source_blog_id_for_mv, $current_blog_id, 1, $ledger_id, $current_blog_id);
                }
            }

            // Cập nhật trạng thái phiếu
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_APPROVED,
                'local_ledger_status' => TGS_LEDGER_STATUS_APPROVED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Xác định transfer_status: ACCEPTED (nhập hết) hoặc PARTIAL (nhập 1 phần)
            $final_transfer_status = $is_partial
                ? TGS_TRANSFER_STATUS_PARTIAL
                : TGS_TRANSFER_STATUS_ACCEPTED;

            if ($local_transfer) {
                $source_blog_id = intval($local_transfer->source_blog_id);

                // Cập nhật transfer_ledger ở shop mẹ
                switch_to_blog($source_blog_id);

                $source_transfer_table = $wpdb->prefix . 'transfer_ledger';
                $wpdb->update($source_transfer_table, [
                    'transfer_status' => $final_transfer_status,
                    'accepted_at' => current_time('mysql'),
                    'accepted_by_user_id' => $current_user_id
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);

                restore_current_blog();

                // Cập nhật transfer_ledger ở shop con (hiện tại)
                $wpdb->update($local_transfer_table, [
                    'transfer_status' => $final_transfer_status,
                    'accepted_at' => current_time('mysql'),
                    'accepted_by_user_id' => $current_user_id
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);
            }

            // ===== XỬ LÝ CÁC LOT CHƯA NHẬP (STATUS = 5) =====
            // Nếu là nhập 1 phần, cần cập nhật các lot chưa được nhập về status = 5
            if ($is_partial && $local_transfer && !empty($all_source_lot_ids_for_partial)) {
                // Sử dụng biến đã thu thập ở trên thay vì query lại
                $all_source_lot_ids = $all_source_lot_ids_for_partial;
                $source_blog_id = intval($local_transfer->source_blog_id);

                // Thu thập các lot đã nhập trong phiếu nhập hiện tại
                $imported_lot_ids = [];
                foreach ($items as $item) {
                    $lots = !empty($item->list_product_lots) ? json_decode($item->list_product_lots, true) : [];
                    $lots = $lots ?: [];
                    $imported_lot_ids = array_merge($imported_lot_ids, array_map('intval', $lots));
                }
                $imported_lot_ids = array_unique(array_filter($imported_lot_ids));

                // Tìm các lot chưa nhập = lot từ phiếu xuất - lot đã nhập
                $not_imported_lot_ids = array_diff($all_source_lot_ids, $imported_lot_ids);

                // Cập nhật status = 5 (Chờ xử lý trả về shop mẹ) cho các lot chưa nhập
                if (!empty($not_imported_lot_ids)) {
                    foreach ($not_imported_lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main_and_sku($lot_id);

                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING_RETURN, // Chờ xử lý trả về shop mẹ
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        // ========== GHI LOG VÀO product_lot_meta ==========
                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'transfer_partial_pending_return', [
                            'previous_status' => TGS_PRODUCT_LOT_PENDING,
                            'new_status' => TGS_PRODUCT_LOT_PENDING_RETURN,
                            'source_blog_id' => $source_blog_id,
                            'to_blog_id' => $current_blog_id,
                            'ledger_id' => $ledger_id,
                            'ledger_code' => $child_ledger->local_ledger_code ?? '',
                            'reason' => 'Không được nhập trong phiếu nhập 1 phần'
                        ]);
                    }
                }
            }
            // ===== END: XỬ LÝ LOT CHƯA NHẬP =====

            $wpdb->query('COMMIT');

            // Thêm log duyệt phiếu nhập kho
            $source_shop_name = $local_transfer ? get_blog_option(intval($local_transfer->source_blog_id), 'blogname') : '';
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'approve', [
                'source_blog_id' => $local_transfer ? intval($local_transfer->source_blog_id) : 0,
                'source_shop_name' => $source_shop_name,
                'items_count' => count($items),
                'is_partial' => $is_partial,
                'transfer_status' => $final_transfer_status,
                'note' => $note,
                'parent_ledger_id' => $parent_id,
                'parent_ledger_code' => $parent_ledger->local_ledger_code ?? ''
            ], !empty($note) ? $note : ($is_partial ? 'Duyệt phiếu nhập kho (1 phần) từ shop: ' . $source_shop_name : 'Duyệt phiếu nhập kho từ shop: ' . $source_shop_name));

            $status_message = $is_partial
                ? 'Duyệt phiếu nhập kho thành công (Nhập 1 phần). Hàng đã vào kho.'
                : 'Duyệt phiếu nhập kho thành công. Hàng đã vào kho.';

            wp_send_json_success([
                'message' => $status_message,
                'is_partial' => $is_partial,
                'transfer_status' => $final_transfer_status
            ]);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Lấy danh sách phiếu xuất
     */
    public static function get_exports_list()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $exports = $wpdb->get_results($wpdb->prepare("
            SELECT l.*, t.destination_blog_id, t.transfer_status, t.transfer_ledger_id
            FROM {$ledger_table} l
            LEFT JOIN {$transfer_table} t ON l.local_ledger_id = t.source_ledger_id
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            ORDER BY l.created_at DESC
        ", TGS_LEDGER_TYPE_TRANSFER_EXPORT));

        // Thêm tên shop đích
        foreach ($exports as &$export) {
            if ($export->destination_blog_id) {
                $export->destination_shop_name = get_blog_details($export->destination_blog_id)->blogname ?? 'Shop #' . $export->destination_blog_id;
            }
        }

        wp_send_json_success(['exports' => $exports]);
    }

    /**
     * Lấy danh sách phiếu nhập
     */
    public static function get_imports_list()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;

        $ledger_table = $wpdb->prefix . 'local_ledger';

        $imports = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$ledger_table}
            WHERE local_ledger_type = %d
            AND (is_deleted IS NULL OR is_deleted = 0)
            ORDER BY created_at DESC
        ", TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        wp_send_json_success(['imports' => $imports]);
    }

    /**
     * Lấy chi tiết phiếu transfer
     */
    public static function get_detail()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $type = sanitize_text_field($_POST['type'] ?? 'export');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_type = $type === 'import' ? TGS_LEDGER_TYPE_TRANSFER_IMPORT : TGS_LEDGER_TYPE_TRANSFER_EXPORT;

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';

        $ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, $ledger_type));

        if (!$ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu']);
        }

        // Lấy các item
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*
            FROM {$ledger_item_table} li
            WHERE li.local_ledger_id = %d
        ", $ledger_id));
        $items = TGS_Transfer_Global_Products::enrich_ledger_items($items, get_current_blog_id());

        // Nếu là phiếu xuất, lấy thêm thông tin transfer
        $transfer = null;
        if ($type === 'export') {
            $transfer_table = $wpdb->prefix . 'transfer_ledger';
            $transfer = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$transfer_table}
                WHERE source_ledger_id = %d
            ", $ledger_id));

            if ($transfer && $transfer->destination_blog_id) {
                $transfer->destination_shop_name = get_blog_details($transfer->destination_blog_id)->blogname ?? 'Shop #' . $transfer->destination_blog_id;
            }
        }

        wp_send_json_success([
            'ledger' => $ledger,
            'items' => $items,
            'transfer' => $transfer
        ]);
    }

    /**
     * Lấy chi tiết transfer theo transfer_id
     *
     * Logic:
     * - Query bảng transfer_ledger của shop hiện tại để lấy source_blog_id, source_ledger_id
     * - Switch sang shop mẹ (source_blog_id) để lấy thông tin ledger và items
     */
    public static function get_transfer_detail()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $transfer_id = intval($_POST['transfer_id'] ?? 0);

        if (!$transfer_id) {
            wp_send_json_error(['message' => 'Thiếu ID transfer']);
        }

        // Step 1: Query bảng transfer_ledger của shop hiện tại
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$transfer_table}
            WHERE transfer_ledger_id = %d
        ", $transfer_id));

        if (!$transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy transfer']);
        }

        $source_blog_id = intval($transfer->source_blog_id);
        $source_ledger_id = intval($transfer->source_ledger_id);
        $destination_blog_id = intval($transfer->destination_blog_id);

        // Step 2: Switch sang shop mẹ để lấy thông tin ledger và items
        switch_to_blog($source_blog_id);

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';

        // Lấy thông tin ledger từ shop mẹ (bao gồm advance_meta + nguyồn phần mềm để kế thừa)
        $ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_code, local_ledger_total_amount, local_ledger_note,
                   local_ledger_approver_status, local_ledger_advance_meta,
                   local_ledger_software_source
            FROM {$ledger_table}
            WHERE local_ledger_id = %d
        ", $source_ledger_id));

        if ($ledger) {
            $transfer->local_ledger_code = $ledger->local_ledger_code;
            $transfer->local_ledger_total_amount = $ledger->local_ledger_total_amount;
            $transfer->local_ledger_note = $ledger->local_ledger_note;
            $transfer->local_ledger_approver_status = $ledger->local_ledger_approver_status;
            $transfer->local_ledger_advance_meta = $ledger->local_ledger_advance_meta;
            $transfer->local_ledger_software_source = $ledger->local_ledger_software_source;
        }

        // Kiểm tra phiếu xuất tự động (phiếu con) đã duyệt chưa
        // Phiếu xuất tự động có local_ledger_parent_id = phiếu cha (type 12)
        $auto_export_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_id, local_ledger_approver_status
            FROM {$ledger_table}
            WHERE local_ledger_parent_id = %d
            AND local_ledger_type = %d
        ", $source_ledger_id, TGS_LEDGER_TYPE_SALE));

        if ($auto_export_ledger) {
            // Check trạng thái duyệt của phiếu xuất tự động
            if ($auto_export_ledger->local_ledger_approver_status != TGS_APPROVER_STATUS_APPROVED) {
                restore_current_blog();
                wp_send_json_error(['message' => 'Phiếu này chưa được nơi xuất duyệt.']);
            }
        } else {
            // Fallback: nếu không có phiếu con thì check phiếu cha
            if ($ledger && $ledger->local_ledger_approver_status != TGS_APPROVER_STATUS_APPROVED) {
                restore_current_blog();
                wp_send_json_error(['message' => 'Phiếu này chưa được nơi xuất duyệt.']);
            }
        }

        $transfer->source_shop_name = get_bloginfo('name');

        // Lấy items từ shop mẹ - phiếu cha lưu item IDs trong local_ledger_item_id
        $source_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_item_id FROM {$ledger_table}
            WHERE local_ledger_id = %d
        ", $source_ledger_id));

        $items = [];
        if ($source_ledger && !empty($source_ledger->local_ledger_item_id)) {
            $item_ids = json_decode($source_ledger->local_ledger_item_id, true) ?: [];
            if (!empty($item_ids)) {
                $item_ids_str = implode(',', array_map('intval', $item_ids));
                $items = $wpdb->get_results("
                    SELECT li.*
                    FROM {$ledger_item_table} li
                    WHERE li.local_ledger_item_id IN ({$item_ids_str})
                    AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
                ");
                $items = TGS_Transfer_Global_Products::enrich_ledger_items($items, $source_blog_id);
            }
        }

        restore_current_blog();

        // Step 3: Catalog dùng chung global nên sản phẩm luôn đồng bộ cho shop đích.
        foreach ($items as &$item) {
            $item->synced_in_destination = true;
        }
        unset($item); // CRITICAL: Break reference to avoid overwriting last element

        // Step 4: Lấy thông tin chi tiết lots từ bảng global cho các sản phẩm tracking
        foreach ($items as &$item) {
            $item->lots_detail = [];

            if ($item->is_tracking && !empty($item->list_product_lots)) {
                $lot_ids = json_decode($item->list_product_lots, true);
                if (!empty($lot_ids) && is_array($lot_ids)) {
                    // Sử dụng helper để lấy thông tin lots từ bảng global
                    $lots = TGS_Global_Lots_Helper::get_lots_by_ids($lot_ids);

                    // Build array với thông tin cần thiết
                    foreach ($lots as $lot) {
                        $item->lots_detail[] = [
                            'id' => intval($lot->global_product_lot_id),
                            'barcode' => $lot->global_product_lot_barcode,
                            'exp_date' => $lot->exp_date,
                            'mfg_date' => $lot->mfg_date,
                            'lot_code' => $lot->lot_code,
                            'condition' => intval($lot->global_product_lot_condition ?? 0)
                        ];
                    }
                }
            }
        }
        unset($item); // CRITICAL: Break reference

        wp_send_json_success([
            'transfer' => $transfer,
            'items' => $items
        ]);
    }

    /**
     * Lấy danh sách items của một transfer
     *
     * Logic:
     * - Query bảng transfer_ledger của shop hiện tại để lấy source_blog_id và source_ledger_id
     * - Switch sang shop mẹ (source_blog_id) để lấy danh sách sản phẩm từ local_ledger_item
     */
    public static function get_transfer_items()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;

        $transfer_id = intval($_POST['transfer_id'] ?? 0);

        if (!$transfer_id) {
            wp_send_json_error(['message' => 'Thiếu ID transfer']);
        }

        $current_blog_id = get_current_blog_id();
        $items = [];

        // Step 1: Query bảng transfer_ledger của shop hiện tại
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT source_blog_id, source_ledger_id
            FROM {$transfer_table}
            WHERE transfer_ledger_id = %d
        ", $transfer_id));

        if (!$transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy thông tin transfer']);
        }

        $source_blog_id = intval($transfer->source_blog_id);
        $source_ledger_id = intval($transfer->source_ledger_id);

        // Step 2: Switch sang shop mẹ để lấy danh sách sản phẩm
        switch_to_blog($source_blog_id);

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';

        // Lấy local_ledger_item_id từ phiếu cha
        $source_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_item_id FROM {$ledger_table}
            WHERE local_ledger_id = %d
        ", $source_ledger_id));

        $items = [];
        if ($source_ledger && !empty($source_ledger->local_ledger_item_id)) {
            $item_ids = json_decode($source_ledger->local_ledger_item_id, true) ?: [];
            if (!empty($item_ids)) {
                $item_ids_str = implode(',', array_map('intval', $item_ids));
                $items = $wpdb->get_results("
                    SELECT li.*
                    FROM {$ledger_item_table} li
                    WHERE li.local_ledger_item_id IN ({$item_ids_str})
                    AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
                ");
                $items = TGS_Transfer_Global_Products::enrich_ledger_items($items, $source_blog_id);
            }
        }

        restore_current_blog();

        wp_send_json_success($items);
    }

    /**
     * Cập nhật tình trạng (condition) cho các lot
     * Được gọi từ modal "Kiểm thực tế và lưu kho"
     */
    public static function update_lot_conditions()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;

        $lots_json = isset($_POST['lots']) ? wp_unslash($_POST['lots']) : '';
        $lots = json_decode($lots_json, true);

        if (empty($lots) || !is_array($lots)) {
            wp_send_json_error(['message' => 'Không có dữ liệu lot để cập nhật']);
        }

        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;
        $updated_count = 0;
        $errors = [];

        foreach ($lots as $lot_data) {
            $lot_id = intval($lot_data['lot_id'] ?? 0);
            $condition = intval($lot_data['condition'] ?? 0);

            if ($lot_id <= 0) {
                continue;
            }

            // Validate condition (0 = Mới, 3 = Hàng lỗi)
            if (!in_array($condition, [0, 3])) {
                $condition = 0;
            }

            $result = $wpdb->update(
                $lots_table,
                [
                    'global_product_lot_condition' => $condition,
                    'updated_at' => current_time('mysql')
                ],
                ['global_product_lot_id' => $lot_id]
            );

            if ($result !== false) {
                $updated_count++;
            } else {
                $errors[] = "Lỗi cập nhật lot ID: {$lot_id}";
            }
        }

        if (!empty($errors)) {
            wp_send_json_error([
                'message' => 'Có lỗi khi cập nhật: ' . implode(', ', $errors),
                'updated_count' => $updated_count
            ]);
        }

        wp_send_json_success([
            'message' => "Đã cập nhật {$updated_count} mã định danh",
            'updated_count' => $updated_count
        ]);
    }

    /**
     * Lấy dữ liệu báo cáo chuyển kho
     */
    public static function get_report_data()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $period = sanitize_text_field($_POST['period'] ?? 'month');

        // Calculate date range
        $date_condition = '';
        switch ($period) {
            case 'today':
                $date_condition = "AND DATE(l.created_at) = CURDATE()";
                break;
            case 'week':
                $date_condition = "AND l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $date_condition = "AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'quarter':
                $date_condition = "AND l.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
                break;
            case 'year':
                $date_condition = "AND l.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
                break;
            default:
                $date_condition = '';
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        // Check if transfer_ledger table exists in current blog
        $transfer_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$transfer_table}'") === $transfer_table;

        // ============= EXPORT STATS =============
        $export_stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_count,
                SUM(CASE WHEN l.local_ledger_approver_status = %d THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN l.local_ledger_approver_status != %d THEN 1 ELSE 0 END) as pending_count
            FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ", TGS_APPROVER_STATUS_APPROVED, TGS_APPROVER_STATUS_APPROVED, TGS_LEDGER_TYPE_TRANSFER_EXPORT));

        // Products exported count
        $products_exported = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(li.quantity), 0)
            FROM {$ledger_item_table} li
            JOIN {$ledger_table} l ON li.local_ledger_id = l.local_ledger_id
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ", TGS_LEDGER_TYPE_TRANSFER_EXPORT));

        // ============= IMPORT STATS =============
        $import_stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_count,
                SUM(CASE WHEN l.local_ledger_approver_status = %d THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN l.local_ledger_approver_status != %d THEN 1 ELSE 0 END) as pending_count
            FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ", TGS_APPROVER_STATUS_APPROVED, TGS_APPROVER_STATUS_APPROVED, TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        // Products imported count
        $products_imported = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(li.quantity), 0)
            FROM {$ledger_item_table} li
            JOIN {$ledger_table} l ON li.local_ledger_id = l.local_ledger_id
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ", TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        // ============= PENDING RECEIVE COUNT =============
        $pending_receive = 0;
        if (is_multisite()) {
            $sites = get_sites(['number' => 1000]);
            foreach ($sites as $site) {
                if ($site->blog_id == $current_blog_id) continue;

                switch_to_blog($site->blog_id);
                $other_transfer_table = $wpdb->prefix . 'transfer_ledger';
                $other_ledger_table = $wpdb->prefix . 'local_ledger';

                // Check if transfer_ledger table exists in this blog
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$other_transfer_table}'") === $other_transfer_table;

                if ($table_exists) {
                    $count = $wpdb->get_var($wpdb->prepare("
                        SELECT COUNT(*)
                        FROM {$other_transfer_table} t
                        JOIN {$other_ledger_table} l ON t.source_ledger_id = l.local_ledger_id
                        WHERE t.destination_blog_id = %d
                        AND (t.destination_ledger_id IS NULL OR t.destination_ledger_id = 0)
                        AND l.local_ledger_approver_status = %d
                    ", $current_blog_id, TGS_APPROVER_STATUS_APPROVED));

                    $pending_receive += intval($count);
                }
                restore_current_blog();
            }
        }

        // ============= EXPORTED TO SHOPS =============
        $exported_to = [];
        if ($transfer_table_exists) {
            $export_transfers = $wpdb->get_results("
                SELECT t.destination_blog_id,
                       COUNT(*) as tickets_count,
                       SUM(CASE WHEN l.local_ledger_approver_status = " . TGS_APPROVER_STATUS_APPROVED . " THEN 1 ELSE 0 END) as approved_count,
                       SUM(CASE WHEN l.local_ledger_approver_status != " . TGS_APPROVER_STATUS_APPROVED . " THEN 1 ELSE 0 END) as pending_count
                FROM {$transfer_table} t
                JOIN {$ledger_table} l ON t.source_ledger_id = l.local_ledger_id
                WHERE t.source_blog_id = {$current_blog_id}
                GROUP BY t.destination_blog_id
            ");

            foreach ($export_transfers as $et) {
                $shop_name = '';
                if (is_multisite()) {
                    $details = get_blog_details($et->destination_blog_id);
                    $shop_name = $details ? $details->blogname : '';
                }

                // Get products count
                $products_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COALESCE(SUM(li.quantity), 0)
                    FROM {$ledger_item_table} li
                    JOIN {$ledger_table} l ON li.local_ledger_id = l.local_ledger_id
                    JOIN {$transfer_table} t ON l.local_ledger_id = t.source_ledger_id
                    WHERE t.destination_blog_id = %d AND t.source_blog_id = %d
                ", $et->destination_blog_id, $current_blog_id));

                $exported_to[] = [
                    'blog_id' => $et->destination_blog_id,
                    'shop_name' => $shop_name,
                    'tickets_count' => $et->tickets_count,
                    'products_count' => intval($products_count),
                    'approved_count' => $et->approved_count,
                    'pending_count' => $et->pending_count
                ];
            }
        }

        // ============= IMPORTED FROM SHOPS =============
        $imported_from = [];
        if (is_multisite()) {
            $sites = get_sites(['number' => 1000]);
            foreach ($sites as $site) {
                if ($site->blog_id == $current_blog_id) continue;

                switch_to_blog($site->blog_id);
                $other_transfer_table = $wpdb->prefix . 'transfer_ledger';

                // Check if transfer_ledger table exists in this blog
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$other_transfer_table}'") === $other_transfer_table;

                if ($table_exists) {
                    $imports = $wpdb->get_row($wpdb->prepare("
                        SELECT COUNT(*) as tickets_count,
                               SUM(CASE WHEN transfer_status = %d THEN 1 ELSE 0 END) as approved_count,
                               SUM(CASE WHEN transfer_status != %d THEN 1 ELSE 0 END) as pending_count
                        FROM {$other_transfer_table}
                        WHERE destination_blog_id = %d
                        AND destination_ledger_id IS NOT NULL
                    ", TGS_TRANSFER_STATUS_ACCEPTED, TGS_TRANSFER_STATUS_ACCEPTED, $current_blog_id));

                    if ($imports && $imports->tickets_count > 0) {
                        $shop_name = get_bloginfo('name');

                        $imported_from[] = [
                            'blog_id' => $site->blog_id,
                            'shop_name' => $shop_name,
                            'tickets_count' => $imports->tickets_count,
                            'products_count' => 0, // Will calculate later
                            'approved_count' => $imports->approved_count,
                            'pending_count' => $imports->pending_count
                        ];
                    }
                }

                restore_current_blog();
            }
        }

        // ============= TREND DATA =============
        $trend = [];
        $days = 30;
        if ($period === 'week') $days = 7;
        elseif ($period === 'quarter') $days = 90;
        elseif ($period === 'year') $days = 365;
        elseif ($period === 'today') $days = 1;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $date_label = date('d/m', strtotime("-{$i} days"));

            $exports_day = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$ledger_table}
                WHERE local_ledger_type = %d
                AND DATE(created_at) = %s
                AND (is_deleted IS NULL OR is_deleted = 0)
            ", TGS_LEDGER_TYPE_TRANSFER_EXPORT, $date));

            $imports_day = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$ledger_table}
                WHERE local_ledger_type = %d
                AND DATE(created_at) = %s
                AND (is_deleted IS NULL OR is_deleted = 0)
            ", TGS_LEDGER_TYPE_TRANSFER_IMPORT, $date));

            $trend[] = [
                'date' => $date_label,
                'exports' => intval($exports_day),
                'imports' => intval($imports_day)
            ];
        }

        // Group by week if more than 30 days
        if ($days > 30 && count($trend) > 0) {
            $weekly_trend = [];
            $week_data = ['exports' => 0, 'imports' => 0, 'date' => ''];
            $week_count = 0;

            foreach ($trend as $i => $day) {
                $week_data['exports'] += $day['exports'];
                $week_data['imports'] += $day['imports'];
                $week_count++;

                if ($week_count === 7 || $i === count($trend) - 1) {
                    $week_data['date'] = $day['date'];
                    $weekly_trend[] = $week_data;
                    $week_data = ['exports' => 0, 'imports' => 0, 'date' => ''];
                    $week_count = 0;
                }
            }
            $trend = $weekly_trend;
        }

        // ============= RECENT TRANSFERS =============
        $recent = [];

        // Recent exports - only if transfer_table exists
        if ($transfer_table_exists) {
            $recent_exports = $wpdb->get_results($wpdb->prepare("
                SELECT l.local_ledger_id as ledger_id, l.local_ledger_code as ledger_code,
                       l.local_ledger_approver_status, l.created_at,
                       t.destination_blog_id as related_blog_id
                FROM {$ledger_table} l
                LEFT JOIN {$transfer_table} t ON l.local_ledger_id = t.source_ledger_id
                WHERE l.local_ledger_type = %d
                AND (l.is_deleted IS NULL OR l.is_deleted = 0)
                ORDER BY l.created_at DESC
                LIMIT 5
            ", TGS_LEDGER_TYPE_TRANSFER_EXPORT));
        } else {
            // Query without transfer_table join
            $recent_exports = $wpdb->get_results($wpdb->prepare("
                SELECT l.local_ledger_id as ledger_id, l.local_ledger_code as ledger_code,
                       l.local_ledger_approver_status, l.created_at,
                       NULL as related_blog_id
                FROM {$ledger_table} l
                WHERE l.local_ledger_type = %d
                AND (l.is_deleted IS NULL OR l.is_deleted = 0)
                ORDER BY l.created_at DESC
                LIMIT 5
            ", TGS_LEDGER_TYPE_TRANSFER_EXPORT));
        }

        foreach ($recent_exports as $re) {
            $shop_name = '';
            if ($re->related_blog_id && is_multisite()) {
                $details = get_blog_details($re->related_blog_id);
                $shop_name = $details ? $details->blogname : '';
            }

            $products_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$ledger_item_table} WHERE local_ledger_id = %d
            ", $re->ledger_id));

            $recent[] = [
                'type' => 'export',
                'ledger_id' => $re->ledger_id,
                'ledger_code' => $re->ledger_code,
                'created_at' => $re->created_at,
                'status' => $re->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED ? 'approved' : 'pending',
                'related_blog_id' => $re->related_blog_id,
                'related_shop_name' => $shop_name,
                'products_count' => intval($products_count)
            ];
        }

        // Recent imports
        $recent_imports = $wpdb->get_results($wpdb->prepare("
            SELECT l.local_ledger_id as ledger_id, l.local_ledger_code as ledger_code,
                   l.local_ledger_approver_status, l.created_at, l.local_ledger_note
            FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            ORDER BY l.created_at DESC
            LIMIT 5
        ", TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        foreach ($recent_imports as $ri) {
            $products_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$ledger_item_table} WHERE local_ledger_id = %d
            ", $ri->ledger_id));

            // Try to extract source shop from note (format: [Từ phiếu xuất: XXX])
            $recent[] = [
                'type' => 'import',
                'ledger_id' => $ri->ledger_id,
                'ledger_code' => $ri->ledger_code,
                'created_at' => $ri->created_at,
                'status' => $ri->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED ? 'approved' : 'pending',
                'related_blog_id' => 0,
                'related_shop_name' => 'Nơi xuất',
                'products_count' => intval($products_count)
            ];
        }

        // Sort by created_at
        usort($recent, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        $recent = array_slice($recent, 0, 10);

        // ============= RESPONSE =============
        wp_send_json_success([
            'summary' => [
                'export_count' => intval($export_stats->total_count ?? 0),
                'export_approved' => intval($export_stats->approved_count ?? 0),
                'export_pending' => intval($export_stats->pending_count ?? 0),
                'import_count' => intval($import_stats->total_count ?? 0),
                'import_approved' => intval($import_stats->approved_count ?? 0),
                'import_pending' => intval($import_stats->pending_count ?? 0),
                'pending_receive' => $pending_receive,
                'products_exported' => intval($products_exported),
                'products_imported' => intval($products_imported)
            ],
            'exported_to' => $exported_to,
            'imported_from' => $imported_from,
            'trend' => $trend,
            'recent' => $recent
        ]);
    }

    // =========================================================================
    // HELPER FUNCTIONS - Sản phẩm global dùng chung
    // =========================================================================

    /**
     * Giữ tên hàm cũ để tương thích code gọi nội bộ.
     * Không còn tạo/copy sản phẩm local; chỉ trả về ID global nếu tìm thấy.
     *
     * @param object $source_product Thông tin sản phẩm từ nơi xuất
     * @param int $source_blog_id Blog ID của nơi xuất
     * @return int|false ID sản phẩm global hoặc false nếu lỗi
     */
    private static function sync_product_from_source($source_product, $source_blog_id)
    {
        $product = TGS_Transfer_Global_Products::get_product_for_item($source_product, $source_blog_id);
        return $product ? (int) ($product['global_product_name_id'] ?? 0) : false;
    }



    /**
     * Từ chối phiếu xuất sang shop con
     */
    public static function reject_export()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'ID phiếu không hợp lệ']);
        }

        if (empty($reason)) {
            wp_send_json_error(['message' => 'Vui lòng nhập lý do từ chối']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

        // Lấy thông tin phiếu con xuất (type 2 = SALE)
        $child_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_SALE));

        if (!$child_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu xuất kho']);
        }

        // Tìm phiếu cha (TRANSFER_EXPORT type 12 hoặc INTERNAL_RETURN type 14) qua local_ledger_parent_id
        $parent_ledger_id = intval($child_ledger->local_ledger_parent_id);
        if (!$parent_ledger_id) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu cha']);
        }

        // Check cả 2 loại phiếu cha: xuất nội bộ (12) và trả nội bộ (14)
        $parent_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type IN (%d, %d)
        ", $parent_ledger_id, TGS_LEDGER_TYPE_TRANSFER_EXPORT, TGS_LEDGER_TYPE_INTERNAL_RETURN));

        if (!$parent_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu xuất/trả nội bộ']);
        }

        if ($child_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_REJECTED) {
            wp_send_json_error(['message' => 'Phiếu đã bị từ chối trước đó']);
        }

        // Lấy các item từ phiếu con xuất (chính là ledger_id được gửi lên)
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*
            FROM {$ledger_item_table} li
            WHERE li.local_ledger_id = %d
            AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
        ", $ledger_id));
        $items = TGS_Transfer_Global_Products::enrich_ledger_items($items, $current_blog_id);

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    // Cập nhật lot về trạng thái ACTIVE (nhập kho lại shop mẹ)
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];

                    foreach ($lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main_and_sku($lot_id, $item->local_product_name_id);

                        // Khi từ chối xuất: lot về lại shop mẹ
                        // to_blog_id giữ nguyên = shop mẹ (current_blog_id)
                        // local_product_lot_is_active = 1 (nhập kho lại)
                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_ACTIVE, // 1 - nhập kho lại
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);
                    }
                } else {
                    // Không ghi tồn vào catalog local. Tồn non-tracking được tính từ ledger/API theo SKU.
                }
            }

            // Cập nhật trạng thái phiếu con xuất
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật trạng thái phiếu cha (TRANSFER_EXPORT)
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $parent_ledger_id]);

            // Cập nhật transfer_ledger (dùng parent_ledger_id vì source_ledger_id = phiếu cha)
            $transfer_table = $wpdb->prefix . 'transfer_ledger';
            $wpdb->update($transfer_table, [
                'transfer_status' => TGS_TRANSFER_STATUS_REJECTED,
                'updated_at' => current_time('mysql')
            ], ['source_ledger_id' => $parent_ledger_id]);

            $wpdb->query('COMMIT');

            // Thêm log từ chối phiếu xuất transfer
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'reject', [
                'reason' => $reason,
                'items_count' => count($items)
            ], $reason);

            wp_send_json_success([
                'message' => 'Từ chối phiếu xuất nội bộ thành công! Hàng đã nhập kho lại.',
                'reason' => $reason
            ]);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Từ chối phiếu nhận từ shop mẹ (shop con từ chối nhận)
     */
    public static function reject_import()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'ID phiếu không hợp lệ']);
        }

        if (empty($reason)) {
            wp_send_json_error(['message' => 'Vui lòng nhập lý do từ chối']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

        // Lấy thông tin phiếu con nhập (type 1 = PURCHASE)
        $child_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_PURCHASE));

        if (!$child_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu nhập kho']);
        }

        // Tìm phiếu cha (TRANSFER_IMPORT type 13 hoặc INTERNAL_RETURN_RECEIVE type 15) qua local_ledger_parent_id
        $parent_ledger_id = intval($child_ledger->local_ledger_parent_id);
        if (!$parent_ledger_id) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu cha']);
        }

        // Check cả 2 loại phiếu cha: nhận nội bộ (13) và nhận trả nội bộ (15)
        $parent_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type IN (%d, %d)
        ", $parent_ledger_id, TGS_LEDGER_TYPE_TRANSFER_IMPORT, TGS_LEDGER_TYPE_INTERNAL_RETURN_RECEIVE));

        if (!$parent_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu nhận/nhận trả nội bộ']);
        }

        if ($child_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_REJECTED) {
            wp_send_json_error(['message' => 'Phiếu đã bị từ chối trước đó']);
        }

        // Lấy các item từ phiếu con nhập (chính là ledger_id được gửi lên)
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*
            FROM {$ledger_item_table} li
            WHERE li.local_ledger_id = %d
            AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
        ", $ledger_id));
        $items = TGS_Transfer_Global_Products::enrich_ledger_items($items, $current_blog_id);

        // Tìm transfer_ledger để biết phiếu xuất gốc (dùng parent_ledger_id)
        $local_transfer_table = $wpdb->prefix . 'transfer_ledger';
        $local_transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$local_transfer_table}
            WHERE destination_ledger_id = %d
        ", $parent_ledger_id));

        $wpdb->query('START TRANSACTION');

        try {
            // ===== XỬ LÝ CÁC LOT ĐÃ ĐƯỢC CHỌN ĐỂ NHẬP (trong phiếu nhập hiện tại) =====
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    // Cập nhật các lot đã chọn về status = 5 (chờ trả mẹ)
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];

                    foreach ($lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main_and_sku($lot_id, $item->local_product_name_id);

                        // Khi shop con từ chối nhận:
                        // source_blog_id: giữ nguyên (shop mẹ)
                        // to_blog_id: giữ nguyên (shop con)
                        // local_product_lot_is_active = 5 (chờ trả mẹ)
                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING_RETURN, // Chờ trả mẹ
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);
                    }
                } else {
                    // Không cộng/trừ tồn kho vì chưa nhập
                    // Không cần xử lý gì
                }
            }

            // ===== XỬ LÝ CÁC LOT CHƯA ĐƯỢC CHỌN ĐỂ NHẬP (nếu là nhập 1 phần) =====
            if ($local_transfer) {
                $source_blog_id = intval($local_transfer->source_blog_id);
                $source_ledger_id = intval($local_transfer->source_ledger_id);

                // Lấy TOÀN BỘ lot từ phiếu xuất gốc (shop mẹ) qua local_ledger_item_id
                switch_to_blog($source_blog_id);
                $source_ledger_table = $wpdb->prefix . 'local_ledger';
                $source_ledger_item_table = $wpdb->prefix . 'local_ledger_item';

                // Lấy local_ledger_item_id từ phiếu cha
                $source_ledger_data = $wpdb->get_row($wpdb->prepare("
                    SELECT local_ledger_item_id FROM {$source_ledger_table}
                    WHERE local_ledger_id = %d
                ", $source_ledger_id));

                $source_items = [];
                if ($source_ledger_data && !empty($source_ledger_data->local_ledger_item_id)) {
                    $source_item_ids = json_decode($source_ledger_data->local_ledger_item_id, true) ?: [];
                    if (!empty($source_item_ids)) {
                        $source_item_ids_str = implode(',', array_map('intval', $source_item_ids));
                        $source_items = $wpdb->get_results("
                            SELECT list_product_lots
                            FROM {$source_ledger_item_table}
                            WHERE local_ledger_item_id IN ({$source_item_ids_str})
                              AND (is_deleted = 0 OR is_deleted IS NULL)
                        ", ARRAY_A);
                    }
                }

                restore_current_blog();

                // Thu thập tất cả lot IDs từ phiếu xuất gốc
                $all_source_lot_ids = [];
                foreach ($source_items as $source_item) {
                    $lots = json_decode($source_item['list_product_lots'], true) ?: [];
                    $all_source_lot_ids = array_merge($all_source_lot_ids, array_map('intval', $lots));
                }
                $all_source_lot_ids = array_unique(array_filter($all_source_lot_ids));

                // Thu thập các lot đã chọn trong phiếu nhập
                $selected_lot_ids = [];
                foreach ($items as $item) {
                    $lots = json_decode($item->list_product_lots, true) ?: [];
                    $selected_lot_ids = array_merge($selected_lot_ids, array_map('intval', $lots));
                }
                $selected_lot_ids = array_unique(array_filter($selected_lot_ids));

                // Tìm các lot chưa được chọn
                $not_selected_lot_ids = array_diff($all_source_lot_ids, $selected_lot_ids);

                // Cập nhật status = 5 cho các lot chưa được chọn
                if (!empty($not_selected_lot_ids)) {
                    foreach ($not_selected_lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main_and_sku($lot_id);

                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING_RETURN, // Chờ trả mẹ
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);
                    }
                }
            }

            // Cập nhật trạng thái phiếu con nhập
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật trạng thái phiếu cha (TRANSFER_IMPORT)
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $parent_ledger_id]);

            // Cập nhật transfer_ledger ở shop con
            if ($local_transfer) {
                $wpdb->update($local_transfer_table, [
                    'transfer_status' => TGS_TRANSFER_STATUS_REJECTED,
                    'updated_at' => current_time('mysql')
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);
            }

            // Cập nhật transfer_ledger ở shop mẹ
            if ($local_transfer) {
                $source_blog_id = intval($local_transfer->source_blog_id);

                switch_to_blog($source_blog_id);

                $source_transfer_table = $wpdb->prefix . 'transfer_ledger';
                $wpdb->update($source_transfer_table, [
                    'transfer_status' => TGS_TRANSFER_STATUS_REJECTED,
                    'updated_at' => current_time('mysql')
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);

                restore_current_blog();
            }

            $wpdb->query('COMMIT');

            // Thêm log từ chối phiếu nhập transfer
            $source_shop_name = $local_transfer ? get_blog_option(intval($local_transfer->source_blog_id), 'blogname') : '';
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'reject', [
                'source_blog_id' => $local_transfer ? intval($local_transfer->source_blog_id) : 0,
                'source_shop_name' => $source_shop_name,
                'reason' => $reason,
                'items_count' => count($items)
            ], $reason);

            wp_send_json_success([
                'message' => 'Từ chối phiếu nhận thành công! Hàng sẽ chờ trả về nơi xuất.',
                'reason' => $reason
            ]);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // ==================== TRẢ HÀNG NỘI BỘ (INTERNAL RETURN) ====================

    /**
     * Lấy danh sách phiếu trả hàng nội bộ đang chờ nhận
     * Tương tự get_pending_imports nhưng với transfer_type = TGS_TRANSFER_TYPE_RETURN
     */
    /**
     * Lấy danh sách phiếu chờ nhận trả (trả hàng nội bộ)
     * Gọi đến do_get_pending_transfers_internal với transfer_type = RETURN
     */
    public static function get_pending_returns()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        // Gọi hàm dùng chung với filter transfer_type = RETURN
        self::do_get_pending_transfers_internal([
            'transfer_type' => TGS_TRANSFER_TYPE_RETURN // 2 = return
        ]);
    }

    /**
     * Tạo phiếu trả hàng nội bộ (TNB)
     * Shop đã mua (shop con) trả lại hàng cho shop đã bán (shop mẹ)
     *
     * Luồng tương tự create_export nhưng:
     * - Có phiếu cha là MNB
     * - transfer_type = TGS_TRANSFER_TYPE_RETURN
     * - source = shop trả, destination = nơi nhận trả
     */
    public static function create_return()
    {
        // Xử lý bởi ticket-create-base.php và class-tgs-ajax-ticket-base.php
        // Chỉ cần đăng ký để khi cần customize có thể override
        // Hiện tại sẽ được xử lý tự động bởi ticket_save_internal_return trong plugin shop

        check_ajax_referer('tgs_shop_nonce', 'nonce');

        // Phiếu trả sẽ được xử lý bởi ticket-create-base với ticket_type = internal_return
        // Logic sẽ tự động:
        // 1. Tạo phiếu TNB (ledger_type = TGS_LEDGER_TYPE_INTERNAL_RETURN)
        // 2. Tạo phiếu xuất tự động (con)
        // 3. Tạo record trong transfer_ledger với transfer_type = TGS_TRANSFER_TYPE_RETURN

        wp_send_json_error(['message' => 'Hàm này được xử lý bởi ticket-create-base']);
    }

    /**
     * Tạo phiếu nhận trả nội bộ (NTN)
     * Shop đã bán (shop mẹ) nhận lại hàng trả từ shop đã mua (shop con)
     *
     * Gọi đến do_create_import_internal với config cho RETURN flow
     */
    public static function create_return_receive()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        // Gọi hàm dùng chung với config cho phiếu nhận trả nội bộ
        self::do_create_import_internal([
            'transfer_type' => TGS_TRANSFER_TYPE_RETURN,           // 2
            'parent_ledger_type' => TGS_LEDGER_TYPE_INTERNAL_RETURN_RECEIVE, // 15
            'source_parent_type' => TGS_LEDGER_TYPE_INTERNAL_RETURN, // 14
            'parent_code_prefix' => 'NTN',                         // Nhận Trả Nội Bộ
            'child_code_prefix' => 'ANT',                          // Auto Nhận Trả
            'parent_title_template' => 'Thông tin phiếu nhận trả nội bộ %s', // %s = code
            'child_title_template' => 'Nhập tự động từ %s', // %s = parent code
            'log_action' => 'transfer_return_receive_created',
            'redirect_view' => 'ticket-internal-return-receive-detail',
            'success_message' => 'Tạo phiếu nhận trả nội bộ thành công',
            'ticket_log_type' => 'return_receive',
            'doc_tracker_ticket_type' => 'internal_return_receive',
            'labels' => [
                'transfer_not_found' => 'Không tìm thấy phiếu chuyển trả',
                'already_created' => 'Phiếu này đã được tạo phiếu nhận trả trước đó',
                'source_not_found' => 'Không tìm thấy phiếu trả nguồn',
                'auto_export_not_approved' => 'Phiếu xuất tự động chưa được shop trả duyệt',
                'source_not_approved' => 'Phiếu trả chưa được shop trả duyệt',
                'no_items' => 'Không có sản phẩm trong phiếu trả',
                'select_items' => 'Vui lòng chọn ít nhất 1 sản phẩm để nhận trả',
                'parent_error' => 'Lỗi tạo phiếu nhận trả nội bộ (phiếu cha)',
                'note_suffix_partial' => 'Từ phiếu trả',
                'note_suffix_full' => 'Từ phiếu trả',
                'ticket_log_desc' => 'Tạo phiếu nhận trả nội bộ từ shop'
            ]
        ]);
    }

    /**
     * Duyệt phiếu trả hàng nội bộ
     *
     * ⚠️ DEPRECATED: Hàm này KHÔNG được JS front-end gọi nữa.
     * JS (ticket-detail-base.js) đã route phiếu con xuất (type=2) có cha type=14
     * sang tgs_transfer_approve_export → approve_export(), hàm đã xử lý đầy đủ:
     *   - Lot tracking (update to_blog_id, status PENDING)
     *   - Non-tracking stock
     *   - batch_distribution (transferred_out)
     *   - Sync sản phẩm sang shop đích
     *
     * Hàm approve_return() chỉ cập nhật trạng thái, THIẾU xử lý lots/stock/distribution.
     * Giữ lại để backward compatible nhưng KHÔNG NÊN GỌI.
     *
     * @deprecated Use approve_export() instead (via tgs_transfer_approve_export action)
     */
    public static function approve_return()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        // Lấy phiếu xuất tự động (con của phiếu trả)
        $child_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_SALE));

        if (!$child_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu xuất kho']);
        }

        if ($child_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED) {
            wp_send_json_error(['message' => 'Phiếu đã được duyệt trước đó']);
        }

        // Tìm phiếu cha (TNB)
        $parent_id = intval($child_ledger->local_ledger_parent_id);
        if (!$parent_id) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu cha']);
        }

        $parent_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $parent_id, TGS_LEDGER_TYPE_INTERNAL_RETURN));

        if (!$parent_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu trả nội bộ']);
        }

        // Lấy thông tin transfer
        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$transfer_table}
            WHERE source_ledger_id = %d
            AND source_blog_id = %d
            AND transfer_type = %d
        ", $parent_id, $current_blog_id, TGS_TRANSFER_TYPE_RETURN));

        if (!$transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy thông tin transfer']);
        }

        $wpdb->query('START TRANSACTION');

        try {
            // Cập nhật trạng thái phiếu xuất tự động
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_APPROVED,
                'local_ledger_status' => TGS_LEDGER_STATUS_APPROVED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật transfer_ledger - sẵn sàng cho shop đích nhận
            $wpdb->update($transfer_table, [
                'transfer_status' => TGS_TRANSFER_STATUS_PENDING,
                'transfer_note' => ($transfer->transfer_note ?? '') . "\n[Duyệt trả kho] " . date('d/m/Y H:i') . ": " . $note
            ], ['transfer_ledger_id' => $transfer->transfer_ledger_id]);

            $wpdb->query('COMMIT');

            // Log
            $dest_shop_name = get_blog_option($transfer->destination_blog_id, 'blogname');
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'approve', [
                'destination_blog_id' => $transfer->destination_blog_id,
                'destination_shop_name' => $dest_shop_name,
                'note' => $note,
                'transfer_type' => 'return'
            ]);

            wp_send_json_success([
                'message' => 'Duyệt phiếu trả nội bộ thành công! Shop đích có thể tạo phiếu nhận trả.'
            ]);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
