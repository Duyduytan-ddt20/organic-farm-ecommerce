import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Button from '../components/Button';
import TextField from '../components/TextField';
import { useAuth } from '../context/useAuth.js';
import { apiError } from '../utils/apiError';

const INITIAL_FORM = {
  full_name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
};

export default function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState(INITIAL_FORM);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState('');
  const [submitting, setSubmitting] = useState(false);

  function update(event) {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setSubmitting(true);
    setMessage('');
    setErrors({});

    try {
      await register({
        ...form,
        phone: form.phone.trim() === '' ? null : form.phone.trim(),
      });
      navigate('/', { replace: true, state: { registered: true } });
    } catch (error) {
      const parsed = apiError(error);
      setMessage(parsed.message);
      setErrors(parsed.errors);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="mx-auto w-full max-w-md rounded-2xl bg-white p-5 shadow-sm sm:p-8">
      <p className="text-sm font-semibold text-emerald-700">TÀI KHOẢN</p>
      <h2 className="mt-2 text-2xl font-bold text-slate-900">Đăng ký</h2>
      <p className="mt-2 text-sm leading-6 text-slate-600">
        Tài khoản mới luôn có vai trò Khách hàng. Staff, Sales và Admin được cấp sẵn trong hệ thống.
      </p>

      <form onSubmit={handleSubmit} className="mt-6 space-y-4" noValidate>
        <TextField
          id="full_name"
          name="full_name"
          label="Họ và tên"
          autoComplete="name"
          value={form.full_name}
          onChange={update}
          error={errors.full_name}
          required
        />
        <TextField
          id="email"
          name="email"
          type="email"
          label="Email"
          autoComplete="email"
          value={form.email}
          onChange={update}
          error={errors.email}
          required
        />
        <TextField
          id="phone"
          name="phone"
          type="tel"
          label="Số điện thoại"
          autoComplete="tel"
          value={form.phone}
          onChange={update}
          error={errors.phone}
        />
        <TextField
          id="password"
          name="password"
          type="password"
          label="Mật khẩu"
          autoComplete="new-password"
          value={form.password}
          onChange={update}
          error={errors.password}
          required
        />
        <TextField
          id="password_confirmation"
          name="password_confirmation"
          type="password"
          label="Xác nhận mật khẩu"
          autoComplete="new-password"
          value={form.password_confirmation}
          onChange={update}
          error={errors.password_confirmation}
          required
        />

        {message ? (
          <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            {message}
          </p>
        ) : null}
        <Button type="submit" className="w-full" disabled={submitting}>
          {submitting ? 'Đang tạo tài khoản...' : 'Đăng ký'}
        </Button>
      </form>

      <p className="mt-5 text-sm text-slate-600">
        Đã có tài khoản?{' '}
        <Link to="/dang-nhap" className="font-semibold text-emerald-800">Đăng nhập</Link>
      </p>
    </section>
  );
}
