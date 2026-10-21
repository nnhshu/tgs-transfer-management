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
 * Quyền: đăng nhập + thành viên website; bên ĐI sửa / xoá / duyệt xuất, bên ĐẾN duyệt nhận / từ chối (super admin làm được cả hai).
 */
if (!defined('ABSPATH')) {
    exit;
}

class TGS_GT_Ajax
{
    const NONCE = 'tgs_gt_nonce';

    public static function init()
    {
        foreach (['options', 'products', 'save', 'get', 'list', 'action', 'htlist', 'report'] as $a) {
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

    public static function options()
    {
        self::guard();
        $blog = (int) self::post('blog_id', get_current_blog_id());
        wp_send_json_success([
            'currentBlogId' => get_current_blog_id(),
            'sites' => TGS_Global_Transfer::sites(),
            'zones' => TGS_Global_Transfer::zones_of_blog($blog),
            'branchCode' => TGS_Global_Transfer::branch_of_blog($blog),
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

    public static function save()
    {
        self::guard();
        $id = (int) self::post('transfer_id', 0);
        $items = json_decode((string) self::post('items', '[]'), true);
        $data = [
            'from_blog_id' => get_current_blog_id(),
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
            if ((int) $cur['from_blog_id'] !== get_current_blog_id() && !is_super_admin()) {
                wp_send_json_error(['message' => 'Chỉ bên xuất được sửa phiếu']);
            }
            $data['from_blog_id'] = (int) $cur['from_blog_id'];
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
        $is_from = (int) $t['from_blog_id'] === $blog || is_super_admin();
        $is_to = (int) $t['to_blog_id'] === $blog || is_super_admin();
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
