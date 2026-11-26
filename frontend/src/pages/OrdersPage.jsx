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

  useEffect(() => {
    if (user) {
      axiosClient.get('/sales/orders')
        .then((response) => setOrders(response.data.data ?? []))
        .catch((error) => setMessage(apiError(error).message));
    }
  }, [user]);

  async function confirm(orderId) {
    setLoadingId(orderId);
    setMessage('');
    try {
      const response = await axiosClient.post(`/sales/orders/${orderId}/confirm`);
      setOrders((current) => current.map((order) => order.id === orderId ? response.data.data : order));
      setMessage(response.data.message);
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
      <div className="mt-5 space-y-4">
        {orders.map((order) => (
          <article key={order.id} className="rounded-2xl border border-slate-200 p-5">
            <div className="flex flex-col justify-between gap-3 sm:flex-row">
              <div>
                <p className="font-bold text-slate-900">{order.order_code}</p>
                <p className="mt-1 text-sm text-slate-600">{order.customer?.full_name} · {order.recipient_phone}</p>
                <p className="text-sm text-slate-600">{order.delivery_address}</p>
              </div>
              <span className="h-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold">{order.status}</span>
            </div>
            <div className="mt-4 space-y-2">
              {order.items.map((item) => (
                <div key={item.id} className="rounded-xl bg-slate-50 p-3">
                  <p className="font-semibold">{item.product_name} - {item.quantity}</p>
                  {item.allocated_batches.length > 0 ? (
                    <p className="mt-1 text-sm text-emerald-700">
                      FEFO: {item.allocated_batches.map((detail) => `${detail.batch_code} (${detail.quantity})`).join(', ')}
                    </p>
                  ) : null}
                </div>
              ))}
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
