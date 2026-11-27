import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { QRCodeSVG } from 'qrcode.react';
import axiosClient from '../api/axiosClient';

const APP_URL = import.meta.env.VITE_PUBLIC_APP_URL || window.location.origin;

export default function ProductDetail() {
  const { id } = useParams();
  const [product, setProduct] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;

    axiosClient.get(`/products/${id}`)
      .then((response) => {
        if (active) setProduct(response.data.data);
      })
      .catch((requestError) => {
        if (active) {
          setError(requestError.response?.data?.message || 'Không thể tải thông tin sản phẩm.');
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [id]);

  if (loading) {
    return <p className="rounded-2xl bg-white p-6 text-slate-600 shadow-sm">Đang tải sản phẩm...</p>;
  }

  if (error || !product) {
    return <p role="alert" className="rounded-2xl bg-red-50 p-6 text-red-700">{error || 'Sản phẩm không tồn tại.'}</p>;
  }

  return (
    <section className="mx-auto max-w-4xl rounded-2xl bg-white p-5 shadow-sm sm:p-8">
      <Link to="/mua-sam" className="text-sm font-semibold text-emerald-700 underline">
        Quay lại mua sắm
      </Link>
      <p className="mt-5 text-sm font-medium text-emerald-700">{product.category?.name}</p>
      <h1 className="mt-1 text-3xl font-bold text-slate-900">{product.name}</h1>
      {product.description ? <p className="mt-3 text-slate-600">{product.description}</p> : null}
      <p className="mt-4 text-xl font-bold text-emerald-800">
        {Number(product.base_price).toLocaleString('vi-VN')} đ/{product.unit}
      </p>

      <h2 className="mt-8 text-lg font-bold text-slate-900">Các lô hàng khả dụng (FEFO)</h2>
      {product.batches?.length ? (
        <ul className="mt-4 space-y-3">
          {product.batches.map((batch) => {
            const traceUrl = batch.qr_token
              ? `${APP_URL}/truy-xuat/${encodeURIComponent(batch.qr_token)}`
              : null;

            return (
              <li key={batch.id} className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 p-4">
                <div>
                  <p className="font-semibold text-slate-900">{batch.batch_code}</p>
                  <p className="mt-1 text-sm text-slate-600">Hạn dùng: {batch.expires_at || 'Chưa cập nhật'}</p>
                  <p className="text-sm text-slate-600">Còn: {batch.available_quantity} {product.unit}</p>
                </div>
                {traceUrl ? (
                  <Link to={`/truy-xuat/${encodeURIComponent(batch.qr_token)}`} className="flex items-center gap-3 text-sm font-semibold text-emerald-800">
                    <QRCodeSVG value={traceUrl} size={72} />
                    <span>Xem truy xuất</span>
                  </Link>
                ) : null}
              </li>
            );
          })}
        </ul>
      ) : (
        <p className="mt-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">
          Sản phẩm hiện chưa có lô hàng khả dụng.
        </p>
      )}
    </section>
  );
}
