# Website Thương Mại Điện Tử Nông Sản Sạch (Organic Farm Ecommerce)

Hệ thống thương mại điện tử mô hình **Single Farm** phục vụ quản lý canh tác, tồn kho theo lô hạn sử dụng (**FEFO**), xuất hủy hao hụt, gói mua định kỳ và truy xuất nguồn gốc qua mã **QR**.

## Thành viên thực hiện (Nhóm 9 - CĐ TH 23WEBB)
1. **Trần Duy Tân** (0306231064)
2. **Nguyễn Lâm Nhật Tiến** (0306231074)
3. **Nguyễn Đăng Khoa** (0306231034)
4. **Trần Quang Danh**
5. **Nguyễn Thành An**

## Công nghệ sử dụng
- **Backend (`backend/`):** Laravel 11 RESTful API, MySQL (InnoDB, utf8mb4), Laravel Storage
- **Frontend (`frontend/`):** React JS (Vite), Tailwind CSS (Mobile-first 390px – Desktop 1280px)

## Quy ước Git Flow & Commit    
- `main`: Nhánh ổn định phục vụ nghiệm thu (khóa push trực tiếp, yêu cầu Pull Request + 1 Approve).
- `develop`: Nhánh tích hợp chính của nhóm.
- `feature/<ten-chuc-nang>`: Nhánh phát triển chức năng (VD: `feature/init-boilerplate`, `feature/fefo-service`).
- `fix/<ten-loi>`: Nhánh sửa lỗi.
- **Conventional Commits:** `feat:`, `fix:`, `docs:`, `chore:`.
New-Item -ItemType Directory -Force -Path docs | Out-Null
Set-Content -Path docs\git-flow-note.md -Value "# Quy trình Git Flow Nhóm 9`n- Nhánh phát triển: feature/setup-docs -> develop"
