import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../context/useAuth.js';
import { getDisplayName } from '../utils/getDisplayName';

const AREAS = [
  {
    role: 'Customer',
    title: 'Khách hàng',
    email: 'customer@organicfarm.vn',
    text: 'Mua nông sản trực tiếp và theo dõi đơn hàng.',
  },
  {
    role: 'Staff',
    title: 'Nhân viên nông trại',
    email: 'staff@organicfarm.vn',
    text: 'Theo dõi lô hàng, hạn sử dụng và ghi nhận hao hụt.',
  },
  {
    role: 'Sales',
    title: 'Nhân viên bán hàng',
    email: 'sales@organicfarm.vn',
    text: 'Xác nhận đơn và chăm sóc khách hàng.',
  },
  {
    role: 'Admin',
    title: 'Quản trị viên',
    email: 'admin@organicfarm.vn',
    text: 'Truy cập các khu vực quản lý của hệ thống.',
  },
];

export default function HomePage() {
  const { user } = useAuth();
  const location = useLocation();
  const roleName = user?.role?.role_name;
  const canOpenDemoOrder = roleName === 'Admin'
    || (roleName === 'Customer' && user.email === 'customer@organicfarm.vn');

  return (
    <div className="space-y-6">
      {location.state?.registered ? (
        <p className="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          Đăng ký thành công. Tài khoản mới chưa có đơn hàng đã giao để gửi khiếu nại.
        </p>
      ) : null}

      <section className="rounded-2xl bg-white p-5 shadow-sm sm:p-8">
        <p className="text-sm font-semibold text-emerald-700">ĐỒ ÁN NHÓM 9</p>
        <h2 className="mt-2 text-2xl font-bold text-slate-900 sm:text-3xl">
          Website thương mại điện tử nông sản sạch
        </h2>
        <p className="mt-3 max-w-2xl leading-7 text-slate-600">
          {user
            ? `Xin chào ${getDisplayName(user)}. Bạn đang đăng nhập với vai trò ${roleName}.`
            : 'Đăng ký tài khoản khách hàng hoặc đăng nhập để mua nông sản và sử dụng các khu vực theo vai trò.'}
        </p>

        {user ? (
          <div className="mt-6 flex flex-wrap gap-3">
            {(roleName === 'Customer' || roleName === 'Admin') ? (
              <Link to="/mua-sam" className="inline-flex min-h-12 items-center rounded-xl bg-emerald-700 px-5 font-semibold text-white">
                Mua nông sản
              </Link>
            ) : null}
            {(roleName === 'Staff' || roleName === 'Admin') ? (
              <Link to="/quan-ly" className="inline-flex min-h-12 items-center rounded-xl bg-slate-700 px-5 font-semibold text-white">
                Quản lý lô hàng
              </Link>
            ) : null}
            {(roleName === 'Sales' || roleName === 'Admin') ? (
              <Link to="/don-hang" className="inline-flex min-h-12 items-center rounded-xl bg-slate-700 px-5 font-semibold text-white">
                Quản lý đơn hàng
              </Link>
            ) : null}
            {canOpenDemoOrder ? (
              <Link to="/orders/preview" className="inline-flex min-h-12 items-center rounded-xl border border-emerald-700 px-5 font-semibold text-emerald-800">
                Mở đơn đã giao mẫu
              </Link>
            ) : null}
          </div>
        ) : (
          <div className="mt-6 flex flex-wrap gap-3">
            <Link to="/dang-nhap" className="inline-flex min-h-12 items-center rounded-xl bg-emerald-700 px-5 font-semibold text-white">
              Đăng nhập
            </Link>
            <Link to="/dang-ky" className="inline-flex min-h-12 items-center rounded-xl bg-slate-700 px-5 font-semibold text-white">
              Đăng ký
            </Link>
          </div>
        )}
      </section>

      <section className="grid gap-4 sm:grid-cols-2" aria-label="Khu vực theo vai trò">
        {AREAS.map((area) => {
          const active = roleName === 'Admin' || roleName === area.role;
          return (
            <article key={area.role} className="rounded-2xl bg-white p-5 shadow-sm">
              <div className="flex items-center justify-between gap-3">
                <h3 className="text-lg font-semibold">{area.title}</h3>
                <span className={`rounded-full px-3 py-1 text-xs font-semibold ${
                  active && user ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500'
                }`}>
                  {active && user ? 'Được truy cập' : area.role}
                </span>
              </div>
              <p className="mt-2 leading-7 text-slate-600">{area.text}</p>
              {!user ? (
                <Link
                  className="mt-4 inline-block font-semibold text-emerald-700 underline"
                  to="/dang-nhap"
                  state={{ email: area.email }}
                >
                  Đăng nhập {area.title.toLowerCase()}
                </Link>
              ) : null}
            </article>
          );
        })}
      </section>
    </div>
  );
}
