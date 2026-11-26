import { Route, Routes } from 'react-router-dom';
import CustomerLayout from './layouts/CustomerLayout';
import HomePage from './pages/HomePage';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import ProductDetail from './pages/ProductDetail';
import TraceabilityPage from './pages/TraceabilityPage';

export default function App() {
  return (
    <Routes>
      <Route
        path="/"
        element={
          <CustomerLayout>
            <HomePage />
          </CustomerLayout>
        }
      />
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />
      <Route path="/products/:id" element={<ProductDetail />} />
      <Route path="/trace/:ma_lo" element={<TraceabilityPage />} />
    </Routes>
  );
}