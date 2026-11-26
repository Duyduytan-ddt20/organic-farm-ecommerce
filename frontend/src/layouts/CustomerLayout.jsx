import { useNavigate } from 'react-router-dom';
import Header from '../components/Header';
import Footer from '../components/Footer';
import { useAuth } from '../context/useAuth';

export default function CustomerLayout({ children }) {
  const { user, signOut } = useAuth();
  const navigate = useNavigate();

  async function handleLogout() {
    try {
      await signOut();
      navigate('/login', { replace: true });
    } catch {
      navigate('/login', {
        replace: true,
        state: { logoutWarning: 'Đã xóa phiên trên trình duyệt nhưng không thể liên hệ máy chủ để thu hồi token.' },
      });
    }
  }

  return (
    <div className="min-h-screen flex flex-col bg-slate-50">
      <Header
        subtitle={user?.role?.role_name?.toUpperCase() ?? 'KHÁCH HÀNG'}
        title="Nông sản từ nông trại"
        user={user}
        onLogout={handleLogout}
      />

      <main className="w-full max-w-7xl mx-auto flex-1 px-4 py-6 sm:px-6">
        {children}
      </main>

      <Footer />
    </div>
  );
}