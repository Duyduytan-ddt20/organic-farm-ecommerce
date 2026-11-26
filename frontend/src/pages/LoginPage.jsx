import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import { useAuth } from '../context/useAuth';

export default function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const { signIn } = useAuth();
  const [email, setEmail] = useState(location.state?.email || 'customer@organicfarm.vn');
  const [password, setPassword] = useState('12345678');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    setError('');
    setSubmitting(true);

    try {
      const response = await axiosClient.post('/auth/login', { email, password });
      const { token, user } = response.data.data;
      await signIn(token, user);
      const canReviewOrders = ['Customer', 'Admin'].includes(user.role?.role_name);
      const hasDemoOrder = user.role?.role_name === 'Admin' || user.email === 'customer@organicfarm.vn';
      const requestedPath = location.state?.from?.pathname;
      const returnTo = requestedPath === '/orders/preview' && !hasDemoOrder ? null : requestedPath;
      navigate(returnTo || (canReviewOrders && hasDemoOrder ? '/orders/preview' : '/'), { replace: true });
    } catch (requestError) {
      const payload = requestError.response?.data;
      const firstFieldError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
      setError(firstFieldError || payload?.message || requestError.message || 'Không đăng nhập được.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
      <h2 className="text-xl font-bold text-slate-900">Đăng nhập khách hàng</h2>
      <p className="mt-2 text-sm text-slate-600">
        Đăng nhập để mở đơn đã giao và gửi khiếu nại kèm ảnh minh chứng.
      </p>
      {location.state?.logoutWarning && (
        <p role="alert" className="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {location.state.logoutWarning}
        </p>
      )}
      <form className="mt-5 space-y-4" onSubmit={handleSubmit}>
        <label className="block text-sm font-medium text-slate-700">
          Email
          <input
            className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3"
            type="email"
            autoComplete="username"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Mật khẩu
          <input
            className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </label>
        {error && <p role="alert" className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
        <button
          className="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white disabled:opacity-60"
          type="submit"
          disabled={submitting}
        >
          {submitting ? 'Đang đăng nhập...' : 'Đăng nhập'}
        </button>
      </form>
      <p className="mt-4 text-sm text-slate-600">
        Tài khoản mẫu: <code>customer@organicfarm.vn</code>, <code>staff@organicfarm.vn</code>,{' '}
        <code>sales@organicfarm.vn</code> hoặc <code>admin@organicfarm.vn</code>. Mật khẩu chung:{' '}
        <code>12345678</code>.
      </p>
      <Link className="mt-4 inline-block font-semibold text-emerald-700 underline" to="/">
        Trở về trang chủ
      </Link>
      <p className="mt-4 text-sm text-slate-600">
        Chưa có tài khoản?{' '}
        <Link className="font-semibold text-emerald-700 underline" to="/register">
          Đăng ký khách hàng
        </Link>
      </p>
    </section>
  );
}
