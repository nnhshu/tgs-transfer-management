# Luồng sản phẩm global trong tgs-transfer-management

Tài liệu này là chuẩn phát triển cho plugin luân chuyển nội bộ. Khi cần tra API chi tiết,
đọc thêm `wp-content/plugins/tgs_shop_management/docs/global-product-api.md`.

## Nguyên tắc chính

- Catalog sản phẩm chỉ lấy từ `wp_global_product_name` qua `TGS_Global_Product_Source`
  hoặc REST API `/wp-json/tgs-shop/v1/products`.
- Plugin transfer không query, không join, không tạo, không cập nhật bảng
  `{prefix}_local_product_name`.
- Các cột `local_product_name_id`, `local_product_sku`, `local_product_name` trong
  `local_ledger_item` và `wp_global_product_lots` chỉ là alias/schema legacy.
- `local_product_name_id` trong phiếu transfer phải được hiểu là
  `global_product_name_id`.
- `local_product_sku` là snapshot SKU global để các shop đối soát cross-blog ổn định.
- Tồn kho không tracking lấy từ ledger/API theo SKU và `blog_id`, không cộng/trừ vào
  cột `local_product_quantity_no_tracking`.

## Class dùng trong plugin

File chính:

```text
includes/class-tgs-transfer-global-products.php
```

Hàm thường dùng:

```php
TGS_Transfer_Global_Products::query_products_for_transfer(get_current_blog_id());

TGS_Transfer_Global_Products::enrich_ledger_items($items, $blog_id);

TGS_Transfer_Global_Products::get_product_for_item($item, $blog_id);

TGS_Transfer_Global_Products::row_product_id((array) $item);

TGS_Transfer_Global_Products::row_sku((array) $item);
```

`enrich_ledger_items()` nhận các dòng `local_ledger_item`, tra global product theo
SKU trước rồi đến global id, sau đó gắn đủ alias cũ cho UI:

- `product_name`
- `sku`
- `barcode`
- `is_tracking`
- `local_product_name`
- `local_product_sku`
- `local_product_is_tracking`
- `global_product_name_id`
- `global_product_sku`

Nhờ vậy frontend cũ vẫn đọc được `item.product_name`, `item.sku`,
`item.is_tracking` mà backend không join bảng sản phẩm local.

## Picker sản phẩm

AJAX `tgs_transfer_get_products` gọi:

```php
TGS_Transfer_Global_Products::query_products_for_transfer($blog_id);
```

Payload trả cho UI giữ format cũ:

- `id`: global product id
- `name`: tên global
- `sku`: SKU global
- `barcode`: barcode global
- `is_tracking`
- `price`
- `tax_percent`
- `actual_stock`
- `projected_stock`
- `no_tracking_stock`
- `tracking_stock`

`actual_stock` và `projected_stock` do `TGS_Global_Product_Source` tính từ
`local_ledger_item` + `local_ledger` theo `blog_id`.

## Check sync sang shop đích

AJAX `tgs_transfer_check_products_sync` không còn kiểm tra bảng product local của
shop đích. Vì catalog global dùng chung, sản phẩm được coi là đã đồng bộ nếu tồn tại
trong `wp_global_product_name`.

Response:

```json
{
  "synced": [1, 2, 3],
  "need_sync": [],
  "missing_global": [],
  "global_catalog": true
}
```

Nếu `missing_global` có id, nghĩa là phiếu hoặc frontend đang giữ id không còn tồn tại
trong global catalog, cần sửa dữ liệu gốc.

## Luồng bán nội bộ

1. Trang tạo bán/trả nội bộ dùng `ticket-create-base` của `tgs_shop_management`.
2. `TGS_Shop_Base_Import_Export::create_export_item()` validate sản phẩm bằng
   `TGS_Global_Product_Source::get_product_object()`.
3. Ledger item lưu:
   - `local_product_name_id` = global product id
   - `global_product_name_id` = global product id
   - `local_product_sku` = global SKU
4. Duyệt xuất (`approve_export`) chỉ xử lý ledger/lots:
   - tracking: chuyển global lots sang pending, cập nhật `source_blog_id`,
     `to_blog_id`
   - non-tracking: không cập nhật catalog local
5. Không có bước copy sản phẩm sang shop nhận.

## Luồng mua/nhận trả nội bộ

1. Shop nhận mở pending transfer.
2. Backend switch sang shop nguồn để lấy `local_ledger_item` của phiếu nguồn.
3. Các dòng item được enrich bằng global product.
4. Khi tạo phiếu nhập/nhận trả, `product_id` truyền vào base import là global id.
5. `TGS_Shop_Base_Import_Export::create_import_item()` ghi ledger item mới với
   global id + SKU.
6. Duyệt nhập (`approve_import`) chỉ xử lý lots tracking và trạng thái transfer.
   Tồn non-tracking tiếp tục do ledger/API tính.

## Luồng từ chối

- `reject_export`: tracking lots về ACTIVE; non-tracking không cộng lại catalog local.
- `reject_import`: tracking lots chuyển sang pending return; non-tracking không ghi
  catalog local vì phiếu nhập chưa làm phát sinh tồn duyệt.

## Quy tắc khi phát triển tiếp

- Không dùng `$wpdb->prefix . 'local_product_name'`.
- Không dùng `JOIN ... local_product_name`.
- Không ghi `local_product_quantity_no_tracking`.
- Khi cần tên/SKU/barcode/tracking từ ledger item, gọi
  `TGS_Transfer_Global_Products::enrich_ledger_items()`.
- Khi cần tồn, gọi `TGS_Global_Product_Source` với `blog_id` và `with_stock => true`
  hoặc REST API trong `global-product-api.md`.
- Chỉ được dùng `local_product_name_id` như alias global id cho schema cũ.
