import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../context/useAuth';
import { getDisplayName } from '../utils/getDisplayName';

const ROLE_CARDS = [
  {
    role: 'Customer',
    title: 'Khách hàng',
    email: 'customer@organicfarm.vn',
    description: 'Theo dõi đơn đã giao và gửi khiếu nại kèm ảnh minh chứng.',
  },
  {
    role: 'Staff',
    title: 'Nhân viên nông trại',
    email: 'staff@organicfarm.vn',
    description: 'Theo dõi lô hàng, hạn sử dụng và ghi nhận hao hụt.',
  },
  {
    role: 'Sales',
    title: 'Nhân viên bán hàng',
    email: 'sales@organicfarm.vn',
    description: 'Xác nhận đơn và chăm sóc khách hàng.',
  },
  {
    role: 'Admin',
    title: 'Quản trị viên',
    email: 'admin@organicfarm.vn',
    description: 'Quản lý các khu vực của hệ thống.',
  },
];

export default function HomePage() {
  const { user } = useAuth();
  const location = useLocation();
  const currentRole = user?.role?.role_name;
  const canOpenDemoOrder = currentRole === 'Admin' || (
    currentRole === 'Customer' && user.email === 'customer@organicfarm.vn'
  );

  return (
    <div className="space-y-6">
      <section className="rounded-2xl bg-white p-6 shadow-sm sm:p-10">
        <p className="font-semibold text-emerald-700">ĐỒ ÁN NHÓM 9</p>
        <h2 className="mt-2 text-3xl font-bold text-slate-900">
          Website thương mại điện tử nông sản sạch
        </h2>
        <p className="mt-3 text-slate-600">
          {user
            ? `Xin chào ${getDisplayName(user)}. Bạn đang đăng nhập với vai trò ${currentRole}.`
            : 'Trang chủ demo để đăng nhập, mở đơn giao mẫu và thử gửi khiếu nại sau bán hàng.'}
        </p>
      </section>
      {location.state?.registered && (
        <p className="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          Đăng ký thành công. Tài khoản mới chưa có đơn giao; khiếu nại chỉ gửi được từ đơn đã giao.
        </p>
      )}

      <section className="grid gap-4 sm:grid-cols-2" aria-label="Khu vực theo vai trò">
        {ROLE_CARDS.map((card) => {
          const isCurrentRole = currentRole === card.role;
          const canOpenSeededOrder = card.role === 'Customer' && canOpenDemoOrder;

          return (
            <article key={card.role} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
              <div className="flex items-center justify-between gap-3">
                <h3 className="text-lg font-semibold">{card.title}</h3>
                <span className={`rounded-full px-3 py-1 text-sm ${isCurrentRole ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'}`}>
                  {isCurrentRole ? 'Đang đăng nhập' : card.role}
                </span>
              </div>
              <p className="mt-2 text-slate-600">{card.description}</p>
              {canOpenSeededOrder ? (
                <Link
                  className="mt-4 inline-flex rounded-xl bg-emerald-700 px-4 py-2 font-semibold text-white"
                  to="/orders/preview"
                >
                  Mở đơn đã giao
                </Link>
              ) : isCurrentRole && card.role === 'Customer' ? (
                <p className="mt-4 text-sm text-slate-500">Tài khoản này chưa có đơn hàng đã giao.</p>
              ) : isCurrentRole ? (
                <p className="mt-4 text-sm text-slate-500">Khu vực nghiệp vụ này chưa có màn hình trong frontend.</p>
              ) : (
                <Link
                  className="mt-4 inline-flex font-semibold text-emerald-700 underline"
                  to="/login"
                  state={{ email: card.email }}
                >
                  {user ? `Đổi sang ${card.role}` : `Đăng nhập ${card.role}`}
                </Link>
              )}
            </article>
          );
        })}
      </section>
    </div>
  );
}
