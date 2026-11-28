import { useEffect, useState } from 'react';
import { CalendarDays, MapPin, PackageCheck, ShieldCheck, Sprout, UserRound } from 'lucide-react';
import { useParams } from 'react-router-dom';
import axiosClient from '../api/axiosClient';

function formatDate(value) {
  if (!value) return 'Chưa cập nhật';
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;

  return new Intl.DateTimeFormat('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date);
}

export default function TracePage() {
  const { qrToken } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    async function loadTrace() {
      setLoading(true);
      setError('');

      try {
        const response = await axiosClient.get(`/trace/${encodeURIComponent(qrToken)}`);
        if (active) setData(response.data);
      } catch (requestError) {
        if (!active) return;
        setError(
          requestError.response?.status === 404
            ? 'Không tìm thấy thông tin truy xuất. Mã QR có thể không còn hợp lệ.'
            : 'Không thể tải thông tin truy xuất. Vui lòng kiểm tra kết nối và thử lại.',
        );
      } finally {
        if (active) setLoading(false);
      }
    }

    loadTrace();
    return () => {
      active = false;
    };
  }, [qrToken]);

  if (loading) {
    return (
      <section className="mx-auto max-w-3xl rounded-2xl bg-white p-8 text-center shadow-sm">
        <div className="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-emerald-100 border-t-emerald-700" />
        <p className="mt-4 font-semibold text-slate-700">Đang tải thông tin truy xuất...</p>
      </section>
    );
  }

  if (error) {
    return (
      <section className="mx-auto max-w-3xl rounded-2xl border border-red-100 bg-white p-6 text-center shadow-sm sm:p-8">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-50 text-red-600">
          <PackageCheck size={28} />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-slate-900">Chưa thể truy xuất</h1>
        <p className="mt-2 text-slate-600">{error}</p>
      </section>
    );
  }

  const batch = data?.batch;
  const product = data?.product;
  const logs = data?.farming_logs ?? [];

  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <section className="overflow-hidden rounded-2xl bg-white shadow-sm">
        <div className="bg-emerald-800 px-5 py-6 text-white sm:px-8">
          <div className="flex items-start justify-between gap-4">
            <div>
              <p className="text-xs font-semibold tracking-widest text-emerald-200 uppercase">
                Kết quả truy xuất
              </p>
              <h1 className="mt-2 text-2xl font-bold sm:text-3xl">
                {product?.name || 'Nông sản organic'}
              </h1>
            </div>
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/15">
              <Sprout size={26} />
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-px bg-slate-100 sm:grid-cols-2">
          <div className="bg-white p-5 sm:p-6">
            <p className="text-sm text-slate-500">Mã lô</p>
            <p className="mt-1 text-lg font-bold text-slate-900">{batch?.batch_code}</p>
          </div>
          <div className="bg-white p-5 sm:p-6">
            <p className="text-sm text-slate-500">Đơn vị</p>
            <p className="mt-1 text-lg font-bold text-slate-900">{product?.unit || 'Chưa cập nhật'}</p>
          </div>
          <div className="bg-white p-5 sm:p-6">
            <p className="flex items-center gap-2 text-sm text-slate-500">
              <CalendarDays size={16} />
              Ngày thu hoạch
            </p>
            <p className="mt-1 font-semibold text-slate-900">{formatDate(batch?.harvested_at)}</p>
          </div>
          <div className="bg-white p-5 sm:p-6">
            <p className="flex items-center gap-2 text-sm text-slate-500">
              <CalendarDays size={16} />
              Hạn sử dụng
            </p>
            <p className="mt-1 font-semibold text-slate-900">{formatDate(batch?.expires_at)}</p>
          </div>
        </div>

        <div className="border-t border-slate-100 p-5 sm:p-6">
          <p className="flex items-center gap-2 text-sm text-slate-500">
            <MapPin size={16} />
            Khu vực canh tác
          </p>
          <p className="mt-1 font-semibold text-slate-900">{batch?.growing_location || 'Chưa cập nhật'}</p>
        </div>
      </section>

      <section className="rounded-2xl bg-white p-5 shadow-sm sm:p-6">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 text-emerald-800">
            <UserRound size={20} />
          </div>
          <div>
            <p className="text-sm text-slate-500">Người phụ trách gần nhất</p>
            <p className="font-bold text-slate-900">
              {data?.responsible_staff?.full_name || 'Chưa có nhật ký canh tác'}
            </p>
          </div>
        </div>
      </section>

      <section className="rounded-2xl bg-white p-5 shadow-sm sm:p-6">
        <h2 className="text-lg font-bold text-slate-900">Nhật ký canh tác</h2>
        {logs.length === 0 ? (
          <p className="mt-4 rounded-xl bg-slate-50 px-4 py-5 text-sm text-slate-600">
            Lô này chưa có nhật ký canh tác được ghi nhận.
          </p>
        ) : (
          <ol className="mt-5 space-y-0">
            {logs.map((log, index) => (
              <li key={log.id} className="relative pb-6 pl-8 last:pb-0">
                {index < logs.length - 1 ? (
                  <span className="absolute bottom-0 left-[7px] top-5 w-px bg-emerald-200" />
                ) : null}
                <span className="absolute left-0 top-1 h-4 w-4 rounded-full border-[3px] border-white bg-emerald-600 ring-2 ring-emerald-100" />
                <p className="font-bold text-slate-900">{log.activity}</p>
                {log.description ? (
                  <p className="mt-1 text-sm leading-6 text-slate-600">{log.description}</p>
                ) : null}
                {log.image_url ? (
                  <img
                    src={log.image_url}
                    alt={`Ảnh minh chứng ${log.activity}`}
                    className="mt-3 max-h-64 w-full rounded-xl border border-slate-200 object-cover"
                  />
                ) : null}
                <p className="mt-2 text-xs font-medium text-slate-500">
                  {formatDate(log.performed_at)}
                  {log.staff?.full_name ? ` · ${log.staff.full_name}` : ''}
                </p>
              </li>
            ))}
          </ol>
        )}
      </section>

      <aside className="flex gap-3 rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm leading-6 text-slate-700">
        <ShieldCheck className="mt-0.5 shrink-0 text-amber-700" size={20} />
        <p>{data?.trace_notice}</p>
      </aside>
    </div>
  );
}
