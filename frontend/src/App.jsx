import { Route, Routes } from 'react-router-dom';
import CustomerLayout from './layouts/CustomerLayout';
import HomePage from './pages/HomePage';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import TracePage from './pages/TracePage';
import ManagementPage from './pages/ManagementPage';

export default function App() {
  return (
    <Routes>
      <Route element={<CustomerLayout />}>
        <Route path="/" element={<HomePage />} />
        <Route path="/dang-nhap" element={<LoginPage />} />
        <Route path="/dang-ky" element={<RegisterPage />} />
        <Route path="/truy-xuat/:qrToken" element={<TracePage />} />
        <Route path="/quan-ly" element={<ManagementPage />} />
      </Route>
    </Routes>
  );
}
