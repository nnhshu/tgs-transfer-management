<?php
/**
 * Plugin Name: TGS Transfer Management
 * Plugin URI: https://bizgpt.vn/
 * Description: Plugin quản lý luân chuyển nội bộ giữa các shop - Extension của TGS Shop Management
 * Version: 1.0.0
 * Author: BIZGPT_AI
 * Author URI: https://bizgpt.vn/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tgs-transfer-management
 * Domain Path: /languages
 * Requires Plugins: tgs_shop_management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TGS_TRANSFER_VERSION', '1.0.0');
define('TGS_TRANSFER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_TRANSFER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TGS_TRANSFER_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Class chính của plugin TGS Transfer Management
 */
class TGS_Transfer_Management
{
    private static $instance = null;

    /**
     * Singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        // Kiểm tra plugin phụ thuộc
        add_action('plugins_loaded', [$this, 'check_dependencies']);
    }

    /**
     * Kiểm tra plugin TGS Shop Management đã được kích hoạt chưa
     */
    public function check_dependencies()
    {
        // Kiểm tra plugin gốc có active không
        if (!class_exists('TGS_Shop_Management')) {
            add_action('admin_notices', [$this, 'show_dependency_notice']);
            return;
        }

        // Plugin gốc đã active, khởi tạo
        $this->init();
    }

    /**
     * Hiển thị thông báo nếu thiếu plugin phụ thuộc
     */
    public function show_dependency_notice()
    {
        ?>
        <div class="notice notice-error">
            <p>
                <strong>TGS Transfer Management</strong> yêu cầu plugin
                <strong>TGS Shop Management</strong> phải được kích hoạt trước.
            </p>
        </div>
        <?php
    }

    /**
     * Khởi tạo plugin
     */
    public function init()
    {
        // Load các file cần thiết
        $this->load_dependencies();

        // Đăng ký hooks
        $this->register_hooks();
    }

    /**
     * Load các file dependencies
     */
    private function load_dependencies()
    {
        require_once TGS_TRANSFER_PLUGIN_DIR . 'includes/class-tgs-transfer-global-products.php';
        require_once TGS_TRANSFER_PLUGIN_DIR . 'includes/class-tgs-transfer-ajax.php';
    }

    /**
     * Đăng ký các hooks
     */
    private function register_hooks()
    {
        // Hook vào routes của plugin gốc
        add_filter('tgs_shop_dashboard_routes', [$this, 'register_routes']);

        // Hook vào sidebar menu
        add_action('tgs_shop_sidebar_menu', [$this, 'render_sidebar_menu']);

        // Khởi tạo AJAX handlers
        TGS_Transfer_Ajax::init();
    }

    /**
     * Đăng ký routes cho Transfer
     */
    public function register_routes($routes)
    {
        /*
         * THUẬT NGỮ THỐNG NHẤT — bên gửi "xuất", bên nhận "nhận".
         *
         * Trước đây gọi là "bán" / "mua" nội bộ, dễ lẫn với bán hàng cho khách và
         * mua hàng của nhà cung cấp. Slug view giữ nguyên (export / import) để
         * không phải sửa link, chỉ đổi phần chữ người dùng đọc.
         */
        $transfer_routes = [
            // Luân chuyển nội bộ
            'transfer-export-add' => ['Tạo phiếu xuất nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-export-add.php'],
            'ticket-transfer-exports' => ['DS phiếu xuất nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/list-export.php'],
            'ticket-transfer-export-detail' => ['Chi tiết phiếu xuất nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/detail-export.php'],
            'transfer-import-add' => ['Tạo phiếu nhận nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-import-add.php'],
            'ticket-transfer-imports' => ['DS phiếu nhận nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/list-import.php'],
            'ticket-transfer-import-detail' => ['Chi tiết phiếu nhận nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/detail-import.php'],
            'transfer-report' => ['Báo cáo luân chuyển nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-report.php'],

            /*
             * Các màn dưới đây đã BỎ KHỎI MENU (luồng chờ nhận thủ công và nhánh
             * trả hàng riêng). Route vẫn giữ để link cũ, phiếu cũ còn mở được.
             */
            'transfer-pending-imports' => ['Phiếu chờ nhận nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/pending-imports.php'],
            'transfer-return-add' => ['Trả hàng nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-return-add.php'],
            'ticket-internal-returns' => ['DS phiếu trả nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/list-return.php'],
            'ticket-internal-return-detail' => ['Chi tiết phiếu trả nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/detail-return.php'],
            'transfer-pending-returns' => ['Chờ nhận từ shop trả', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/pending-return-receives.php'],
            'transfer-return-receive-add' => ['Nhận trả nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-return-receive-add.php'],
            'ticket-internal-return-receives' => ['DS phiếu nhận trả nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/list-return-receive.php'],
            'ticket-internal-return-receive-detail' => ['Chi tiết phiếu nhận trả nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/detail-return-receive.php'],
        ];

        return array_merge($routes, $transfer_routes);
    }

    /**
     * Render sidebar menu cho Transfer
     */
    public function render_sidebar_menu($current_view)
    {
        /*
         * Menu luân chuyển nội bộ chỉ còn hai đầu phiếu: XUẤT (bên gửi) và
         * NHẬN (bên nhận).
         *
         * Các màn "Chờ nhận nội bộ" / "Chờ nhận từ shop trả" đã bỏ khỏi menu:
         * duyệt phiếu xuất là hệ thống tự sinh phiếu chờ duyệt bên nhận, không
         * còn phải vào màn chờ bấm tạo tay. Nhánh trả hàng nội bộ cũng bỏ vì trả
         * dùng chung phiếu xuất nội bộ.
         *
         * Route của các màn cũ vẫn giữ để link/bookmark cũ không chết.
         */
        $transfer_views = [
            'ticket-transfer-exports',
            'ticket-transfer-export-detail',
            'transfer-import-add',
            'ticket-transfer-imports',
            'ticket-transfer-import-detail',
            'transfer-report'
        ];
        $is_active = in_array($current_view, $transfer_views) ? 'active open' : '';
        ?>
        <!-- Luân chuyển nội bộ - From TGS Transfer Management Plugin -->
        <li class="menu-item <?php echo $is_active; ?>">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
                <i class="menu-icon tf-icons bx bx-transfer-alt"></i>
                <div>Luân chuyển nội bộ</div>
            </a>
            <ul class="menu-sub">
                <li class="menu-item <?php echo $current_view === 'transfer-report' ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('transfer-report'); ?>" class="menu-link">
                        <i class="bx bx-bar-chart-alt-2 text-primary me-1"></i>
                        <div>Báo cáo & Thống kê</div>
                    </a>
                </li>
                <li class="menu-item <?php echo in_array($current_view, ['ticket-transfer-exports', 'ticket-transfer-export-detail']) ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('ticket-transfer-exports'); ?>" class="menu-link">
                        <i class="bx bx-list-ul me-1"></i>
                        <div>DS phiếu xuất nội bộ</div>
                    </a>
                </li>
                <li class="menu-item <?php echo in_array($current_view, ['ticket-transfer-imports', 'ticket-transfer-import-detail']) ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('ticket-transfer-imports'); ?>" class="menu-link">
                        <i class="bx bx-download text-success me-1"></i>
                        <div>DS phiếu nhận nội bộ</div>
                    </a>
                </li>
            </ul>
        </li>
        <?php
    }
}

/**
 * Khởi tạo plugin
 */
function tgs_transfer_management_init()
{
    return TGS_Transfer_Management::get_instance();
}

tgs_transfer_management_init();
