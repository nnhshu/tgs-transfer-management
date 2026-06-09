<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cầu nối sản phẩm global cho luồng luân chuyển nội bộ.
 *
 * Ledger/lots vẫn còn các cột tên local_product_* để tương thích schema cũ,
 * nhưng giá trị sản phẩm được hiểu là alias của wp_global_product_name.
 */
class TGS_Transfer_Global_Products
{
    private static $table_cache = [];

    private static function ensure_global_constants()
    {
        global $wpdb;

        if (!defined('TGS_TABLE_GLOBAL_PRODUCT_NAME')) {
            define('TGS_TABLE_GLOBAL_PRODUCT_NAME', $wpdb->base_prefix . 'global_product_name');
        }

        if (!defined('TGS_TABLE_GLOBAL_PRODUCT_LOTS')) {
            define('TGS_TABLE_GLOBAL_PRODUCT_LOTS', $wpdb->base_prefix . 'global_product_lots');
        }
    }

    private static function ensure_source()
    {
        self::ensure_global_constants();

        if (class_exists('TGS_Global_Product_Source')) {
            return true;
        }

        $plugin_root = defined('WP_PLUGIN_DIR')
            ? WP_PLUGIN_DIR
            : dirname(TGS_TRANSFER_PLUGIN_DIR);

        $candidates = [
            trailingslashit($plugin_root) . 'tgs_shop_management/functions/class-tgs-global-product-source.php',
            trailingslashit(dirname(TGS_TRANSFER_PLUGIN_DIR)) . 'tgs_shop_management/functions/class-tgs-global-product-source.php',
        ];

        foreach ($candidates as $file) {
            if (is_readable($file)) {
                require_once $file;
                break;
            }
        }

        return class_exists('TGS_Global_Product_Source');
    }

    public static function is_available()
    {
        return self::ensure_source();
    }

    public static function query_products(array $args = [])
    {
        if (!self::ensure_source()) {
            return [
                'items' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 0,
                'total_pages' => 0,
            ];
        }

        $args = wp_parse_args($args, [
            'parent_only' => false,
            'with_local_aliases' => true,
            'with_stock' => !empty($args['blog_id']),
            'status_filter' => 'all',
            'require_sku' => true,
        ]);

        return TGS_Global_Product_Source::query_products($args);
    }

    public static function query_products_for_transfer($blog_id, array $args = [])
    {
        $explicit_page = isset($args['page']);
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, (int) ($args['per_page'] ?? 1000));

        $result = self::query_products(wp_parse_args($args, [
            'blog_id' => (int) $blog_id,
            'with_stock' => true,
            'with_local_aliases' => true,
            'parent_only' => false,
            'status_filter' => 'all',
            'require_sku' => true,
            'page' => $page,
            'per_page' => $per_page,
            'order_by' => 'global_product_name',
            'order_dir' => 'ASC',
        ]));

        $items = $result['items'] ?? [];
        $total_pages = (int) ($result['total_pages'] ?? 1);

        while (!$explicit_page && $page < $total_pages) {
            $page++;
            $next = self::query_products(wp_parse_args($args, [
                'blog_id' => (int) $blog_id,
                'with_stock' => true,
                'with_local_aliases' => true,
                'parent_only' => false,
                'status_filter' => 'all',
                'require_sku' => true,
                'page' => $page,
                'per_page' => $per_page,
                'order_by' => 'global_product_name',
                'order_dir' => 'ASC',
            ]));

            $items = array_merge($items, $next['items'] ?? []);
            $total_pages = (int) ($next['total_pages'] ?? $total_pages);
        }

        $products = [];
        foreach ($items as $item) {
            $products[] = (object) self::format_transfer_product($item);
        }

        return $products;
    }

    public static function format_transfer_product(array $item)
    {
        $stock = $item['stock'] ?? [];
        $actual_stock = (float) ($item['actual_stock'] ?? ($stock['actual_stock'] ?? 0));
        $projected_stock = (float) ($item['projected_stock'] ?? ($stock['projected_stock'] ?? 0));
        $is_tracking = (int) ($item['global_product_is_tracking'] ?? $item['local_product_is_tracking'] ?? 0);

        return [
            'id' => (int) ($item['global_product_name_id'] ?? $item['local_product_name_id'] ?? 0),
            'name' => (string) ($item['global_product_name'] ?? $item['local_product_name'] ?? ''),
            'barcode' => (string) ($item['global_product_barcode_main'] ?? $item['local_product_barcode_main'] ?? ''),
            'is_tracking' => $is_tracking,
            'price' => (float) ($item['global_product_price'] ?? $item['local_product_price'] ?? 0),
            'tax_percent' => (float) ($item['global_product_tax'] ?? $item['local_product_tax'] ?? 0),
            'no_tracking_stock' => $is_tracking ? 0 : $projected_stock,
            'tracking_stock' => $is_tracking ? $actual_stock : 0,
            'actual_stock' => $actual_stock,
            'projected_stock' => $projected_stock,
            'pending_stock_delta' => (float) ($item['pending_stock_delta'] ?? ($stock['pending_stock_delta'] ?? 0)),
            'source_blog_id' => (int) ($item['global_blog_id'] ?? 0),
            'sku' => (string) ($item['global_product_sku'] ?? $item['local_product_sku'] ?? ''),
            'unit' => (string) ($item['global_product_unit'] ?? $item['local_product_unit'] ?? ''),
            'global_product_name_id' => (int) ($item['global_product_name_id'] ?? 0),
            'global_product_sku' => (string) ($item['global_product_sku'] ?? ''),
        ];
    }

    public static function get_products_by_ids(array $ids, $blog_id = 0)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $args = [
            'ids' => $ids,
            'per_page' => count($ids),
            'parent_only' => false,
            'with_local_aliases' => true,
            'status_filter' => 'all',
            'require_sku' => false,
        ];

        if ((int) $blog_id > 0) {
            $args['blog_id'] = (int) $blog_id;
            $args['with_stock'] = true;
        }

        $result = self::query_products($args);
        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    public static function get_products_by_skus(array $skus, $blog_id = 0)
    {
        $skus = array_values(array_unique(array_filter(array_map(static function ($sku) {
            return trim((string) $sku);
        }, $skus))));

        if (empty($skus)) {
            return [];
        }

        $args = [
            'skus' => $skus,
            'per_page' => count($skus),
            'parent_only' => false,
            'with_local_aliases' => true,
            'status_filter' => 'all',
            'require_sku' => false,
        ];

        if ((int) $blog_id > 0) {
            $args['blog_id'] = (int) $blog_id;
            $args['with_stock'] = true;
        }

        $result = self::query_products($args);
        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    public static function get_product_for_item($row, $blog_id = 0)
    {
        $row = is_array($row) ? $row : (array) $row;
        $sku = self::row_sku($row);

        if ($sku !== '' && self::ensure_source()) {
            $product = TGS_Global_Product_Source::get_product($sku, [
                'by' => 'sku',
                'blog_id' => (int) $blog_id ?: null,
                'with_stock' => (int) $blog_id > 0,
                'with_local_aliases' => true,
            ]);
            if ($product) {
                return $product;
            }
        }

        $product_id = self::row_product_id($row);
        if ($product_id > 0 && self::ensure_source()) {
            return TGS_Global_Product_Source::get_product($product_id, [
                'by' => 'id',
                'blog_id' => (int) $blog_id ?: null,
                'with_stock' => (int) $blog_id > 0,
                'with_local_aliases' => true,
            ]);
        }

        return null;
    }

    public static function product_exists_for_item($row, $blog_id = 0)
    {
        return (bool) self::get_product_for_item($row, $blog_id);
    }

    public static function enrich_ledger_items(array $rows, $blog_id = 0, $as_objects = true)
    {
        $ids = [];
        $skus = [];

        foreach ($rows as $row) {
            $row_array = is_array($row) ? $row : (array) $row;

            $product_id = self::row_product_id($row_array);
            if ($product_id > 0) {
                $ids[] = $product_id;
            }

            $sku = self::row_sku($row_array);
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        $products_by_id = [];
        foreach (self::get_products_by_ids($ids, $blog_id) as $product) {
            $id = (int) ($product['global_product_name_id'] ?? 0);
            if ($id > 0) {
                $products_by_id[$id] = $product;
            }
        }

        $products_by_sku = [];
        foreach (self::get_products_by_skus($skus, $blog_id) as $product) {
            $sku = trim((string) ($product['global_product_sku'] ?? ''));
            if ($sku !== '') {
                $products_by_sku[$sku] = $product;
            }
        }

        $enriched = [];
        foreach ($rows as $row) {
            $row_array = is_array($row) ? $row : (array) $row;
            $product_id = self::row_product_id($row_array);
            $row_sku = self::row_sku($row_array);

            $product = null;
            if ($row_sku !== '' && isset($products_by_sku[$row_sku])) {
                $product = $products_by_sku[$row_sku];
            } elseif ($product_id > 0 && isset($products_by_id[$product_id])) {
                $product = $products_by_id[$product_id];
            }

            $row_array = self::apply_product_aliases($row_array, $product);
            $enriched[] = $as_objects ? (object) $row_array : $row_array;
        }

        return $enriched;
    }

    public static function apply_product_aliases(array $row, $product = null)
    {
        $product = is_array($product) ? $product : [];
        $global_id = !empty($product['global_product_name_id'])
            ? (int) $product['global_product_name_id']
            : self::row_product_id($row);
        $sku = !empty($product['global_product_sku'])
            ? (string) $product['global_product_sku']
            : self::row_sku($row);

        $name = (string) ($product['global_product_name'] ?? $row['global_product_name'] ?? $row['product_name'] ?? '');
        $barcode = (string) ($product['global_product_barcode_main'] ?? $row['global_product_barcode_main'] ?? $row['barcode'] ?? '');
        $unit = (string) ($product['global_product_unit'] ?? $row['global_product_unit'] ?? $row['local_product_unit'] ?? '');
        $is_tracking = isset($product['global_product_is_tracking'])
            ? (int) $product['global_product_is_tracking']
            : (int) ($row['is_tracking'] ?? $row['local_product_is_tracking'] ?? 0);

        $row['global_product_name_id'] = $global_id;
        $row['global_product_sku'] = $sku;
        $row['global_product_name'] = $name;
        $row['global_product_unit'] = $unit;
        $row['global_product_barcode_main'] = $barcode;
        $row['global_product_tax'] = (float) ($product['global_product_tax'] ?? $row['global_product_tax'] ?? 0);
        $row['global_product_price'] = (float) ($product['global_product_price'] ?? $row['global_product_price'] ?? 0);
        $row['global_product_price_after_tax'] = (float) ($product['global_product_price_after_tax'] ?? $row['global_product_price_after_tax'] ?? 0);
        $row['global_product_is_tracking'] = $is_tracking;

        // Alias legacy cho các màn transfer cũ. Không đọc bảng local_product_name.
        $row['local_product_name_id'] = $global_id;
        $row['local_product_sku'] = $sku;
        $row['local_product_name'] = $name;
        $row['local_product_unit'] = $unit;
        $row['local_product_barcode_main'] = $barcode;
        $row['local_product_tax'] = $row['global_product_tax'];
        $row['local_product_price'] = $row['global_product_price'];
        $row['local_product_price_after_tax'] = $row['global_product_price_after_tax'];
        $row['local_product_is_tracking'] = $is_tracking;
        $row['local_product_quantity_no_tracking'] = (float) ($product['projected_stock'] ?? $row['local_product_quantity_no_tracking'] ?? 0);

        $row['product_name'] = $name;
        $row['sku'] = $sku;
        $row['barcode'] = $barcode;
        $row['is_tracking'] = $is_tracking;
        $row['synced_in_destination'] = true;

        if (isset($product['stock'])) {
            $row['stock'] = $product['stock'];
            $row['actual_stock'] = (float) ($product['actual_stock'] ?? 0);
            $row['projected_stock'] = (float) ($product['projected_stock'] ?? 0);
            $row['pending_stock_delta'] = (float) ($product['pending_stock_delta'] ?? 0);
        }

        return $row;
    }

    public static function row_product_id(array $row)
    {
        $global_id = (int) ($row['global_product_name_id'] ?? 0);
        if ($global_id > 0) {
            return $global_id;
        }

        return (int) ($row['local_product_name_id'] ?? $row['product_id'] ?? 0);
    }

    public static function row_sku(array $row)
    {
        foreach (['global_product_sku', 'local_product_sku', 'sku'] as $key) {
            $sku = trim((string) ($row[$key] ?? ''));
            if ($sku !== '') {
                return $sku;
            }
        }

        $meta = $row['local_ledger_item_meta'] ?? $row['local_product_meta'] ?? null;
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                foreach (['sku', 'product_sku', 'global_product_sku'] as $key) {
                    $sku = trim((string) ($decoded[$key] ?? ''));
                    if ($sku !== '') {
                        return $sku;
                    }
                }
            }
        }

        return '';
    }

    public static function table_exists($table)
    {
        global $wpdb;

        if ($table === '') {
            return false;
        }

        if (isset(self::$table_cache[$table])) {
            return self::$table_cache[$table];
        }

        self::$table_cache[$table] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        return self::$table_cache[$table];
    }
}

TGS_Transfer_Global_Products::is_available();
