import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import { useAuth } from '../context/useAuth';

export default function RegisterPage() {
  const navigate = useNavigate();
  const { signIn } = useAuth();
  const [form, setForm] = useState({
    full_name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  });
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  function updateField(event) {
    setForm((current) => ({ ...current, [event.target.name]: event.target.value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setError('');
    setSubmitting(true);

    try {
      const response = await axiosClient.post('/auth/register', form);
      await signIn(response.data.data.token, response.data.data.user);
      navigate('/', { replace: true, state: { registered: true } });
    } catch (requestError) {
      const payload = requestError.response?.data;
      const firstFieldError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
      setError(firstFieldError || payload?.message || requestError.message || 'Không đăng ký được.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
      <h2 className="text-xl font-bold text-slate-900">Tạo tài khoản khách hàng</h2>
      <p className="mt-2 text-sm text-slate-600">Tài khoản đăng ký mới được tạo với vai trò Customer.</p>
      <form className="mt-5 space-y-4" onSubmit={handleSubmit}>
        <label className="block text-sm font-medium text-slate-700">
          Họ và tên
          <input
            className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3"
            name="full_name"
            autoComplete="name"
            maxLength={100}
            required
            value={form.full_name}
            onChange={updateField}
          />
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Email
          <input
            className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3"
            name="email"
            type="email"
            autoComplete="email"
            maxLength={150}
            required
            value={form.email}
            onChange={updateField}
          />
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Số điện thoại (không bắt buộc)
          <input
            className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3"
            name="phone"
            type="tel"
            autoComplete="tel"
            maxLength={20}
            value={form.phone}
            onChange={updateField}
          />
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Mật khẩu (ít nhất 8 ký tự)
          <input
            className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3"
            name="password"
            type="password"
            autoComplete="new-password"
            minLength={8}
            required
            value={form.password}
            onChange={updateField}
          />
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Nhập lại mật khẩu
          <input
            className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3"
            name="password_confirmation"
            type="password"
            autoComplete="new-password"
            minLength={8}
            required
            value={form.password_confirmation}
            onChange={updateField}
          />
        </label>
        {error && <p role="alert" className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
        <button
          className="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white disabled:opacity-60"
          type="submit"
          disabled={submitting}
        >
          {submitting ? 'Đang tạo tài khoản...' : 'Đăng ký'}
        </button>
      </form>
      <p className="mt-4 text-sm text-slate-600">
        Đã có tài khoản? <Link className="font-semibold text-emerald-700 underline" to="/login">Đăng nhập</Link>
      </p>
    </section>
  );
}
