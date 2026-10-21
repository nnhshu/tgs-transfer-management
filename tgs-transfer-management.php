<?php
/**
 * Plugin Name: TGS Transfer Management
 * Plugin URI: https://bizgpt.vn/
 * Description: Chuyển kho nội bộ giữa các shop / chi nhánh / phân kho — MỘT phiếu global hai đầu (wp_global_transfer), tồn hai bên tính thẳng từ phiếu. Extension của TGS Shop Management.
 * Version: 2.0.0
 * Author: BIZGPT_AI
 * Author URI: https://bizgpt.vn/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tgs-transfer-management
 * Domain Path: /languages
 * Requires Plugins: tgs_shop_management
 *
 * Luồng (21/10/2026, thay hẳn luồng cũ transfer_ledger từng website + phiếu 12–15 hai chiều):
 *   bên ĐI lập phiếu (phân kho đi → website / chi nhánh + phân kho đến) → duyệt xuất (bên đi trừ tồn) → bên ĐẾN duyệt nhận
 *   (cộng tồn) hoặc từ chối (bên đi được trả tồn). Sửa phiếu một chỗ, tồn hai bên tự đúng. Dịch vụ: TGS_Global_Transfer
 *   (tgs_shop_management/functions/class-tgs-global-transfer.php); AJAX: includes/class-tgs-gt-ajax.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_TRANSFER_VERSION', '2.0.0');
define('TGS_TRANSFER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_TRANSFER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TGS_TRANSFER_PLUGIN_BASENAME', plugin_basename(__FILE__));

/*
 * Link tới một màn của dashboard. Bản gốc chỉ khai báo trong file thanh menu (header-mega-nav / sidebar) của
 * tgs_shop_management — màn mở dạng tab không nạp thanh menu thì hàm chưa có → lỗi nghiêm trọng. Khai báo sẵn ở đây.
 */
if (!function_exists('tgs_url')) {
    function tgs_url($view)
    {
        return admin_url('admin.php?page=tgs-shop-management&view=' . $view);
    }
}

class TGS_Transfer_Management
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'check_dependencies']);
    }

    public function check_dependencies()
    {
        if (!class_exists('TGS_Shop_Management')) {
            add_action('admin_notices', static function () {
                echo '<div class="notice notice-error"><p><strong>TGS Transfer Management</strong> yêu cầu plugin <strong>TGS Shop Management</strong> phải được kích hoạt trước.</p></div>';
            });
            return;
        }
        require_once TGS_TRANSFER_PLUGIN_DIR . 'includes/class-tgs-gt-ajax.php';
        add_filter('tgs_shop_dashboard_routes', [$this, 'register_routes']);
        add_action('tgs_shop_sidebar_menu', [$this, 'render_sidebar_menu']);
        TGS_GT_Ajax::init();
    }

    /** Route giữ nguyên slug cũ (link / bookmark / phân quyền không đổi), trỏ về màn mới. */
    public function register_routes($routes)
    {
        $d = TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/';
        return array_merge($routes, [
            'transfer-export-add' => ['Tạo phiếu chuyển kho', $d . 'gt-form.php'],
            'ticket-transfer-exports' => ['Phiếu chuyển kho đi', $d . 'list-export.php'],
            'ticket-transfer-imports' => ['Phiếu chuyển kho đến', $d . 'list-import.php'],
            'transfer-report' => ['Tất cả phiếu chuyển kho', $d . 'list-all.php'],
            'ticket-transfer-export-detail' => ['Chi tiết phiếu chuyển kho', $d . 'gt-detail.php'],
            'ticket-transfer-import-detail' => ['Chi tiết phiếu chuyển kho', $d . 'gt-detail.php'],
        ]);
    }

    public function render_sidebar_menu($current_view)
    {
        $views = ['transfer-export-add', 'ticket-transfer-exports', 'ticket-transfer-imports', 'transfer-report', 'ticket-transfer-export-detail', 'ticket-transfer-import-detail'];
        $item = static function ($view, $icon, $text) use ($current_view) {
            printf('<li class="menu-item %s"><a href="%s" class="menu-link"><i class="bx %s me-1"></i><div>%s</div></a></li>',
                $current_view === $view ? 'active' : '', esc_url(tgs_url($view)), esc_attr($icon), esc_html($text));
        };
        ?>
        <li class="menu-item <?php echo in_array($current_view, $views, true) ? 'active open' : ''; ?>">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
                <i class="menu-icon tf-icons bx bx-transfer-alt"></i>
                <div>Chuyển kho nội bộ</div>
            </a>
            <ul class="menu-sub">
                <?php
                $item('transfer-export-add', 'bx-plus', 'Tạo phiếu chuyển kho');
                $item('ticket-transfer-exports', 'bx-log-out-circle', 'Chuyển đi');
                $item('ticket-transfer-imports', 'bx-log-in-circle', 'Chuyển đến (chờ nhận)');
                $item('transfer-report', 'bx-list-ul', 'Tất cả phiếu');
                ?>
            </ul>
        </li>
        <?php
    }
}

TGS_Transfer_Management::get_instance();
