import { useState } from 'react';
import { useNavigate } from 'react-router-dom';

export default function TraceabilityPage() {
  const [qrToken, setQrToken] = useState('');
  const navigate = useNavigate();

  function handleSubmit(event) {
    event.preventDefault();
    const token = qrToken.trim();
    if (token) navigate(`/truy-xuat/${encodeURIComponent(token)}`);
  }

  return (
    <section className="mx-auto max-w-xl rounded-2xl bg-white p-6 shadow-sm sm:p-8">
      <p className="text-sm font-semibold text-emerald-700">TRUY XUẤT NGUỒN GỐC</p>
      <h1 className="mt-2 text-2xl font-bold text-slate-900">Tra cứu mã lô</h1>
      <p className="mt-2 text-sm leading-6 text-slate-600">
        Nhập mã QR trên sản phẩm để xem thông tin lô và nhật ký canh tác.
      </p>
      <form onSubmit={handleSubmit} className="mt-5 flex flex-col gap-3 sm:flex-row">
        <input
          type="text"
          value={qrToken}
          onChange={(event) => setQrToken(event.target.value)}
          required
          aria-label="Mã QR lô hàng"
          placeholder="Nhập mã QR"
          className="min-h-12 flex-1 rounded-xl border border-slate-300 px-4"
        />
        <button type="submit" className="min-h-12 rounded-xl bg-emerald-700 px-5 font-semibold text-white">
          Tra cứu
        </button>
      </form>
    </section>
  );
}
