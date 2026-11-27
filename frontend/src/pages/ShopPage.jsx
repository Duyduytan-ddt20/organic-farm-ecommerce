import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import Button from '../components/Button';
import TextField from '../components/TextField';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../utils/apiError';

function formatMoney(value) {
  return Number(value || 0).toLocaleString('vi-VN');
}

export default function ShopPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [products, setProducts] = useState([]);
  const [cart, setCart] = useState({});
  const [orders, setOrders] = useState([]);
  const [form, setForm] = useState({
    recipient_name: user?.full_name || '',
    recipient_phone: user?.phone || '',
    delivery_address: '',
  });
  const [message, setMessage] = useState('');
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!user) {
      navigate('/dang-nhap');
      return;
    }
    Promise.all([
      axiosClient.get('/products'),
      axiosClient.get('/customer/orders'),
    ]).then(([productsResponse, ordersResponse]) => {
      setProducts(productsResponse.data.data ?? []);
      setOrders(ordersResponse.data.data ?? []);
    }).catch((error) => setMessage(apiError(error).message));
  }, [navigate, user]);

  function addProduct(product) {
    setCart((current) => ({
      ...current,
      [product.id]: {
        product,
        quantity: Number(current[product.id]?.quantity || 0) + 1,
      },
    }));
  }

  function updateQuantity(productId, quantity) {
    setCart((current) => ({
      ...current,
      [productId]: { ...current[productId], quantity: Number(quantity) },
    }));
  }

  async function checkout(event) {
    event.preventEvent?.();
    event.preventDefault();
    setLoading(true);
    setMessage('');
    setErrors({});

    const items = Object.values(cart)
      .filter((entry) => entry.quantity > 0)
      .map((entry) => ({ product_id: entry.product.id, quantity: entry.quantity }));

    if (items.length === 0) {
      setMessage('Vui lòng chọn ít nhất một sản phẩm.');
      setLoading(false);
      return;
    }

    try {
      const response = await axiosClient.post('/customer/orders', {
        ...form,
        shipping_fee: 0,
        items,
      });
      setOrders((current) => [response.data.data, ...current]);
      setCart({});
      setMessage('Tạo đơn hàng thành công. Đơn đang chờ nhân viên bán hàng xác nhận.');
    } catch (error) {
      const parsed = apiError(error);
      setMessage(parsed.message);
      setErrors(parsed.errors);
    } finally {
      setLoading(false);
    }
  }

  const cartEntries = Object.values(cart).filter((entry) => entry.quantity > 0);
  const total = cartEntries.reduce(
    (sum, entry) => sum + Number(entry.product.base_price || 0) * Number(entry.quantity || 0),
    0,
  );

  return (
    <div className="space-y-5">
      <section className="grid gap-5 lg:grid-cols-[1fr_360px]">
        <div className="grid gap-4 sm:grid-cols-2">
          {products.filter((product) => product.is_active).map((product) => (
            <article key={product.id} className="rounded-2xl bg-white p-5 shadow-sm">
              <p className="text-xs font-semibold uppercase text-emerald-700">{product.category?.name}</p>
              <h2 className="mt-2 text-lg font-bold text-slate-900">{product.name}</h2>
              <p className="mt-1 text-sm text-slate-600">{product.description}</p>
              <p className="mt-3 font-bold text-emerald-800">
                {formatMoney(product.base_price)} đ/{product.unit}
              </p>
              <Button type="button" variant="secondary" className="mt-4" onClick={() => addProduct(product)}>
                Thêm vào đơn
              </Button>
            </article>
          ))}
        </div>

        <aside className="h-fit rounded-2xl bg-white p-5 shadow-sm">
          <h2 className="text-xl font-bold text-slate-900">Thanh toán</h2>
          {cartEntries.length === 0 ? (
            <p className="mt-3 text-sm text-slate-500">Chưa có sản phẩm.</p>
          ) : (
            <div className="mt-4 space-y-3">
              {cartEntries.map((entry) => (
                <div key={entry.product.id} className="grid grid-cols-[1fr_80px] gap-3">
                  <div>
                    <p className="text-sm font-semibold">{entry.product.name}</p>
                    <p className="text-xs text-slate-500">{formatMoney(entry.product.base_price)} đ/{entry.product.unit}</p>
                  </div>
                  <input
                    type="number"
                    min="0.01"
                    step="0.01"
                    value={entry.quantity}
                    onChange={(event) => updateQuantity(entry.product.id, event.target.value)}
                    className="min-h-10 rounded-lg border border-slate-300 px-2"
                  />
                </div>
              ))}
            </div>
          )}

          <form onSubmit={checkout} className="mt-5 space-y-3" noValidate>
            <TextField id="recipient_name" name="recipient_name" label="Người nhận" value={form.recipient_name} onChange={(event) => setForm({ ...form, recipient_name: event.target.value })} error={errors.recipient_name} />
            <TextField id="recipient_phone" name="recipient_phone" label="Số điện thoại" value={form.recipient_phone} onChange={(event) => setForm({ ...form, recipient_phone: event.target.value })} error={errors.recipient_phone} />
            <label className="block">
              <span className="mb-1.5 block text-sm font-medium">Địa chỉ giao hàng</span>
              <textarea name="delivery_address" value={form.delivery_address} onChange={(event) => setForm({ ...form, delivery_address: event.target.value })} rows="3" className="w-full rounded-xl border border-slate-300 px-4 py-3" />
              {errors.delivery_address ? <span className="text-sm text-red-600">{errors.delivery_address}</span> : null}
            </label>
            <p className="border-t border-slate-100 pt-3 text-lg font-bold">Tổng: {formatMoney(total)} đ</p>
            {message ? <p className="rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{message}</p> : null}
            <Button type="submit" className="w-full" disabled={loading}>
              {loading ? 'Đang tạo đơn...' : 'Tạo đơn hàng'}
            </Button>
          </form>
        </aside>
      </section>

      <section className="rounded-2xl bg-white p-5 shadow-sm">
        <h2 className="text-lg font-bold">Đơn hàng của tôi</h2>
        {orders.length === 0 ? <p className="mt-3 text-sm text-slate-500">Chưa có đơn hàng.</p> : (
          <div className="mt-3 space-y-3">
            {orders.map((order) => (
              <article key={order.id} className="rounded-xl border border-slate-200 p-4">
                <div className="flex justify-between gap-3">
                  <p className="font-bold">{order.order_code}</p>
                  <p className="text-sm font-semibold text-emerald-800">{order.status}</p>
                </div>
                <p className="mt-1 text-sm text-slate-600">{order.items.map((item) => `${item.product_name}: ${item.quantity} ${item.unit_price}`).join('; ')}</p>
                {order.items.some((item) => item.allocated_batches?.length) ? (
                  <p className="mt-2 text-xs text-slate-500">
                    Phân bổ FEFO: {order.items.flatMap((item) => item.allocated_batches.map((detail) => `${detail.batch_code} (${detail.quantity})`)).join(', ')}
                  </p>
                ) : null}
              </article>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
