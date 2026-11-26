import React, { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import axios from 'axios';
import { QRCodeSVG } from 'qrcode.react';

const API_URL = import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api/v1';
const APP_URL = import.meta.env.VITE_PUBLIC_APP_URL || window.location.origin;

export default function ProductDetail() {
  const { id } = useParams(); // Ví dụ route: /products/:id
  const [product, setProduct] = useState(null);
  const [selectedBatch, setSelectedBatch] = useState(null);
  const [quantity, setQuantity] = useState(1);
  const [purchaseType, setPurchaseType] = useState('retail'); // 'retail' | 'subscription'
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const fetchProductDetail = async () => {
      try {
        setLoading(true);
        const res = await axios.get(`${API_URL}/products/${id}`);
        const data = res.data.data;
        setProduct(data);
        // Tự động chọn lô ưu tiên FEFO đầu tiên (hết hạn gần nhất)
        if (data.batches && data.batches.length > 0) {
          setSelectedBatch(data.batches[0]);
        }
      } catch (err) {
        setError(err.response?.data?.message || 'Không thể tải thông tin sản phẩm.');
      } finally {
        setLoading(false);
      }
    };
    fetchProductDetail();
  }, [id]);

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="animate-spin rounded-full h-10 w-10 border-4 border-green-600 border-t-transparent"></div>
      </div>
    );
  }

  if (error || !product) {
    return (
      <div className="max-w-lg mx-auto mt-10 p-6 bg-red-50 border border-red-200 rounded-xl text-red-700 text-center">
        {error || 'Sản phẩm không tồn tại.'}
      </div>
    );
  }

  const traceUrl = selectedBatch ? `${APP_URL}/trace/${selectedBatch.ma_lo}` : '';
  const unitPrice = selectedBatch && selectedBatch.phan_tram_giam > 0
    ? product.gia_ban_co_ban * (1 - selectedBatch.phan_tram_giam / 100)
    : Number(product.gia_ban_co_ban);

  return (
    <div className="min-h-screen bg-gray-50 pb-28 md:pb-12">
      {/* Header Mobile-First */}
      <header className="sticky top-0 z-20 bg-white/95 backdrop-blur border-b border-gray-200 px-4 py-3 flex items-center justify-between">
        <Link to="/" className="text-sm font-medium text-green-700 hover:underline">
          ← Quay lại cửa hàng
        </Link>
        <span className="text-xs font-semibold uppercase tracking-wider text-green-700 bg-green-50 px-2.5 py-1 rounded-full">
          Single Farm Organic
        </span>
      </header>

      <main className="max-w-4xl mx-auto px-4 pt-5 grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Cột trái: Hình ảnh sản phẩm & Thông tin Lô FEFO */}
        <div className="space-y-4">
          <div className="relative rounded-2xl overflow-hidden bg-white shadow-sm border border-gray-100 aspect-square">
            <img
              src={product.hinh_anh || 'https://images.unsplash.com/photo-1592924357228-91a4daadcfea?auto=format&fit=crop&w=800&q=80'}
              alt={product.ten_san_pham}
              className="w-full h-full object-cover"
            />
            {selectedBatch && (
              <span className="absolute bottom-3 left-3 bg-green-600 text-white text-xs font-semibold px-3 py-1.5 rounded-full shadow">
                Thu hoạch: {selectedBatch.ngay_thu_hoach}
              </span>
            )}
          </div>

          {/* Thẻ Lô hàng đang mở bán (FEFO) */}
          {selectedBatch ? (
            <div className="bg-green-50/80 border border-green-200 rounded-2xl p-4 space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold uppercase text-green-800">
                  Lô hàng đang xuất (FEFO)
                </span>
                <span className="text-xs font-mono font-semibold bg-white text-green-900 px-2.5 py-1 rounded border border-green-300">
                  {selectedBatch.ma_lo}
                </span>
              </div>

              <div className="grid grid-cols-3 gap-2 text-center bg-white rounded-xl p-2.5 border border-green-100">
                <div>
                  <p className="text-[11px] text-gray-500">Ngày thu hoạch</p>
                  <p className="text-xs font-bold text-gray-800">{selectedBatch.ngay_thu_hoach}</p>
                </div>
                <div className="border-x border-gray-100">
                  <p className="text-[11px] text-gray-500">Hạn sử dụng</p>
                  <p className="text-xs font-bold text-red-600">{selectedBatch.ngay_het_han}</p>
                </div>
                <div>
                  <p className="text-[11px] text-gray-500">Tồn khả dụng</p>
                  <p className="text-xs font-bold text-green-700">
                    {selectedBatch.so_luong_ton} {product.don_vi_tinh}
                  </p>
                </div>
              </div>

              {/* Khối Mã QR Truy xuất nguồn gốc */}
              <div className="flex items-center gap-4 bg-white p-3 rounded-xl border border-green-200">
                <div className="p-2 bg-white border border-gray-200 rounded-lg shrink-0">
                  <QRCodeSVG
                    value={traceUrl}
                    size={88}
                    level="H"
                    includeMargin={false}
                  />
                </div>
                <div className="space-y-1">
                  <p className="text-xs font-bold text-gray-900">
                    Quét mã QR truy xuất nguồn gốc
                  </p>
                  <p className="text-[11px] text-gray-600 leading-relaxed">
                    Dùng camera điện thoại quét trực tiếp mã này để kiểm chứng nhật ký trồng trọt tại vườn.
                  </p>
                  <Link
                    to={`/trace/${selectedBatch.ma_lo}`}
                    className="inline-block text-xs font-semibold text-green-700 hover:underline pt-0.5"
                  >
                    Xem trang truy xuất công khai →
                  </Link>
                </div>
              </div>
            </div>
          ) : (
            <div className="p-4 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-sm">
              Sản phẩm hiện đang tạm hết lô thu hoạch khả dụng.
            </div>
          )}
        </div>

        {/* Cột phải: Giá bán, Tùy chọn mua & Dòng thời gian Nhật ký canh tác */}
        <div className="space-y-6">
          <div className="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 space-y-4">
            <div>
              <p className="text-xs text-gray-500 font-medium">{product.category?.ten_danh_muc}</p>
              <h1 className="text-2xl font-bold text-gray-900 mt-0.5">{product.ten_san_pham}</h1>
            </div>

            <div className="flex items-baseline gap-2.5">
              <span className="text-2xl font-extrabold text-green-700">
                {unitPrice.toLocaleString('vi-VN')}đ
              </span>
              <span className="text-sm text-gray-500">/ {product.don_vi_tinh}</span>
              {selectedBatch?.phan_tram_giam > 0 && (
                <span className="text-xs font-bold bg-red-100 text-red-700 px-2 py-0.5 rounded">
                  -{selectedBatch.phan_tram_giam}% Xả hàng tươi
                </span>
              )}
            </div>

            {/* Chọn hình thức Mua lẻ hoặc Đặt gói định kỳ */}
            <div className="grid grid-cols-2 gap-2.5 pt-1">
              <button
                type="button"
                onClick={() => setPurchaseType('retail')}
                className={`p-3 rounded-xl border text-left transition ${
                  purchaseType === 'retail'
                    ? 'border-green-600 bg-green-50 text-green-900'
                    : 'border-gray-200 text-gray-600 hover:bg-gray-50'
                }`}
              >
                <p className="text-xs font-bold">Mua lẻ giao ngay</p>
                <p className="text-[11px] opacity-80 mt-0.5">Xuất chuẩn lô FEFO</p>
              </button>
              <button
                type="button"
                onClick={() => setPurchaseType('subscription')}
                className={`p-3 rounded-xl border text-left transition ${
                  purchaseType === 'subscription'
                    ? 'border-green-600 bg-green-50 text-green-900'
                    : 'border-gray-200 text-gray-600 hover:bg-gray-50'
                }`}
              >
                <p className="text-xs font-bold">Gói định kỳ tuần</p>
                <p className="text-[11px] text-green-700 font-medium mt-0.5">Ưu đãi tiết kiệm 10%</p>
              </button>
            </div>

            {/* Chọn số lượng */}
            <div className="flex items-center justify-between pt-2">
              <span className="text-sm font-medium text-gray-700">Số lượng ({product.don_vi_tinh}):</span>
              <div className="flex items-center border border-gray-300 rounded-lg overflow-hidden">
                <button
                  type="button"
                  onClick={() => setQuantity((q) => Math.max(0.5, q - 0.5))}
                  className="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 font-bold"
                >
                  -
                </button>
                <span className="px-4 py-1.5 text-sm font-semibold">{quantity}</span>
                <button
                  type="button"
                  onClick={() => setQuantity((q) => q + 0.5)}
                  className="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 font-bold"
                >
                  +
                </button>
              </div>
            </div>
          </div>

          {/* Dòng thời gian (Timeline) Nhật ký canh tác thực tế */}
          <div className="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
            <h2 className="text-base font-bold text-gray-900 mb-4">
              Nhật ký canh tác lô {selectedBatch?.ma_lo}
            </h2>

            {selectedBatch?.farming_logs && selectedBatch.farming_logs.length > 0 ? (
              <ol className="relative border-l-2 border-green-500 ml-3 space-y-6">
                {selectedBatch.farming_logs.map((log) => (
                  <li key={log.id} className="ml-5">
                    <span className="absolute -left-2.5 flex items-center justify-center w-5 h-5 bg-green-600 rounded-full ring-4 ring-white">
                      <span className="w-2 h-2 bg-white rounded-full"></span>
                    </span>
                    <time className="block text-xs font-semibold text-green-700">
                      {log.thoi_gian}
                    </time>
                    <h3 className="text-sm font-bold text-gray-900 mt-0.5">{log.hanh_dong}</h3>
                    {log.ghi_chu && (
                      <p className="text-xs text-gray-600 mt-1 leading-relaxed">{log.ghi_chu}</p>
                    )}
                    {log.hinh_anh_minh_chung && (
                      <img
                        src={log.hinh_anh_minh_chung}
                        alt={log.hanh_dong}
                        className="mt-2.5 rounded-xl w-full max-h-48 object-cover border border-gray-200"
                      />
                    )}
                    <p className="text-[11px] text-gray-400 mt-1.5">
                      Ghi nhận bởi: {log.staff?.ho_ten || 'Kỹ thuật viên nông trại'}
                    </p>
                  </li>
                ))}
              </ol>
            ) : (
              <p className="text-xs text-gray-500 italic">
                Chưa có bản ghi nhật ký canh tác nào cho lô này.
              </p>
            )}
          </div>
        </div>
      </main>
    </div>
  );
}