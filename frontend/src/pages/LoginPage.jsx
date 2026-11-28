import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import Button from '../components/Button';
import TextField from '../components/TextField';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../utils/apiError';

export default function LoginPage() {
  const { login } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [form, setForm] = useState({ email: '', password: '' });
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
      await login(form);
      const returnPath = location.state?.from;
      const purchaseProduct = location.state?.purchaseProduct;
      const purchaseProductId = Number(purchaseProduct?.id);
      navigate(
        typeof returnPath === 'string' && returnPath.startsWith('/') && !returnPath.startsWith('//')
          ? returnPath
          : '/',
        {
          replace: true,
          state: Number.isSafeInteger(purchaseProductId) && purchaseProductId > 0
            ? { purchaseProduct }
            : null,
        },
      );
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
      <h2 className="mt-2 text-2xl font-bold text-slate-900">Đăng nhập</h2>
      <p className="mt-2 text-sm leading-6 text-slate-600">
        Tài khoản mẫu dùng mật khẩu <span className="font-semibold">12345678</span>:
        admin@organicfarm.vn, staff@organicfarm.vn, sales@organicfarm.vn, customer@organicfarm.vn.
      </p>

      <form onSubmit={handleSubmit} className="mt-6 space-y-4" noValidate>
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
          id="password"
          name="password"
          type="password"
          label="Mật khẩu"
          autoComplete="current-password"
          value={form.password}
          onChange={update}
          error={errors.password}
          required
        />

        {message ? (
          <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            {message}
          </p>
        ) : null}

        <Button type="submit" className="w-full" disabled={submitting}>
          {submitting ? 'Đang đăng nhập...' : 'Đăng nhập'}
        </Button>
      </form>

      <p className="mt-5 text-sm text-slate-600">
        Chưa có tài khoản?{' '}
        <Link to="/dang-ky" className="font-semibold text-emerald-800">
          Đăng ký
        </Link>
      </p>
    </section>
  );
}
