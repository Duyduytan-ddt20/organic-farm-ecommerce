# BÁO CÁO DỰ ÁN ORGANIC FARM E-COMMERCE

**Ngày cập nhật:** 28/11/2026  
**Nhóm:** Nhóm 9 - CĐ TH 23WEBB

## 1. Tổng quan

Organic Farm E-commerce là website thương mại điện tử theo mô hình một nông trại, hỗ trợ bán nông sản và quản lý thông tin sản phẩm theo từng lô. Hệ thống tập trung vào ba vấn đề: đặt hàng trực tuyến, quản lý hạn sử dụng/tồn kho theo FEFO và truy xuất nguồn gốc bằng mã QR.

## 2. Chức năng chính

- **Khách hàng:** tìm kiếm sản phẩm, xem trang chi tiết được tải động từ API, nhật ký canh tác và mã QR truy xuất. Thêm sản phẩm/cập nhật số lượng gửi AJAX kiểm tra tồn kho theo lô, có trạng thái chờ và thông báo kết quả.
- **Đặt hàng và giao hàng:** kiểm tra tồn khả dụng khi thao tác giỏ và kiểm tra lại trước khi tạo đơn, tính phí giao hàng theo địa chỉ, xác thực báo giá trước khi tạo đơn. Khóa idempotency giúp tránh tạo đơn trùng khi gửi lại yêu cầu.
- **Quản lý đơn:** nhân viên bán hàng hoặc quản trị viên xem đơn chờ và xác nhận đơn. Khi xác nhận, hệ thống phân bổ hàng theo FEFO (*First Expired, First Out*), ưu tiên lô có hạn sử dụng gần nhất và cập nhật tồn kho.
- **Quản lý lô:** nhân viên hoặc quản trị viên tìm lô theo mã, sản phẩm, ID, khu vực hoặc trạng thái; theo dõi hạn sử dụng, mã QR, nhật ký canh tác và các phiếu xuất hủy hàng hao hụt.
- **Truy xuất nguồn gốc:** khách quét QR để xem tên sản phẩm, mã lô, khu vực canh tác, ngày thu hoạch, hạn sử dụng, nhật ký và người phụ trách gần nhất.
- **Khiếu nại:** khách gửi yêu cầu từ đơn đã giao, chọn sản phẩm/lý do và bắt buộc đính kèm ảnh minh chứng hợp lệ.
- **Thanh toán:** giao diện checkout có MoMo Sandbox, COD và lựa chọn demo local không thu tiền thật. Backend có endpoint IPN cho MoMo/VNPay; VNPay chưa được hiển thị trong giao diện checkout hiện tại.

## 3. Công nghệ và kiến trúc

- **Frontend:** React, JavaScript, Vite, Tailwind CSS và React Router; giao tiếp với backend qua REST API.
- **Backend:** PHP 8.2, Laravel 12, REST API và Laravel Sanctum cho xác thực token.
- **Cơ sở dữ liệu:** MySQL/InnoDB cho dữ liệu ứng dụng; các bài kiểm thử backend chạy trong môi trường testing.
- **Tích hợp:** MoMo Sandbox và các endpoint VNPay cho thanh toán; Nominatim và OSRM trong luồng xác định tọa độ/tuyến đường giao hàng ở môi trường phát triển, kiểm thử.
- **Phân quyền:** các vai trò Customer, Staff, Sales và Admin; API áp dụng middleware xác thực, trạng thái tài khoản và quyền theo vai trò.

Luồng nghiệp vụ chính: khách chọn sản phẩm → hệ thống kiểm tra tồn và phí giao hàng → tạo đơn → nhân viên xác nhận hoặc nhà cung cấp xác nhận thanh toán qua IPN → hệ thống phân bổ lô theo FEFO và cập nhật tồn. Trang quay về sau thanh toán không được xem là bằng chứng thanh toán; với giao dịch thật, backend chỉ cập nhật trạng thái sau khi xác minh IPN.

## 4. Mô tả nghiệp vụ và cách xử lý

### 4.1. Xuất kho theo lô FEFO

Nghiệp vụ được tách vào `FefoAllocationService` thay vì thực hiện trực tiếp trong giao diện. Dịch vụ chỉ xét các lô đang khả dụng, còn tồn và chưa hết hạn; sau đó sắp xếp theo ngày hết hạn tăng dần. Với mỗi mặt hàng trong đơn, vòng lặp lấy lượng cần thiết từ lô gần hết hạn nhất rồi tiếp tục sang lô kế tiếp cho đến khi đáp ứng đủ số lượng. Mỗi lần lấy hàng sẽ tạo chi tiết phân bổ trong `order_details`, giảm `available_quantity` của lô và đóng lô nếu tồn kho về 0.

Ví dụ minh họa: đơn cần 10 kg cà chua; nếu lô A còn 3 kg và lô B còn đủ hàng thì hệ thống ghi nhận 3 kg từ lô A và 7 kg từ lô B. Nếu tổng tồn khả dụng không đủ, dịch vụ ném lỗi nghiệp vụ. Khi chạy trong transaction, các cập nhật thanh toán, đơn hàng và tồn kho được rollback nếu phân bổ thất bại.

FEFO chạy khi Sales xác nhận đơn đang chờ. Với IPN thật báo thanh toán thành công, backend cũng xác nhận đơn và kích hoạt FEFO tự động trong cùng transaction. Riêng thanh toán demo local chỉ ghi nhận payment thành công để trình diễn; đơn vẫn chờ Sales xác nhận trước khi trừ kho.

### 4.2. Nhật ký canh tác và mã QR truy xuất

Frontend sử dụng React, không dùng Blade. Trang chi tiết gọi API lấy dữ liệu sản phẩm, các lô khả dụng và nhật ký canh tác. Mã QR được hiển thị dạng SVG để giữ độ sắc nét; nội dung mã là URL của trang React `/truy-xuat/{qr_token}`. Ngoài ra, API QR theo mã lô trả SVG được tạo bởi `QrGeneratorService`. Khi quét mã, trang truy xuất dùng token để lấy thông tin lô và nhật ký canh tác.

### 4.3. API REST và xử lý lỗi

Backend cung cấp REST API JSON. Các resource sản phẩm, danh mục và lô hỗ trợ các phương thức CRUD chuẩn GET, POST, PUT, PATCH và DELETE; những nghiệp vụ như kiểm tra tồn giỏ hàng, tạo đơn, xác nhận đơn, thanh toán và khiếu nại dùng endpoint riêng theo hành động.

Laravel được cấu hình render exception của đường dẫn API dưới dạng JSON; lỗi xác thực trả HTTP 401 JSON và lỗi dữ liệu đầu vào trả lỗi validation. Frontend dùng Axios để đọc thông báo lỗi. Hiện cấu trúc JSON tuân theo loại lỗi Laravel (`message`, `errors`), chưa ép mọi loại lỗi về một envelope duy nhất như `{"status":"error","message":"..."}`.

### 4.4. AJAX và trạng thái chờ

Thao tác thêm sản phẩm hoặc đổi số lượng gọi API kiểm tra tồn mà không tải lại trang. Trong lúc chờ, giao diện khóa thao tác liên quan và thể hiện trạng thái đang xử lý; kết quả thành công hoặc thất bại được thông báo bằng toast. Checkout cũng hiển thị trạng thái chờ khi tính phí giao hàng và gửi đơn.

### 4.5. Xác thực và hết hạn phiên

Đây là ứng dụng React gọi Laravel REST API và dùng Laravel Sanctum token, không phải giao diện Blade dựa trên session CSRF. Axios interceptor bắt lỗi 401/419, xóa thông tin xác thực cục bộ và hiển thị hộp thoại yêu cầu đăng nhập lại, thay vì để người dùng gặp trang lỗi thô.

### 4.6. Tính phí giao hàng

Khi địa chỉ thay đổi, frontend gọi API tính phí. Backend chuẩn hóa địa chỉ, dùng Nominatim để tìm tọa độ và OSRM để lấy khoảng cách theo tuyến đường; phí được tính theo công thức `khoảng cách (km) × đơn giá (đ/km)`. Đơn giá hiện tại là 5.000 đồng/km. Báo giá được ký thành token, ràng buộc với khách hàng và địa chỉ, có thời hạn; khi tạo đơn backend kiểm tra lại token để ngăn sửa phí ở frontend. Nominatim/OSRM hiện dành cho local/testing, chưa phải cấu hình dịch vụ production.

### 4.7. Thanh toán trực tuyến và IPN

Khi tạo giao dịch MoMo/VNPay, backend lưu payment ở trạng thái `pending` và tạo URL thanh toán. Nhà cung cấp gọi các endpoint IPN công khai; backend kiểm tra chữ ký, mã tham chiếu, phương thức thanh toán và số tiền. Nếu callback hợp lệ và thành công, payment được cập nhật `succeeded`, lưu `paid_at`/mã giao dịch, sau đó đơn được xác nhận và FEFO chạy. Callback lặp không phân bổ tồn kho lần nữa.

Luồng IPN thật cần thông tin sandbox hợp lệ và URL HTTPS công khai để nhà cung cấp gọi được; chưa thể xem lựa chọn “Demo thanh toán thành công (local-only)” là giao dịch thật. Demo chỉ ghi payment thành công giả lập, không thu tiền và không tự trừ kho; Sales xác nhận đơn để chạy FEFO.

### 4.8. Khiếu nại và ảnh minh chứng

Form khiếu nại chỉ hiển thị với đơn đã giao. Backend kiểm tra quyền sở hữu đơn, sản phẩm thuộc đơn, số lượng khiếu nại không vượt số đã mua và ảnh minh chứng bắt buộc. Frontend và backend chỉ chấp nhận `.jpg`/`.png` có dung lượng nhỏ hơn 5 MiB; backend lưu ảnh vào storage và tạo bản ghi khiếu nại cùng tệp đính kèm trong database. Cloudinary chưa được tích hợp.

## 5. Dữ liệu và kiểm thử hiện tại

- Database local hiện có **34 lô** và **102 nhật ký canh tác mẫu** để trình diễn màn hình quản lý và truy xuất QR.
- Backend có kiểm thử cho xác thực/phân quyền, đặt hàng và FEFO, báo giá giao hàng, thanh toán/IPN, QR/nhật ký canh tác, khiếu nại và xuất hủy tồn kho.
- Lần kiểm thử có phạm vi đơn hàng/khiếu nại/thanh toán gần nhất: **22 tests passed, 239 assertions**; riêng `PaymentFlowTest`: **4 tests passed, 50 assertions**.
- Frontend build và lint các trang liên quan đến giỏ hàng, thanh toán và đơn hàng đã thành công. Chưa chạy lại lint toàn bộ frontend trong lần cập nhật báo cáo này.

## 6. Hạn chế và hướng phát triển

1. Kiểm thử giao dịch MoMo/VNPay Sandbox end-to-end bằng thông tin sandbox hợp lệ và URL IPN HTTPS công khai; không đưa khóa bí mật vào báo cáo hoặc mã nguồn.
2. Chuẩn hóa envelope lỗi JSON cho toàn bộ API và bổ sung kiểm thử giao diện cho checkout, quản lý lô, khiếu nại và trang truy xuất.
3. Tích hợp nhà cung cấp định tuyến production phù hợp; Nominatim/OSRM hiện chỉ dùng cho môi trường phát triển/kiểm thử.
4. Bổ sung màn hình/quy trình cho nhân viên xử lý, phản hồi và cập nhật trạng thái khiếu nại; hiện tại chức năng tập trung vào tiếp nhận yêu cầu và lưu minh chứng.
5. Khi triển khai thật, bổ sung giám sát, sao lưu dữ liệu, phân quyền vận hành và quy trình nhập lô có nhật ký thực tế.

## 7. Bài trình bày gợi ý

> Đề tài của nhóm em là Organic Farm E-commerce, một website bán nông sản theo mô hình nông trại, xây dựng bằng React và Laravel REST API. Hệ thống quản lý tồn theo từng lô và áp dụng FEFO để ưu tiên xuất lô gần hết hạn. Khách hàng có thể xem nhật ký canh tác, quét QR để truy xuất nguồn gốc, đặt hàng, tính phí giao hàng theo địa chỉ và gửi khiếu nại kèm ảnh minh chứng cho đơn đã giao. Với thanh toán thật, backend xác minh IPN từ cổng thanh toán trước khi cập nhật payment và tự động xác nhận đơn, chạy FEFO. Do môi trường demo chưa dùng được tài khoản MoMo UAT, nhóm có lựa chọn thanh toán mô phỏng local: giao dịch được ghi nhận là thành công nhưng đơn chờ Sales xác nhận, không thu tiền thật. Hệ thống có kiểm thử backend cho các luồng đơn hàng, FEFO, thanh toán và khiếu nại; các bước tiếp theo là kiểm thử sandbox end-to-end và hoàn thiện tích hợp môi trường production.
