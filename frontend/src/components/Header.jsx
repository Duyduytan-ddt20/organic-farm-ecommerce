import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function Header({ subtitle = 'KHÁCH HÀNG', title = 'Organic Farm' }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  async function handleLogout() {
    await logout();
    navigate('/');
  }

  return (
    <header className="bg-emerald-800 px-4 py-5 text-white shadow-sm">
      <div className="mx-auto flex max-w-7xl flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <Link to="/">
          <p className="text-xs font-semibold tracking-wider text-emerald-200 uppercase">
            ORGANIC FARM / {subtitle}
          </p>
          <h1 className="mt-1 text-2xl font-bold">{title}</h1>
        </Link>

        <nav className="flex flex-wrap items-center gap-2">
          {user ? (
            <>
              {(user.role?.role_name === 'Staff' || user.role?.role_name === 'Admin') ? (
                <Link
                  to="/quan-ly"
                  className="inline-flex min-h-10 items-center rounded-xl px-4 text-sm font-semibold"
                >
                  Quản lý lô
                </Link>
              ) : null}
              <span className="text-sm">{user.full_name}</span>
              <span className="rounded-full bg-emerald-700 px-3 py-1 text-xs font-semibold">
                {user.role?.role_name}
              </span>
              <button
                type="button"
                onClick={handleLogout}
                className="min-h-10 rounded-xl bg-white px-4 text-sm font-semibold text-emerald-900"
              >
                Đăng xuất
              </button>
            </>
          ) : (
            <>
              <Link
                to="/dang-nhap"
                className="inline-flex min-h-10 items-center rounded-xl px-4 text-sm font-semibold"
              >
                Đăng nhập
              </Link>
              <Link
                to="/dang-ky"
                className="inline-flex min-h-10 items-center rounded-xl bg-white px-4 text-sm font-semibold text-emerald-900"
              >
                Đăng ký
              </Link>
            </>
          )}
        </nav>
      </div>
    </header>
  );
}
