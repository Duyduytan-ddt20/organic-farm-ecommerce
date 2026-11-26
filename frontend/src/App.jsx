import { Route, Routes } from 'react-router-dom';
import CustomerLayout from './layouts/CustomerLayout';
import HomePage from './pages/HomePage';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import ProductDetail from './pages/ProductDetail';
import TraceabilityPage from './pages/TraceabilityPage';
import TracePage from './pages/TracePage';
import ManagementPage from './pages/ManagementPage';
import ShopPage from './pages/ShopPage';
import OrdersPage from './pages/OrdersPage';

export default function App() {
  return (
    <Routes>
      <Route element={<CustomerLayout />}>
        <Route path="/" element={<HomePage />} />
        <Route path="/dang-nhap" element={<LoginPage />} />
        <Route path="/dang-ky" element={<RegisterPage />} />
        <Route path="/truy-xuat/:qrToken" element={<TracePage />} />
        <Route path="/quan-ly" element={<ManagementPage />} />
        <Route path="/mua-sam" element={<ShopPage />} />
        <Route path="/don-hang" element={<OrdersPage />} />
      </Route>
    </Routes>
  );
}