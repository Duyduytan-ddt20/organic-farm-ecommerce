import { Link } from 'react-router-dom';
import { getDisplayName } from '../utils/getDisplayName';

export default function Header({
  subtitle = 'KHÁCH HÀNG',
  title = 'Organic Farm',
  user,
  onLogout,
}) {
  return (
    <header className="bg-emerald-800 text-white px-4 py-5 shadow-sm">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4">
        <Link className="rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4" to="/">
          <p className="text-xs font-semibold tracking-wider uppercase text-emerald-200">
            ORGANIC FARM / {subtitle}
          </p>
          <h1 className="text-2xl font-bold mt-1">{title}</h1>
        </Link>
        <nav className="flex items-center gap-3" aria-label="Điều hướng tài khoản">
          <Link className="rounded-lg px-3 py-2 text-sm font-medium hover:bg-emerald-700" to="/">
            Trang chủ
          </Link>
          {user ? (
            <>
              <span className="hidden text-sm sm:inline">{getDisplayName(user)}</span>
              <span className="rounded-full bg-emerald-700 px-3 py-1 text-xs font-semibold">
                {user.role?.role_name}
              </span>
              <Link className="rounded-lg px-3 py-2 text-sm font-medium hover:bg-emerald-700" to="/login">
                Đổi tài khoản
              </Link>
              <button
                className="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-50"
                type="button"
                onClick={onLogout}
              >
                Đăng xuất
              </button>
            </>
          ) : (
            <Link className="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-50" to="/login">
              Đăng nhập
            </Link>
          )}
        </nav>
      </div>
    </header>
  );
}