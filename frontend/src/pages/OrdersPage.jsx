import { useEffect, useState } from 'react';
import axiosClient from '../api/axiosClient';
import Button from '../components/Button';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../utils/apiError';

export default function OrdersPage() {
  const { user } = useAuth();
  const [orders, setOrders] = useState([]);
  const [message, setMessage] = useState('');
  const [loadingId, setLoadingId] = useState(null);
  const [ordersLoaded, setOrdersLoaded] = useState(false);

  useEffect(() => {
    if (!user) {
      return undefined;
    }

    let active = true;
    axiosClient.get('/sales/orders')
      .then((response) => {
        if (active) {
          setOrders(Array.isArray(response.data) ? response.data : []);
        }
      })
      .catch((error) => {
        if (active) {
          setMessage(apiError(error).message);
        }
      })
      .finally(() => {
        if (active) {
          setOrdersLoaded(true);
        }
      });

    return () => {
      active = false;
    };
  }, [user]);

  const loadingOrders = Boolean(user) && !ordersLoaded;

  async function confirm(orderId) {
    setLoadingId(orderId);
    setMessage('');
    try {
      const response = await axiosClient.post(`/sales/orders/${orderId}/confirm`);
      setOrders((current) => current.map((order) => order.id === orderId ? response.data : order));
      setMessage(response.message);
    } catch (error) {
      setMessage(apiError(error).message);
    } finally {
      setLoadingId(null);
    }
  }

  return (
    <section className="rounded-2xl bg-white p-5 shadow-sm sm:p-7">
      <p className="text-sm font-semibold text-emerald-700">NHÂN VIÊN BÁN HÀNG</p>
      <h1 className="mt-1 text-2xl font-bold">Quản lý đơn hàng FEFO</h1>
      {message ? <p className="mt-4 rounded-xl bg-slate-100 px-4 py-3 text-sm">{message}</p> : null}
      {loadingOrders ? <p role="status" className="mt-5 text-sm text-slate-600">Đang tải đơn hàng...</p> : null}
      {!loadingOrders && user && !message && orders.length === 0 ? (
        <p className="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
          Hiện chưa có đơn hàng cần xử lý.
        </p>
      ) : null}
      <div className="mt-5 space-y-4">
        {orders.map((order) => (
          <article key={order.id} className="rounded-2xl border border-slate-200 p-5">
            <div className="flex flex-col justify-between gap-3 sm:flex-row">
              <div>
                <p className="font-bold text-slate-900">{order.order_code}</p>
                <p className="mt-1 text-sm text-slate-600">{order.customer?.full_name} · {order.recipient_phone}</p>
                <p className="text-sm text-slate-600">{order.delivery_address}</p>
                {order.payment_status ? (
                  <p className="mt-1 text-sm font-medium text-emerald-700">
                    Thanh toán: {order.payment_status === 'succeeded'
                      ? 'Đã thanh toán'
                      : order.payment_status === 'failed'
                        ? 'Thất bại'
                        : 'Đang chờ'}
                    {order.payment_method === 'mock' ? ' · Demo local' : ''}
                  </p>
                ) : null}
              </div>
              <span className="h-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold">{order.status}</span>
            </div>
            <div className="mt-4 space-y-2">
              {order.items.map((item) => {
                const batchDetails = order.status === 'pending'
                  ? item.planned_batches
                  : item.allocated_batches;

                return (
                  <div key={item.id} className="rounded-xl bg-slate-50 p-3">
                    <p className="font-semibold">{item.product_name} - {item.quantity}</p>
                    {batchDetails?.length ? (
                      <p className="mt-1 text-sm text-emerald-700">
                        {order.status === 'pending' ? 'Dự kiến FEFO' : 'Lô đã phân bổ'}:{' '}
                        {batchDetails.map((detail) => `${detail.batch_code} (${detail.quantity})`).join(', ')}
                        {order.status === 'pending' ? ' — có thể thay đổi trước khi xác nhận.' : ''}
                      </p>
                    ) : order.status === 'pending' ? (
                      <p className="mt-1 text-sm text-amber-700">
                        Chưa đủ tồn khả dụng để dự kiến phân bổ lô.
                      </p>
                    ) : null}
                  </div>
                );
              })}
            </div>
            {order.status === 'pending' ? (
              <Button type="button" className="mt-4" disabled={loadingId === order.id} onClick={() => confirm(order.id)}>
                {loadingId === order.id ? 'Đang xác nhận...' : 'Xác nhận & trừ kho FEFO'}
              </Button>
            ) : null}
          </article>
        ))}
      </div>
    </section>
  );
}
