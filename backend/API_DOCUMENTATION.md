# Tài liệu API Organic Farm

## Truy cập tài liệu tương tác

Khởi động backend từ thư mục `backend`:

```powershell
php artisan migrate
php artisan serve
```

- Scalar UI: <http://127.0.0.1:8000/docs/api>
- OpenAPI JSON: <http://127.0.0.1:8000/docs/api.json>
- API base URL: `http://127.0.0.1:8000/api/v1`

Scalar lấy mô tả endpoint trực tiếp từ Laravel. Trong Scalar, chọn **Authorize** và nhập token theo dạng `Bearer <token>` để thử các route cần đăng nhập.

> Nếu chạy backend bằng host hoặc port khác, cập nhật `servers` trong `config/scramble.php` cho khớp. OpenAPI dùng phần path `/v1`; server URL đã bao gồm tiền tố `/api`.
>
> Nếu `php artisan scramble:analyze` báo thiếu bảng, hãy chạy migration trên database phát triển đã cấu hình trước khi phân tích/xuất schema.

## Quy ước chung

- Request/response dùng JSON; gửi `Accept: application/json`.
- Route cần đăng nhập dùng `Authorization: Bearer <token>` do Sanctum cấp.
- Upload ảnh dùng `multipart/form-data`, trường file tên `image`; loại hỗ trợ JPG, JPEG, PNG, WEBP, tối đa 5 MB.
- Ngày dùng định dạng ISO, ví dụ `2026-11-27`; ngày giờ có thể gửi `2026-11-27T15:00:00+07:00`.
- Validation lỗi trả HTTP `422` với `message` và `errors`.
- Các mã lỗi thường gặp: `401` chưa đăng nhập/token hết hạn, `403` sai quyền hoặc tài khoản bị khóa, `404` không tìm thấy tài nguyên, `409` khóa idempotency bị dùng lại với dữ liệu khác, `503` dịch vụ tạm thời không sẵn sàng.

## Phân quyền hiện tại

| Nhóm route | Quyền |
|---|---|
| Health, đăng ký/đăng nhập, tra cứu QR, IPN thanh toán | Công khai; IPN phải qua xác minh chữ ký nhà cung cấp |
| `categories`, `products`, `batches` và các route con như `expiring-soon` | Hiện không nằm trong middleware đăng nhập |
| `auth/me`, `auth/logout` | Tài khoản đang hoạt động |
| `customer/*`, shipping, tạo/xem thanh toán | `Customer` hoặc `Admin` |
| `sales/*` | `Sales` hoặc `Admin` |
| QR nội bộ, nhật ký canh tác, xuất hủy | `Staff` hoặc `Admin` |
| `admin/dashboard` | `Admin` |

**Lưu ý bảo mật:** các route CRUD danh mục, sản phẩm và lô hàng hiện được khai báo ngoài nhóm `auth:sanctum`; cần thêm middleware phù hợp trước khi triển khai công khai.

## Sức khỏe và xác thực

| Method | Endpoint | Quyền | Mục đích |
|---|---|---|---|
| GET | `/health` | Công khai | Kiểm tra backend |
| POST | `/auth/register` | Công khai, giới hạn 10 lần/phút | Tạo tài khoản khách hàng |
| POST | `/auth/login` | Công khai, giới hạn 10 lần/phút | Đăng nhập và nhận token |
| GET | `/auth/me` | Đã đăng nhập | Lấy thông tin tài khoản hiện tại |
| POST | `/auth/logout` | Đã đăng nhập | Thu hồi token hiện tại |

Đăng ký yêu cầu `full_name`, `email`, `password`, `password_confirmation`; `phone` không bắt buộc. Mật khẩu tối thiểu 8 ký tự.

```json
{
  "email": "customer@example.com",
  "password": "your-password"
}
```

Đăng nhập thành công trả `data.token`, `data.token_type` (`Bearer`) và `data.user`.

## Danh mục, sản phẩm và lô hàng

### Danh mục

| Method | Endpoint | Mục đích |
|---|---|---|
| GET | `/categories` | Danh sách danh mục và số sản phẩm |
| POST | `/categories` | Tạo danh mục |
| GET | `/categories/{id}` | Chi tiết danh mục kèm sản phẩm |
| PUT/PATCH | `/categories/{id}` | Cập nhật danh mục |
| DELETE | `/categories/{id}` | Xóa danh mục |

**Vấn đề cần sửa trước khi demo ghi dữ liệu:** controller đang nhận `ten_danh_muc`, trong khi migration và model dùng cột `name`. Vì vậy POST/PUT có thể lỗi khi truy vấn cơ sở dữ liệu. GET vẫn dùng được.

### Sản phẩm

| Method | Endpoint | Mục đích |
|---|---|---|
| GET | `/products?category_id={id}&search={text}` | Danh sách; bộ lọc tùy chọn theo danh mục/tên |
| POST | `/products` | Tạo sản phẩm |
| GET | `/products/{id}` | Chi tiết kèm danh mục và lô còn hàng |
| PUT/PATCH | `/products/{id}` | Cập nhật thông tin |
| PATCH | `/products/{id}/status` | Bật/tắt kinh doanh |
| DELETE | `/products/{id}` | Xóa sản phẩm |

Ví dụ tạo sản phẩm:

```json
{
  "category_id": 1,
  "name": "Cà chua hữu cơ",
  "description": "Cà chua thu hoạch tại vườn",
  "base_price": 38000,
  "unit": "kg",
  "is_active": true
}
```

`slug` có thể bỏ trống để backend tự tạo. `GET /products` trả thêm `tong_ton_kha_dung`.

### Lô hàng

| Method | Endpoint | Mục đích |
|---|---|---|
| GET | `/batches?product_id={id}&status={status}` | Danh sách lô, sắp theo hạn sử dụng |
| POST | `/batches` | Nhập lô mới |
| GET | `/batches/{id}` | Chi tiết lô |
| PUT/PATCH | `/batches/{id}` | Cập nhật lô |
| DELETE | `/batches/{id}` | Xóa lô nếu không bị dữ liệu liên quan tham chiếu |
| GET | `/batches/expiring-soon?days=2` | Lô sắp hết hạn |
| PATCH | `/batches/{id}/discount` | Hiện chỉ trả thông báo lô không có giảm giá riêng |

Ví dụ nhập lô:

```json
{
  "product_id": 1,
  "batch_code": "CA-2026-001",
  "growing_location": "Nhà màng A",
  "harvested_at": "2026-11-27",
  "expires_at": "2026-12-05 23:59:59",
  "received_quantity": 20
}
```

Lô mới có trạng thái `growing`; số lượng khả dụng ban đầu bằng số lượng nhập. Khi chuẩn bị bán, trạng thái cần là `available`.

## Canh tác, xuất hủy và truy xuất

| Method | Endpoint | Quyền | Mục đích |
|---|---|---|---|
| GET | `/batches/{batch}/farming-logs` | Staff/Admin | Xem nhật ký canh tác |
| POST | `/batches/{batch}/farming-logs` | Staff/Admin | Ghi nhật ký |
| GET | `/batches/{batch}/disposals` | Staff/Admin | Xem lịch sử xuất hủy |
| POST | `/batches/{batch}/disposals` | Staff/Admin | Xuất hủy, cập nhật tồn trong transaction |
| GET | `/batches/{batch}/qr` | Staff/Admin | Lấy ảnh QR dạng SVG |
| GET | `/trace/{qr_token}` | Công khai | Tra cứu nguồn gốc từ mã QR |

Nhật ký canh tác nhận `activity` (`Gieo trồng`, `Chăm sóc`, `Thu hoạch`), `performed_at`, `description` tùy chọn và `image` tùy chọn.

Xuất hủy nhận `quantity` (lớn hơn 0, tối đa 2 chữ số thập phân), `reason`, `note`, `disposed_at`, `image`. Không thể xuất quá tồn khả dụng hoặc xuất hủy lô đã đóng.

## Giao hàng và đơn hàng

| Method | Endpoint | Quyền | Mục đích |
|---|---|---|---|
| POST | `/shipping/calculate` | Customer/Admin | Tính phí giao hàng và cấp quote token |
| GET | `/customer/orders` | Customer/Admin | Danh sách đơn của khách hiện tại |
| POST | `/customer/orders` | Customer/Admin | Tạo đơn chờ xử lý |
| GET | `/sales/orders` | Sales/Admin | Danh sách đơn cần xử lý |
| POST | `/sales/orders/{order}/confirm` | Sales/Admin | Xác nhận đơn và phân bổ kho theo FEFO |

Tính phí giao hàng nhận:

```json
{
  "delivery_address": "Địa chỉ giao hàng đầy đủ"
}
```

Response gồm `distance_km`, `shipping_rate`, `shipping_fee`, `quote_token`. Quote gắn với địa chỉ và tài khoản; gửi lại nguyên văn khi checkout.

Checkout bắt buộc header `Idempotency-Key` (16–255 ký tự; chỉ gồm chữ, số, `.`, `_`, `:`, `-`) và body:

```json
{
  "recipient_name": "Nguyễn Văn A",
  "recipient_phone": "0900000000",
  "delivery_address": "Địa chỉ giao hàng đầy đủ",
  "quote_token": "token-nhan-tu-api-tinh-phi",
  "items": [
    { "product_id": 1, "quantity": 2 }
  ]
}
```

Đơn mới ở trạng thái `pending`. Sales xác nhận đơn sẽ trừ kho theo hạn sử dụng gần nhất trước (FEFO). Gửi lại cùng `Idempotency-Key` và cùng nội dung sẽ phát lại kết quả; dùng cùng khóa với nội dung khác trả `409`.

## Thanh toán

| Method | Endpoint | Quyền | Mục đích |
|---|---|---|---|
| POST | `/payments/vnpay/create` | Customer/Admin | Tạo URL thanh toán VNPay sandbox |
| POST | `/payments/momo/create` | Customer/Admin | Tạo URL thanh toán MoMo sandbox |
| GET | `/payments/{paymentReference}` | Customer/Admin, chỉ chủ đơn | Xem trạng thái thanh toán |
| POST | `/payments/vnpay-ipn` | Nhà cung cấp, công khai | Nhận IPN VNPay; chữ ký được xác minh |
| POST | `/payments/momo-ipn` | Nhà cung cấp, công khai | Nhận IPN MoMo; chữ ký được xác minh |

Tạo thanh toán nhận `{ "order_id": 123 }`; response chứa `payment_reference`, `payment_url`, `amount`, `method`. IPN hợp lệ cập nhật thanh toán; nếu đơn còn `pending`, backend xác nhận đơn và phân bổ kho FEFO. Không tự xác nhận thanh toán dựa trên trang return của trình duyệt.

## Dashboard theo vai trò

| Method | Endpoint | Quyền |
|---|---|---|
| GET | `/customer/dashboard` | Customer/Admin |
| GET | `/staff/dashboard` | Staff/Admin |
| GET | `/sales/dashboard` | Sales/Admin |
| GET | `/admin/dashboard` | Admin |

## Kiểm tra nhanh bằng curl

```bash
curl -H "Accept: application/json" \
  http://127.0.0.1:8000/api/v1/health
```

```bash
curl -H "Accept: application/json" \
  -H "Authorization: Bearer <token>" \
  http://127.0.0.1:8000/api/v1/auth/me
```

Đối với các body mẫu, dùng `Content-Type: application/json`. Với ảnh nhật ký hoặc xuất hủy, gửi `multipart/form-data`.
