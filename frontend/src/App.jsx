import { Link, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import CustomerLayout from './layouts/CustomerLayout';
import HomePage from './pages/HomePage';
import LoginPage from './pages/LoginPage';
import OrderDetailPage from './pages/OrderDetailPage';
import RegisterPage from './pages/RegisterPage';
import ProductDetail from './pages/ProductDetail';
import TraceabilityPage from './pages/TraceabilityPage';
import TracePage from './pages/TracePage';
import ManagementPage from './pages/ManagementPage';
import ShopPage from './pages/ShopPage';
import OrdersPage from './pages/OrdersPage';
import { useAuth } from './context/useAuth.js';

function ProtectedOrderPage() {
  const { user } = useAuth();
  const location = useLocation();

  if (!user) {
    return <Navigate to="/dang-nhap" replace state={{ from: location }} />;
  }

  if (!['Customer', 'Admin'].includes(user.role?.role_name)) {
    return <Navigate to="/" replace />;
  }

  return <OrderDetailPage />;
}

function LegacyAuthRedirect({ to }) {
  const location = useLocation();

  return <Navigate to={to} replace state={location.state} />;
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
    <Routes>
      <Route element={<CustomerLayout />}>
        <Route path="/" element={<HomePage />} />
        <Route path="/dang-nhap" element={<LoginPage />} />
        <Route path="/dang-ky" element={<RegisterPage />} />
        <Route path="/login" element={<LegacyAuthRedirect to="/dang-nhap" />} />
        <Route path="/register" element={<LegacyAuthRedirect to="/dang-ky" />} />
        <Route path="/truy-xuat/:qrToken" element={<TracePage />} />
        <Route path="/truy-xuat" element={<TraceabilityPage />} />
        <Route path="/quan-ly" element={<ManagementPage />} />
        <Route path="/mua-sam" element={<ShopPage />} />
        <Route path="/don-hang" element={<OrdersPage />} />
        <Route path="/orders/preview" element={<ProtectedOrderPage />} />
        <Route path="/orders/:id" element={<ProtectedOrderPage />} />
        <Route path="/products/:id" element={<ProductDetail />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}
