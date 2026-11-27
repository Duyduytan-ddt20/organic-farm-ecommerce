import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/useAuth.js';

export default function Header({ subtitle = 'KHÁCH HÀNG', title = 'Organic Farm' }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const role = user?.role?.role_name;

  async function handleLogout() {
    await logout();
    navigate('/');
  }

  return (
    <header className="bg-emerald-800 px-4 py-5 text-white shadow-sm">
      <div className="mx-auto flex max-w-7xl flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <Link to="/">
          <p className="text-xs font-semibold tracking-wider text-emerald-200 uppercase">
            ORGANIC FARM / {user ? role?.toUpperCase() : subtitle}
          </p>
          <h1 className="mt-1 text-2xl font-bold">{title}</h1>
        </Link>

        <nav className="flex flex-wrap items-center gap-2" aria-label="Điều hướng tài khoản">
          <Link to="/" className="inline-flex min-h-10 items-center rounded-xl px-3 text-sm font-medium hover:bg-emerald-700">
            Trang chủ
          </Link>
          {user ? (
            <>
              {(role === 'Customer' || role === 'Admin') ? (
                <Link to="/mua-sam" className="inline-flex min-h-10 items-center rounded-xl px-3 text-sm font-semibold hover:bg-emerald-700">
                  Mua hàng
                </Link>
              ) : null}
              {(role === 'Sales' || role === 'Admin') ? (
                <Link to="/don-hang" className="inline-flex min-h-10 items-center rounded-xl px-3 text-sm font-semibold hover:bg-emerald-700">
                  Đơn hàng
                </Link>
              ) : null}
              {(role === 'Staff' || role === 'Admin') ? (
                <Link to="/quan-ly" className="inline-flex min-h-10 items-center rounded-xl px-3 text-sm font-semibold hover:bg-emerald-700">
                  Quản lý lô
                </Link>
              ) : null}
              <span className="px-2 text-sm">{user.full_name}</span>
              <span className="rounded-full bg-emerald-700 px-3 py-1 text-xs font-semibold">{role}</span>
              <button
                type="button"
                onClick={handleLogout}
                className="min-h-10 rounded-xl bg-white px-4 text-sm font-semibold text-emerald-900 hover:bg-emerald-50"
              >
                Đăng xuất
              </button>
            </>
          ) : (
            <>
              <Link to="/dang-nhap" className="inline-flex min-h-10 items-center rounded-xl px-4 text-sm font-semibold hover:bg-emerald-700">
                Đăng nhập
              </Link>
              <Link to="/dang-ky" className="inline-flex min-h-10 items-center rounded-xl bg-white px-4 text-sm font-semibold text-emerald-900">
                Đăng ký
              </Link>
            </>
          )}
        </nav>
      </div>
    </header>
  );
}
