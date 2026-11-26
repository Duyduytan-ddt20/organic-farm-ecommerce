import { BrowserRouter, Link, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { AuthProvider } from './context/AuthProvider';
import { useAuth } from './context/useAuth';
import CustomerLayout from './layouts/CustomerLayout';
import HomePage from './pages/HomePage';
import LoginPage from './pages/LoginPage';
import OrderDetailPage from './pages/OrderDetailPage';
import RegisterPage from './pages/RegisterPage';

function ProtectedOrderPage() {
  const { user } = useAuth();
  const location = useLocation();

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (!['Customer', 'Admin'].includes(user.role?.role_name)) {
    return <Navigate to="/" replace />;
  }

  return <OrderDetailPage />;
}

function NotFoundPage() {
  return (
    <section className="rounded-2xl bg-white p-6 shadow-sm">
      <h2 className="text-xl font-bold">Không tìm thấy trang</h2>
      <Link className="mt-3 inline-block font-semibold text-emerald-700 underline" to="/">
        Trở về trang chủ
      </Link>
    </section>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/" element={<CustomerLayout><HomePage /></CustomerLayout>} />
          <Route path="/login" element={<CustomerLayout><LoginPage /></CustomerLayout>} />
          <Route path="/register" element={<CustomerLayout><RegisterPage /></CustomerLayout>} />
          <Route
            path="/orders/:id"
            element={<CustomerLayout><ProtectedOrderPage /></CustomerLayout>}
          />
          <Route path="*" element={<CustomerLayout><NotFoundPage /></CustomerLayout>} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
