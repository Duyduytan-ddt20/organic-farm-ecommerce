import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import Button from '../components/Button';
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
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!user) {
      navigate('/dang-nhap');
      return;
    }

    Promise.all([
      axiosClient.get('/products'),
      axiosClient.get('/customer/orders'),
    ]).then(([productsResponse, ordersResponse]) => {
      setProducts(productsResponse.data ?? []);
      setOrders(ordersResponse.data ?? []);
    }).catch((error) => {
      setMessage(apiError(error).message);
    }).finally(() => {
      setLoading(false);
    });
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

  function checkout() {
    const items = Object.values(cart)
      .filter((entry) => entry.quantity > 0)
      .map((entry) => ({
        product_id: entry.product.id,
        name: entry.product.name,
        unit: entry.product.unit,
        unit_price: Number(entry.product.base_price),
        quantity: entry.quantity,
      }));

    if (items.length === 0) {
      setMessage('Vui lòng chọn ít nhất một sản phẩm.');
      return;
    }

    navigate('/thanh-toan', { state: { cartItems: items } });
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
                Thêm vào giỏ
              </Button>
            </article>
          ))}
          {!loading && products.length === 0 ? (
            <p className="text-sm text-slate-500">Chưa có sản phẩm đang kinh doanh.</p>
          ) : null}
        </div>

        <aside className="h-fit rounded-2xl bg-white p-5 shadow-sm">
          <h2 className="text-xl font-bold text-slate-900">Giỏ hàng</h2>
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
                    min="0"
                    step="0.01"
                    value={entry.quantity}
                    onChange={(event) => updateQuantity(entry.product.id, event.target.value)}
                    className="min-h-10 rounded-lg border border-slate-300 px-2"
                  />
                </div>
              ))}
            </div>
          )}

          <p className="mt-5 border-t border-slate-100 pt-3 text-lg font-bold">
            Tạm tính: {formatMoney(total)} đ
          </p>
          {message ? <p className="mt-3 text-sm text-red-600">{message}</p> : null}
          <Button type="button" className="mt-4 w-full" disabled={cartEntries.length === 0} onClick={checkout}>
            Tiến hành thanh toán
          </Button>
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
                <p className="mt-1 text-sm text-slate-600">
                  {order.items.map((item) => `${item.product_name}: ${item.quantity} ${item.unit_price}`).join('; ')}
                </p>
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
