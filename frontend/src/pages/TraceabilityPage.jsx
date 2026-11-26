import React, { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import axios from 'axios';
import { QRCodeSVG } from 'qrcode.react';

const API_URL = import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api/v1';
const APP_URL = import.meta.env.VITE_PUBLIC_APP_URL || window.location.origin;

export default function TraceabilityPage() {
  const { ma_lo } = useParams(); // Route: /trace/:ma_lo
  const [batch, setBatch] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const fetchTraceInfo = async () => {
      try {
        setLoading(true);
        const res = await axios.get(`${API_URL}/trace/${ma_lo}`);
        setBatch(res.data.data);
      } catch (err) {
        setError('Không tìm thấy thông tin truy xuất cho mã lô này.');
      } finally {
        setLoading(false);
      }
    };
    fetchTraceInfo();
  }, [ma_lo]);

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-green-50">
        <div className="animate-spin rounded-full h-10 w-10 border-4 border-green-700 border-t-transparent"></div>
      </div>
    );
  }

  if (error || !batch) {
    return (
      <div className="min-h-screen flex items-center justify-center p-4 bg-gray-50">
        <div className="max-w-md w-full bg-white p-6 rounded-2xl shadow text-center space-y-3">
          <p className="text-red-600 font-bold">{error}</p>
          <Link to="/" className="inline-block text-sm text-green-700 underline">
            Về trang chủ nông trại
          </Link>
        </div>
      </div>
    );
  }

  const currentTraceUrl = `${APP_URL}/trace/${batch.ma_lo}`;

  return (
    <div className="min-h-screen bg-gradient-to-b from-green-50 to-white py-6 px-4">
      <div className="max-w-xl mx-auto space-y-5">
        {/* Huy hiệu xác thực nguồn gốc */}
        <div className="bg-green-700 text-white rounded-2xl p-5 shadow-md flex items-center justify-between">
          <div>
            <span className="inline-block bg-green-600 text-[11px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full">
              ✓ Chứng thực truy xuất nguồn gốc
            </span>
            <h1 className="text-xl font-extrabold mt-2">{batch.product?.ten_san_pham}</h1>
            <p className="text-xs text-green-100 mt-0.5">Mã lô: {batch.ma_lo}</p>
          </div>
          <div className="bg-white p-2 rounded-xl shrink-0">
            <QRCodeSVG value={currentTraceUrl} size={68} level="H" />
          </div>
        </div>

        {/* Thông số kiểm định lô thu hoạch */}
        <div className="bg-white rounded-2xl p-5 shadow-sm border border-green-100 space-y-3">
          <h2 className="text-sm font-bold text-gray-900 uppercase tracking-wide">
            Thông tin lô thu hoạch
          </h2>
          <div className="grid grid-cols-2 gap-3 text-sm">
            <div className="p-3 bg-gray-50 rounded-xl">
              <p className="text-xs text-gray-500">Ngày thu hoạch</p>
              <p className="font-bold text-gray-900 mt-0.5">{batch.ngay_thu_hoach}</p>
            </div>
            <div className="p-3 bg-gray-50 rounded-xl">
              <p className="text-xs text-gray-500">Hạn sử dụng chuẩn</p>
              <p className="font-bold text-red-600 mt-0.5">{batch.ngay_het_han}</p>
            </div>
            <div className="p-3 bg-gray-50 rounded-xl">
              <p className="text-xs text-gray-500">Sản lượng thu hoạch lô</p>
              <p className="font-bold text-gray-900 mt-0.5">
                {batch.so_luong_nhap} {batch.product?.don_vi_tinh}
              </p>
            </div>
            <div className="p-3 bg-gray-50 rounded-xl">
              <p className="text-xs text-gray-500">Trạng thái lô</p>
              <p className="font-bold text-green-700 mt-0.5 uppercase">{batch.trang_thai}</p>
            </div>
          </div>
        </div>

        {/* Hành trình canh tác minh bạch */}
        <div className="bg-white rounded-2xl p-5 shadow-sm border border-green-100">
          <h2 className="text-sm font-bold text-gray-900 uppercase tracking-wide mb-4">
            Hành trình canh tác từ hạt giống đến bàn ăn
          </h2>

          {batch.farming_logs && batch.farming_logs.length > 0 ? (
            <ol className="relative border-l-2 border-green-600 ml-3 space-y-6">
              {batch.farming_logs.map((log) => (
                <li key={log.id} className="ml-5">
                  <span className="absolute -left-2.5 flex items-center justify-center w-5 h-5 bg-green-600 rounded-full ring-4 ring-white">
                    <span className="w-2 h-2 bg-white rounded-full"></span>
                  </span>
                  <time className="block text-xs font-bold text-green-700">{log.thoi_gian}</time>
                  <h3 className="text-sm font-bold text-gray-900 mt-0.5">{log.hanh_dong}</h3>
                  {log.ghi_chu && <p className="text-xs text-gray-600 mt-1">{log.ghi_chu}</p>}
                  {log.hinh_anh_minh_chung && (
                    <img
                      src={log.hinh_anh_minh_chung}
                      alt={log.hanh_dong}
                      className="mt-2.5 rounded-xl w-full max-h-56 object-cover border border-gray-200"
                    />
                  )}
                  <p className="text-[11px] text-gray-400 mt-1">
                    Xác nhận bởi: {log.staff?.ho_ten || 'Farm Staff'}
                  </p>
                </li>
              ))}
            </ol>
          ) : (
            <p className="text-xs text-gray-500 italic">Đang cập nhật nhật ký canh tác.</p>
          )}

          <div className="mt-6 pt-4 border-t border-gray-100 text-center">
            <Link
              to={`/products/${batch.product_id}`}
              className="inline-block w-full py-3 bg-green-700 hover:bg-green-800 text-white font-bold text-sm rounded-xl transition"
            >
              Đặt mua sản phẩm từ lô này
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}