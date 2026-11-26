import { useEffect, useRef, useState } from 'react';
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
  const [messageType, setMessageType] = useState('success');
  const [errors, setErrors] = useState({});
  const [shippingQuote, setShippingQuote] = useState(null);
  const [shippingLoading, setShippingLoading] = useState(false);
  const [shippingError, setShippingError] = useState('');
  const [checkoutLoading, setCheckoutLoading] = useState(false);
  const shippingRequestId = useRef(0);
  const checkoutInFlight = useRef(false);

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
    }).catch((error) => {
      setMessage(apiError(error).message);
      setMessageType('error');
    });
  }, [navigate, user]);

  function addProduct(product) {
    if (checkoutInFlight.current) {
      return;
    }

    setCart((current) => ({
      ...current,
      [product.id]: {
        product,
        quantity: Number(current[product.id]?.quantity || 0) + 1,
      },
    }));
  }

  function updateQuantity(productId, quantity) {
    if (checkoutInFlight.current) {
      return;
    }

    setCart((current) => ({
      ...current,
      [productId]: { ...current[productId], quantity: Number(quantity) },
    }));
  }

  function normalizeAddress(address) {
    return String(address || '').trim().replace(/\s+/g, ' ');
  }

  function updateDeliveryAddress(address) {
    if (checkoutInFlight.current) {
      return;
    }

    shippingRequestId.current += 1;
    setShippingLoading(false);
    setShippingQuote(null);
    setShippingError('');
    setMessage('');
    setErrors((current) => {
      const next = { ...current };
      delete next.delivery_address;
      return next;
    });
    setForm((current) => ({ ...current, delivery_address: address }));
  }

  async function calculateShipping() {
    if (checkoutInFlight.current) {
      return;
    }

    const address = normalizeAddress(form.delivery_address);
    if (!address) {
      setShippingQuote(null);
      setShippingError('Vui lòng nhập địa chỉ giao hàng.');
      return;
    }

    const requestId = ++shippingRequestId.current;
    setShippingLoading(true);
    setShippingQuote(null);
    setShippingError('');
    setMessage('');

    try {
      const response = await axiosClient.post('/shipping/calculate', {
        delivery_address: address,
      });
      if (requestId !== shippingRequestId.current) {
        return;
      }

      const distance = Number(response.data?.distance_km);
      const rate = Number(response.data?.shipping_rate);
      const fee = Number(response.data?.shipping_fee);
      const quoteToken = response.data?.quote_token;
      if (
        !Number.isFinite(distance) || distance < 0
        || !Number.isInteger(rate) || rate <= 0
        || !Number.isInteger(fee) || fee < 0
        || typeof quoteToken !== 'string' || quoteToken.length === 0 || quoteToken.length > 8192
      ) {
        setShippingError('Máy chủ trả báo giá không hợp lệ. Vui lòng tính lại.');
        return;
      }

      setShippingQuote({
        address,
        distance_km: distance,
        shipping_rate: rate,
        shipping_fee: fee,
        quote_token: quoteToken,
      });
    } catch (error) {
      if (requestId !== shippingRequestId.current) {
        return;
      }

      const parsed = apiError(error);
      setShippingError(parsed.errors.delivery_address || parsed.message);
    } finally {
      if (requestId === shippingRequestId.current) {
        setShippingLoading(false);
      }
    }
  }

  async function checkout(event) {
    event.preventEvent?.();
    event.preventDefault();

    if (checkoutInFlight.current) {
      return;
    }

    const items = Object.values(cart)
      .filter((entry) => entry.quantity > 0)
      .map((entry) => ({ product_id: entry.product.id, quantity: entry.quantity }));

    if (items.length === 0) {
      setMessage('Vui lòng chọn ít nhất một sản phẩm.');
      setMessageType('error');
      return;
    }

    const address = normalizeAddress(form.delivery_address);
    if (!shippingQuote?.quote_token || shippingQuote.address !== address) {
      setMessage('Vui lòng tính phí vận chuyển cho địa chỉ hiện tại trước khi đặt hàng.');
      setMessageType('error');
      return;
    }

    checkoutInFlight.current = true;
    setCheckoutLoading(true);
    setMessage('');
    setMessageType('success');
    setErrors({});

    try {
      const response = await axiosClient.post('/customer/orders', {
        ...form,
        quote_token: shippingQuote.quote_token,
        items,
      });
      setOrders((current) => [response.data.data, ...current]);
      setCart({});
      setShippingQuote(null);
      setMessage('Tạo đơn hàng thành công. Đơn đang chờ nhân viên bán hàng xác nhận.');
      setMessageType('success');
    } catch (error) {
      const parsed = apiError(error);
      setMessage(parsed.message);
      setMessageType('error');
      setErrors(parsed.errors);
      if (
        error?.response?.status === 409 ||
        error?.response?.status >= 500 ||
        parsed.errors.delivery_address ||
        parsed.errors.quote_token
      ) {
        setShippingQuote(null);
      }
    } finally {
      checkoutInFlight.current = false;
      setCheckoutLoading(false);
    }
  }

  const cartEntries = Object.values(cart).filter((entry) => entry.quantity > 0);
  const subtotal = cartEntries.reduce(
    (sum, entry) => sum + Number(entry.product.base_price || 0) * Number(entry.quantity || 0),
    0,
  );
  const quoteIsCurrent = Boolean(
    shippingQuote && shippingQuote.address === normalizeAddress(form.delivery_address),
  );
  const total = quoteIsCurrent ? subtotal + shippingQuote.shipping_fee : null;

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
              <Button type="button" variant="secondary" className="mt-4" disabled={checkoutLoading} onClick={() => addProduct(product)}>
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
                    disabled={checkoutLoading}
                    onChange={(event) => updateQuantity(entry.product.id, event.target.value)}
                    className="min-h-10 rounded-lg border border-slate-300 px-2"
                  />
                </div>
              ))}
            </div>
          )}

          <form onSubmit={checkout} className="mt-5 space-y-3" noValidate>
            <TextField id="recipient_name" name="recipient_name" label="Người nhận" value={form.recipient_name} disabled={checkoutLoading} onChange={(event) => setForm({ ...form, recipient_name: event.target.value })} error={errors.recipient_name} />
            <TextField id="recipient_phone" name="recipient_phone" label="Số điện thoại" value={form.recipient_phone} disabled={checkoutLoading} onChange={(event) => setForm({ ...form, recipient_phone: event.target.value })} error={errors.recipient_phone} />
            <label className="block">
              <span className="mb-1.5 block text-sm font-medium">Địa chỉ giao hàng</span>
              <textarea name="delivery_address" value={form.delivery_address} disabled={checkoutLoading} onChange={(event) => updateDeliveryAddress(event.target.value)} rows="3" className="w-full rounded-xl border border-slate-300 px-4 py-3" />
              {errors.delivery_address ? <span className="text-sm text-red-600">{errors.delivery_address}</span> : null}
            </label>
            <Button
              type="button"
              variant="secondary"
              className="w-full"
              disabled={checkoutLoading || shippingLoading || !normalizeAddress(form.delivery_address)}
              onClick={calculateShipping}
            >
              {shippingLoading ? 'Đang tính phí vận chuyển...' : 'Tính phí vận chuyển'}
            </Button>
            {shippingError ? <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{shippingError}</p> : null}
            <div className="space-y-1 border-t border-slate-100 pt-3 text-sm">
              <p>Khoảng cách: {quoteIsCurrent ? `${shippingQuote.distance_km.toLocaleString('vi-VN')} km` : 'Chưa tính'}</p>
              <p>Phí vận chuyển: {quoteIsCurrent ? `${formatMoney(shippingQuote.shipping_fee)} đ` : 'Chưa tính'}</p>
              <p>Tạm tính: {formatMoney(subtotal)} đ</p>
            </div>
            <p className="text-lg font-bold">
              Tổng: {total === null ? 'Chưa có báo giá' : `${formatMoney(total)} đ`}
            </p>
            {message ? (
              <p
                role={messageType === 'error' ? 'alert' : 'status'}
                className={`rounded-xl px-3 py-2 text-sm ${messageType === 'error' ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-800'}`}
              >
                {message}
              </p>
            ) : null}
            <Button
              type="submit"
              className="w-full"
              disabled={checkoutLoading || shippingLoading || !quoteIsCurrent || cartEntries.length === 0}
            >
              {checkoutLoading ? 'Đang tạo đơn...' : 'Tạo đơn hàng'}
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
