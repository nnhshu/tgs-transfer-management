# Hàng đang đi đường — cách xác định

> Quy tắc nghiệp vụ. Dùng cho báo cáo tồn, đối soát, cảnh báo hàng lâu chưa nhận.

## Định nghĩa

**Hàng đang đi đường của một website** = các dòng hàng nằm trong một **phiếu nhập tự động
chưa được duyệt**, mà phiếu đó sinh ra từ một **phiếu mua nội bộ cũng chưa được duyệt**.

Hàng đã rời kho bên gửi (bên gửi đã duyệt phiếu xuất, tồn đã trừ) nhưng bên nhận chưa
duyệt phiếu nhập, nên chưa cộng vào tồn của bên nhận. Khoảng giữa đó chính là hàng đang
trên đường.

Áp dụng cho **mọi chiều luân chuyển nội bộ**: shop → shop, kho → shop, shop → kho.

## Điều kiện chính xác

Xét trên bảng `local_ledger` **của từng website** (tiền tố theo blog: `wp_local_ledger`,
`wp_3_local_ledger`, `wp_5_local_ledger`…).

Phiếu **con** — phiếu nhập tự động:

| Cột | Điều kiện | Ý nghĩa |
|---|---|---|
| `local_ledger_type` | `= 1` | `TGS_LEDGER_TYPE_PURCHASE` — phiếu nhập hàng |
| `local_ledger_approver_status` | `IS NULL` hoặc `= 0` | chưa duyệt (`TGS_APPROVER_STATUS_PENDING`) |
| `local_ledger_parent_id` | trỏ tới phiếu cha bên dưới | |

Phiếu **cha** — phiếu mua nội bộ:

| Cột | Điều kiện | Ý nghĩa |
|---|---|---|
| `local_ledger_type` | `= 13` | `TGS_LEDGER_TYPE_TRANSFER_IMPORT` — phiếu mua nội bộ |
| `local_ledger_approver_status` | `IS NULL` hoặc `= 0` | chưa duyệt |

Khi cả hai điều kiện thoả, cột **`local_ledger_item_id` của phiếu nhập** (JSON mảng id)
chính là danh sách dòng hàng đang đi đường. Mỗi phần tử là một
`local_ledger_item.local_ledger_item_id`, ứng với một mã hàng.

## Câu truy vấn

```sql
-- Thay {P} bằng tiền tố của website cần xét: wp_ , wp_3_ , wp_5_ ...
SELECT
    imp.local_ledger_id            AS import_ledger_id,
    imp.local_ledger_code          AS import_code,
    imp.local_ledger_item_id       AS item_ids_json,
    parent.local_ledger_id         AS purchase_ledger_id,
    parent.local_ledger_code       AS purchase_code,
    imp.created_at
FROM {P}local_ledger AS imp
INNER JOIN {P}local_ledger AS parent
        ON parent.local_ledger_id = imp.local_ledger_parent_id
WHERE imp.local_ledger_type = 1                                    -- phiếu nhập
  AND (imp.local_ledger_approver_status IS NULL
       OR imp.local_ledger_approver_status = 0)                    -- chưa duyệt
  AND parent.local_ledger_type = 13                                -- phiếu mua nội bộ
  AND (parent.local_ledger_approver_status IS NULL
       OR parent.local_ledger_approver_status = 0)                 -- cha cũng chưa duyệt
  AND (imp.is_deleted = 0 OR imp.is_deleted IS NULL)
  AND (parent.is_deleted = 0 OR parent.is_deleted IS NULL);
```

Muốn ra từng **dòng hàng** thay vì từng phiếu, nối tiếp sang `local_ledger_item` theo
danh sách id trong `item_ids_json`.

## Những chỗ dễ sai

**Đừng viết `approver_status != 1`.** Trạng thái `2` là **từ chối**
(`TGS_APPROVER_STATUS_REJECTED`) — phiếu bị từ chối thì hàng không đi đường nữa. Phải
liệt kê tường minh `IS NULL OR = 0`.

**`NULL` và `0` đều là chưa duyệt.** Dữ liệu cũ có dòng để `NULL`, dòng để `0`. Thiếu vế
`IS NULL` là sót phiếu.

**Phải xét đúng bảng của từng website.** Mỗi site một bảng riêng theo tiền tố blog. Chạy
gộp trên một bảng duy nhất sẽ ra thiếu.

**Trong PHP, đừng dùng hằng số `TGS_TABLE_LOCAL_LEDGER` khi chạy chéo site.** Hằng số
được định nghĩa một lần lúc nạp plugin theo blog khởi tạo request; `switch_to_blog()`
không đổi được nó. Luôn tính `$wpdb->prefix . 'local_ledger'` **sau** khi switch.

**`local_ledger_item_id` là chuỗi JSON**, không phải khoá ngoại. Phải `json_decode` rồi
mới `IN (...)` — và nhớ ép kiểu số nguyên trước khi ghép vào SQL.

## Vì sao điều kiện phải có cả phiếu cha

Phiếu nhập type 1 xuất hiện ở nhiều luồng khác: nhập mua hàng từ NCC, nhập từ khách trả,
nhập tay… Chỉ khi phiếu cha là type 13 thì mới chắc đây là hàng luân chuyển nội bộ.

Điều kiện phiếu cha **cũng chưa duyệt** để loại trường hợp bên nhận đã duyệt phiếu mua
nội bộ nhưng phiếu nhập con còn treo vì lý do khác — lúc đó hàng đã về tới nơi, không
còn tính là đang đi đường.

## Bối cảnh liên quan

Từ 06/08/2026, khi shop bán duyệt phiếu xuất kho thì hệ thống **tự sinh** phiếu mua nội
bộ (MNB, type 13) + phiếu nhập tự động (AMN, type 1) bên shop nhận, cả hai ở trạng thái
**chờ duyệt** — xem `auto_create_destination_import()` trong
`includes/class-tgs-transfer-ajax.php`.

Nghĩa là sau thay đổi này, **mọi** lô hàng luân chuyển nội bộ đều rơi vào trạng thái
"đang đi đường" ngay khi bên gửi duyệt xuất, cho tới khi bên nhận duyệt.
