import { Outlet } from 'react-router-dom';
import Footer from '../components/Footer';
import Header from '../components/Header';

export default function CustomerLayout() {
  return (
    <div className="flex min-h-screen flex-col bg-slate-50">
      <Header subtitle="NÔNG SẢN HỮU CƠ" title="Nông sản từ nông trại" />

      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6">
        <Outlet />
      </main>

      <Footer />
    </div>
  );
}
