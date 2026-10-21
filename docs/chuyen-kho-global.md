# Chuyển kho — một phiếu global hai đầu

Áp dụng từ 21/10/2026, thay hẳn luồng cũ. Luồng cũ dùng phiếu cha 12/13/14/15, phiếu con nhập/xuất 1/2 và bảng `{prefix}transfer_ledger` ở từng website.

## Dữ liệu

| Bảng | Nội dung |
|---|---|
| `wp_global_transfer` | Đầu phiếu, gồm cả hai đầu. **Đầu đi**: `from_blog_id`, `from_branch_code`, `from_zone`. **Đầu đến**: `to_blog_id`, `to_branch_code`, `to_zone`. **Trạng thái bên đi**: `export_status` (0 nháp, 1 đã duyệt xuất). **Trạng thái bên đến**: `import_status` (0 chờ nhận, 1 đã nhận, 2 từ chối). |
| `wp_global_transfer_item` | Dòng hàng. `quantity` là SL xuất. `received_qty` là SL thực nhận (NULL nghĩa là nhận đủ, dùng khi nhận thiếu). |

- `blog_id = 0` nghĩa là đầu **ngoài hệ thống**: hàng về từ, hoặc đi ra, một kho chưa có website. Trường hợp này chủ yếu đến từ dữ liệu cũ chỉ có một đầu.
- Chi nhánh của mỗi đầu lấy từ "Cấu hình phân cấp → chi nhánh" lúc lập phiếu.
- Phân kho mặc định của website là `wp_blogs.tgs_site_code` (mã kho đồng bộ từ ERP).

## Tồn kho

Không có dòng `local_ledger` nào cho chuyển kho. Mọi nơi tính tồn đều cộng thêm `TGS_Global_Transfer::movements_sql($blog_id)`, hoặc dùng các hàm `net()` / `in_out()`:

- **Bên đi**: phiếu đã duyệt xuất, chưa bị từ chối, chưa xoá. Tồn trừ `quantity`. Ngày = ngày phiếu.
- **Bên đến**: phiếu đã nhận, chưa xoá. Tồn cộng `received_qty`, nếu NULL thì cộng `quantity`. Ngày = ngày duyệt nhận.
- **Hàng đi đường của bên đến**: phiếu đã duyệt xuất nhưng `import_status = 0`.

Sửa phiếu chỉ sửa ở một chỗ, tồn hai bên tự đúng. Từ chối nhận thì bên đi được trả lại tồn. Xoá mềm thì cả hai bên trả lại tồn.

## Ai làm gì

| Màn | Việc |
|---|---|
| wp-admin → Chuyển kho nội bộ (plugin này) | Lập / sửa phiếu: chọn phân kho đi, website + phân kho đến. Duyệt / bỏ duyệt xuất, xoá (bên đi). Duyệt / bỏ duyệt nhận, từ chối (bên đến). |
| POS → Phiếu nội bộ (`tgs_pos`) | Shop duyệt xuất / duyệt nhận, từ chối, quay lại chờ duyệt, ghi chú. Các thao tác này cần mật khẩu. |
| Nhập phiếu chuyển kho từ Excel (`tgs_internal_transfer`) | Mỗi phiếu Excel (Số phiếu + Kho nhập) thành 1 phiếu, kho xuất → shop nhập, duyệt xuất và nhận luôn. |
| POS → Đề nghị điều chuyển | **Chỉ là đơn đề nghị**, không đụng tồn. |

Mọi thao tác ghi đều đi qua `TGS_Global_Transfer` (tgs_shop_management). Sau mỗi lần ghi, nó bắn hook `tgs_global_transfer_changed($id)`.

## Kế toán

`tgs_accounting_bridge` gửi **mỗi đầu một chứng từ** `transfer`:

- Khoá: `btsoft:{blog}:gt-out:{id}` cho đầu xuất, `btsoft:{blog}:gt-in:{id}` cho đầu nhận.
- Chi nhánh và mã kho từng dòng lấy theo đầu tương ứng.
- Chỉ gửi số lượng (tiền 0).
- Gửi tức thời qua hook ở trên. Phiếu xoá, bỏ duyệt hoặc bị từ chối thì báo huỷ đầu tương ứng.

## Dữ liệu cũ

Chạy `tgs_shop_management/tools/migrate-transfer-to-global.php`. Mặc định là chạy thử; thêm `--apply` để ghi thật. Công cụ làm các việc sau:

- Cặp 12→13 / 14→15 trở thành 1 phiếu global.
- Phiếu chỉ có một đầu trở thành phiếu có đầu kia là ngoài hệ thống.
- Xoá mềm phiếu cũ (phiếu cha và phiếu con).
- Ảnh hưởng tồn của từng shop giữ nguyên.
