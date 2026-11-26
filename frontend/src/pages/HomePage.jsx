import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const areas = [
  {
    role: 'Customer',
    title: 'Khách hàng',
    text: 'Đăng ký, đăng nhập và mua nông sản trực tiếp từ nông trại.',
  },
  {
    role: 'Staff',
    title: 'Nhân viên nông trại',
    text: 'Theo dõi lô hàng, hạn sử dụng và ghi nhận hao hụt.',
  },
  {
    role: 'Sales',
    title: 'Nhân viên bán hàng',
    text: 'Xác nhận đơn và chăm sóc khách hàng.',
  },
  {
    role: 'Admin',
    title: 'Quản trị viên',
    text: 'Truy cập toàn bộ khu vực Customer, Staff và Sales.',
  },
];

export default function HomePage() {
  const { user } = useAuth();
  const roleName = user?.role?.role_name;

  return (
    <>
      <section className="rounded-2xl bg-white p-5 shadow-sm sm:p-8">
        <p className="text-sm font-semibold text-emerald-700">ĐỒ ÁN NHÓM 9</p>
        <h2 className="mt-2 text-2xl font-bold text-slate-900 sm:text-3xl">
          Website thương mại điện tử nông sản sạch
        </h2>
        <p className="mt-3 max-w-2xl leading-7 text-slate-600">
          {user
            ? `Xin chào ${user.full_name}. Bạn đang đăng nhập với vai trò ${roleName}.`
            : 'Đăng ký tài khoản khách hàng hoặc đăng nhập để dùng các khu vực theo vai trò.'}
        </p>
        <div className="mt-6 flex flex-col gap-3 sm:flex-row">
          {user ? (
            <>
              {(roleName === 'Customer' || roleName === 'Admin') ? (
                <Link to="/mua-sam" className="inline-flex min-h-12 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">
                  Mua nông sản
                </Link>
              ) : null}
              {(roleName === 'Staff' || roleName === 'Admin') ? (
                <Link to="/quan-ly" className="inline-flex min-h-12 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">
                  Quản lý QR và lô hàng
                </Link>
              ) : null}
              {(roleName === 'Sales' || roleName === 'Admin') ? (
                <Link to="/don-hang" className="inline-flex min-h-12 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">
                  Xác nhận đơn hàng
                </Link>
              ) : null}
            </>
          ) : (
            <>
              <Link
                to="/dang-nhap"
                className="inline-flex min-h-12 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white"
              >
                Đăng nhập
              </Link>
              <Link
                to="/dang-ky"
                className="inline-flex min-h-12 items-center justify-center rounded-xl bg-slate-600 px-5 py-3 font-semibold text-white"
              >
                Đăng ký
              </Link>
            </>
          )}
        </div>
      </section>

      <section className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
        {areas.map((area) => {
          const active = roleName === 'Admin' || roleName === area.role;
          return (
            <article key={area.role} className="rounded-2xl bg-white p-5 shadow-sm">
              <div className="flex items-center justify-between gap-3">
                <h3 className="text-lg font-semibold">{area.title}</h3>
                <span
                  className={`rounded-full px-3 py-1 text-xs font-semibold ${
                    active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500'
                  }`}
                >
                  {active && user ? 'Được truy cập' : area.role}
                </span>
              </div>
              <p className="mt-2 leading-7 text-slate-600">{area.text}</p>
            </article>
          );
        })}
      </section>
    </>
  );
}
