<?php
/**
 * AJAX của chuyển kho GLOBAL (TGS_Global_Transfer — bảng wp_global_transfer / _item, một phiếu hai đầu).
 *   tgs_gt_options    website / chi nhánh có thể chọn, phân kho của một website
 *   tgs_gt_products   tìm hàng (mã / tên / mã vạch) kèm tồn tại website đang đứng
 *   tgs_gt_save       tạo / sửa phiếu (tuỳ chọn duyệt xuất luôn)
 *   tgs_gt_get        chi tiết phiếu
 *   tgs_gt_list       danh sách theo chiều (out | in | all), trạng thái, ngày, tìm
 *   tgs_gt_action     approve_export · unapprove_export · approve_import · unapprove_import · reject · delete
 *   tgs_gt_htlist     màn "Phiếu chuyển kho": trạng thái (chờ xác nhận / đã xác nhận / tất cả), từ – đến, chi nhánh, tìm nhanh
 *   tgs_gt_report     "Báo cáo chuyển kho nội bộ": chi tiết (mode=detail) / nhóm theo mặt hàng (mode=item)
 * Quyền: bên ĐI sửa / xoá / duyệt xuất, bên ĐẾN duyệt nhận / từ chối. Quản trị của website đang đứng (kế toán chi nhánh) và
 * quản trị mạng lập / thao tác được phiếu cho MỌI chi nhánh — không phải vào từng website.
 * Lý do chuyển kho: danh mục lý do của phần mềm kế toán (/api/v1/posting/reasons, nhớ 6 giờ), không gọi được thì dùng danh
 * mục lý do xuất có sẵn.
 */
if (!defined('ABSPATH')) {
    exit;
}

class TGS_GT_Ajax
{
    const NONCE = 'tgs_gt_nonce';

    public static function init()
    {
        foreach (['options', 'products', 'save', 'get', 'list', 'action', 'htlist', 'report', 'catalog', 'stock'] as $a) {
            add_action('wp_ajax_tgs_gt_' . $a, [__CLASS__, $a]);
        }
    }

    private static function guard()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!is_user_logged_in() || !class_exists('TGS_Global_Transfer')) {
            wp_send_json_error(['message' => 'Không có quyền hoặc thiếu Quản lý Shop.'], 403);
        }
        if (!TGS_Global_Transfer::ready()) {
            wp_send_json_error(['message' => 'Chưa có bảng chuyển kho global — chạy nâng cấp database Quản lý Shop (deactivate/activate).'], 500);
        }
    }

    private static function reply($res)
    {
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        wp_send_json_success($res);
    }

    private static function post($k, $default = '')
    {
        return isset($_POST[$k]) ? wp_unslash($_POST[$k]) : $default;
    }

    /** Được thao tác phiếu của chi nhánh khác (không phải đầu đi / đầu đến là website đang đứng). */
    private static function can_all()
    {
        return is_super_admin() || current_user_can('manage_options');
    }

    const REASON_CACHE = 'tgs_gt_erp_reasons';

    /**
     * Lý do chuyển kho: [{code, name, isDefault}] — lý do xuất (kind out) của phần mềm kế toán, lý do mặc định của chuyển
     * kho (flags.default = transfer) đứng đầu. $refresh: kéo lại, bỏ bản nhớ.
     */
    public static function reasons($refresh = false)
    {
        $list = $refresh ? false : get_site_transient(self::REASON_CACHE);
        if (is_array($list) && $list) {
            return $list;
        }
        $list = [];
        if (class_exists('TGS_Acc_Api_Client') && class_exists('TGS_Acc_Config') && TGS_Acc_Config::url() !== '' && TGS_Acc_Config::key() !== '') {
            $res = (new TGS_Acc_Api_Client())->get('/api/v1/posting/reasons');
            if (!is_wp_error($res)) {
                foreach ((array) ($res['data'] ?? []) as $r) {
                    $r = (array) $r;
                    if (($r['kind'] ?? '') !== 'out' || empty($r['isActive'])) {
                        continue;
                    }
                    $flags = (array) ($r['flags'] ?? []);
                    $list[] = ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'isDefault' => ($flags['default'] ?? '') === 'transfer'];
                }
            }
        }
        if (!$list) {
            $f = defined('TGS_SHOP_PLUGIN_DIR') ? TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/create/configs/ticket_import_reasons.php' : '';
            if ($f && file_exists($f)) {
                require_once $f;
            }
            if (function_exists('tgs_ticket_get_export_reasons')) {
                foreach (tgs_ticket_get_export_reasons() as $r) {
                    $list[] = ['code' => (string) $r['code'], 'name' => trim(($r['account'] ? $r['account'] . '-' : '') . $r['label']), 'isDefault' => $r['code'] === 'XCKho'];
                }
            }
            if ($list) {
                set_site_transient(self::REASON_CACHE, $list, 10 * MINUTE_IN_SECONDS);
            }
            return self::sort_reasons($list);
        }
        $list = self::sort_reasons($list);
        set_site_transient(self::REASON_CACHE, $list, 6 * HOUR_IN_SECONDS);
        return $list;
    }

    private static function sort_reasons(array $list)
    {
        usort($list, static function ($a, $b) { return ($b['isDefault'] <=> $a['isDefault']) ?: strcmp($a['code'], $b['code']); });
        return $list;
    }

    public static function options()
    {
        self::guard();
        $blog = (int) self::post('blog_id', get_current_blog_id());
        wp_send_json_success([
            'currentBlogId' => get_current_blog_id(),
            'canAll' => self::can_all(),
            'sites' => TGS_Global_Transfer::sites(),
            'zones' => TGS_Global_Transfer::zones_of_blog($blog),
            'branchCode' => TGS_Global_Transfer::branch_of_blog($blog),
            'reasons' => self::reasons(!empty($_POST['refresh_reasons'])),
        ]);
    }

    public static function products()
    {
        self::guard();
        $q = trim((string) self::post('search'));
        if (mb_strlen($q) < 2 || !class_exists('TGS_Global_Product_Source')) {
            wp_send_json_success(['items' => []]);
        }
        $res = TGS_Global_Product_Source::query_products(['search' => $q, 'page' => 1, 'per_page' => 30, 'status_filter' => 'active', 'blog_id' => get_current_blog_id(), 'with_stock' => true]);
        $items = [];
        foreach ((array) ($res['items'] ?? []) as $p) {
            $items[] = [
                'product_id' => (int) $p['global_product_name_id'], 'sku' => (string) $p['global_product_sku'], 'name' => (string) $p['global_product_name'],
                'unit' => (string) ($p['global_product_unit'] ?? ''), 'barcode' => (string) ($p['global_product_barcode_main'] ?? ''),
                'tax' => (float) ($p['global_product_tax'] ?? 0), 'price' => (float) ($p['global_product_price'] ?? 0),
                'stock' => (float) ($p['actual_stock'] ?? 0),
            ];
        }
        wp_send_json_success(['items' => $items]);
    }

    /**
     * Danh mục mặt hàng gọn để form tìm NGAY TRÊN TRÌNH DUYỆT (khoảng 20 nghìn mã — gõ tới đâu gợi ý tới đó, không gọi máy
     * chủ mỗi phím): {ver, rows: [[id, mã, tên, ĐVT, mã vạch, giá, thuế]]}. Gửi kèm ver đang giữ: chưa đổi thì {same: true}.
     */
    public static function catalog()
    {
        self::guard();
        global $wpdb;
        $P = $wpdb->base_prefix . 'global_product_name';
        $ver = (string) $wpdb->get_var("SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(updated_at), '')) FROM {$P} WHERE is_deleted = 0 OR is_deleted IS NULL");
        if ((string) self::post('ver') === $ver) {
            wp_send_json_success(['same' => true, 'ver' => $ver]);
        }
        $rows = $wpdb->get_results(
            "SELECT global_product_name_id, global_product_sku, global_product_name, global_product_unit, global_product_barcode_main, global_product_price, global_product_tax
               FROM {$P} WHERE (is_deleted = 0 OR is_deleted IS NULL) AND global_product_sku <> ''",
            ARRAY_N
        ) ?: [];
        foreach ($rows as &$r) {
            $r = [(int) $r[0], (string) $r[1], (string) $r[2], (string) $r[3], (string) $r[4], (float) $r[5], (float) $r[6]];
        }
        unset($r);
        wp_send_json_success(['ver' => $ver, 'rows' => $rows]);
    }

    /** Tồn kho thực tế của một số mã hàng tại một website (chi nhánh xuất): {sku: SL}. */
    public static function stock()
    {
        self::guard();
        $blog = (int) self::post('blog_id', get_current_blog_id()) ?: get_current_blog_id();
        $skus = array_values(array_filter(array_map('strval', (array) json_decode((string) self::post('skus', '[]'), true)), 'strlen'));
        $out = [];
        if ($skus && class_exists('TGS_Global_Product_Source')) {
            foreach ((array) TGS_Global_Product_Source::get_stock_for_skus(array_slice($skus, 0, 500), $blog) as $sku => $s) {
                $out[(string) $sku] = (float) ($s['actual_stock'] ?? 0);
            }
        }
        wp_send_json_success($out);
    }

    public static function save()
    {
        self::guard();
        $id = (int) self::post('transfer_id', 0);
        $items = json_decode((string) self::post('items', '[]'), true);
        $from = (int) self::post('from_blog_id', 0) ?: get_current_blog_id();
        if ($from !== get_current_blog_id() && !self::can_all()) {
            wp_send_json_error(['message' => 'Chỉ quản trị mới lập phiếu cho chi nhánh xuất khác website đang đứng']);
        }
        if (!get_site($from)) {
            wp_send_json_error(['message' => 'Chi nhánh xuất không tồn tại']);
        }
        $data = [
            'from_blog_id' => $from,
            'reason_code' => sanitize_text_field(self::post('reason_code')),
            'to_blog_id' => (int) self::post('to_blog_id'),
            'from_zone' => sanitize_text_field(self::post('from_zone')),
            'to_zone' => sanitize_text_field(self::post('to_zone')),
            'doc_date' => sanitize_text_field(self::post('doc_date')),
            'transfer_type' => (int) self::post('transfer_type', 1),
            'note' => sanitize_textarea_field(self::post('note')),
        ];
        $approve = !empty($_POST['approve']);
        if ($id > 0) {
            $cur = TGS_Global_Transfer::get($id, false);
            if (!$cur) {
                wp_send_json_error(['message' => 'Không thấy phiếu']);
            }
            if ((int) $cur['from_blog_id'] !== get_current_blog_id() && !self::can_all()) {
                wp_send_json_error(['message' => 'Chỉ bên xuất được sửa phiếu']);
            }
            $res = TGS_Global_Transfer::update($id, $data, is_array($items) ? $items : []);
            if (!is_wp_error($res) && $approve && (int) $res['export_status'] === 0) {
                $res = TGS_Global_Transfer::approve_export($id);
            }
        } else {
            $res = TGS_Global_Transfer::create($data, is_array($items) ? $items : [], $approve);
        }
        self::reply($res);
    }

    public static function get()
    {
        self::guard();
        $t = TGS_Global_Transfer::get((int) self::post('transfer_id'));
        if (!$t) {
            wp_send_json_error(['message' => 'Không thấy phiếu']);
        }
        $blog = get_current_blog_id();
        $t['side'] = (int) $t['from_blog_id'] === $blog ? 'out' : ((int) $t['to_blog_id'] === $blog ? 'in' : 'other');
        self::reply($t);
    }

    public static function list()
    {
        self::guard();
        $f = [];
        foreach (['direction', 'status', 'from', 'to', 'search', 'page', 'per_page'] as $k) {
            if (isset($_POST[$k])) {
                $f[$k] = sanitize_text_field(self::post($k));
            }
        }
        self::reply(TGS_Global_Transfer::search(get_current_blog_id(), $f));
    }

    /** Bộ lọc chung của danh sách / báo cáo từ POST. */
    private static function filters()
    {
        $f = [];
        foreach (['status', 'from', 'to', 'search', 'from_zone', 'to_zone'] as $k) {
            if (isset($_POST[$k])) {
                $f[$k] = sanitize_text_field(self::post($k));
            }
        }
        foreach (['blog_id', 'from_blog', 'to_blog'] as $k) {
            if (isset($_POST[$k]) && self::post($k) !== '' && self::post($k) !== 'all') {
                $f[$k] = (int) self::post($k);
            }
        }
        return $f;
    }

    public static function htlist()
    {
        self::guard();
        self::reply(TGS_Global_Transfer::list_rows(self::filters()));
    }

    public static function report()
    {
        self::guard();
        self::reply(TGS_Global_Transfer::report(self::filters(), self::post('mode') === 'item' ? 'item' : 'detail'));
    }

    public static function action()
    {
        self::guard();
        $id = (int) self::post('transfer_id');
        $do = sanitize_key(self::post('do'));
        $t = TGS_Global_Transfer::get($id, false);
        if (!$t) {
            wp_send_json_error(['message' => 'Không thấy phiếu']);
        }
        $blog = get_current_blog_id();
        $is_from = (int) $t['from_blog_id'] === $blog || self::can_all();
        $is_to = (int) $t['to_blog_id'] === $blog || self::can_all();
        $need = in_array($do, ['approve_import', 'unapprove_import', 'reject'], true) ? $is_to : $is_from;
        if (!$need) {
            wp_send_json_error(['message' => in_array($do, ['approve_import', 'unapprove_import', 'reject'], true) ? 'Chỉ bên nhận được thao tác này' : 'Chỉ bên xuất được thao tác này']);
        }
        switch ($do) {
            case 'approve_export':
                $res = TGS_Global_Transfer::approve_export($id);
                break;
            case 'unapprove_export':
                $res = TGS_Global_Transfer::unapprove_export($id);
                break;
            case 'approve_import':
                // Số lượng nhập từng dòng (bên nhận ghi khi xác nhận); không gửi = nhận đủ.
                $received = [];
                foreach ((array) json_decode((string) self::post('received', '[]'), true) as $item_id => $q) {
                    if ((int) $item_id > 0 && is_numeric($q)) {
                        $received[(int) $item_id] = (float) $q;
                    }
                }
                $res = TGS_Global_Transfer::approve_import($id, $received);
                break;
            case 'unapprove_import':
                $res = TGS_Global_Transfer::unapprove_import($id);
                break;
            case 'reject':
                $res = TGS_Global_Transfer::reject_import($id, sanitize_textarea_field(self::post('reason')));
                break;
            case 'delete':
                $res = TGS_Global_Transfer::delete($id);
                break;
            default:
                $res = new WP_Error('tgs_gt', 'Thao tác không hợp lệ');
        }
        self::reply($res);
    }
}
