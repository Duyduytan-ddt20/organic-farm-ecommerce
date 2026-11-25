import Header from '../components/Header';
import Footer from '../components/Footer';

export default function CustomerLayout({ children }) {
  return (
    <div className="min-h-screen flex flex-col bg-slate-50">
      <Header
        subtitle="KHÁCH HÀNG"
        title="Nông sản từ nông trại"
      />

      <main className="w-full max-w-7xl mx-auto flex-1 px-4 py-6 sm:px-6">
        {children}
      </main>

      <Footer />
    </div>
  );
}