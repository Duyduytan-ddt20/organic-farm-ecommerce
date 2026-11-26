import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../utils/apiError';

function formatMoney(value) {
    return Number(value || 0).toLocaleString('vi-VN');
}

export default function CheckoutPage() {
    const location = useLocation();
    const navigate = useNavigate();
    const { user } = useAuth();
    const items = location.state?.cartItems ?? [];
    const [form, setForm] = useState({
        recipient_name: user?.full_name ?? '',
        recipient_phone: user?.phone ?? '',
        delivery_address: '',
    });
    const [paymentMethod, setPaymentMethod] = useState('vnpay_sandbox');
    const [submitting, setSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState('');
    const [pendingOrderId, setPendingOrderId] = useState(null);

    const subtotal = items.reduce(
        (sum, item) => sum + Number(item.unit_price || 0) * Number(item.quantity || 0),
        0,
    );
    const shippingFee = 21000;
    const total = subtotal + shippingFee;

    async function handlePlaceOrder(event) {
        event.preventDefault();
        if (items.length === 0) {
            setErrorMessage('Giỏ hàng đang trống. Vui lòng chọn sản phẩm trước.');
            return;
        }

        setSubmitting(true);
        setErrorMessage('');
        try {
            let orderId = pendingOrderId;
            if (!orderId) {
                const orderResponse = await axiosClient.post('/customer/orders', {
                    ...form,
                    shipping_fee: shippingFee,
                    items: items.map((item) => ({
                        product_id: item.product_id,
                        quantity: item.quantity,
                    })),
                });
                orderId = orderResponse.data.id;
                setPendingOrderId(orderId);
            }

            if (paymentMethod === 'cod') {
                navigate('/don-hang');
                return;
            }

            const provider = paymentMethod === 'momo_sandbox' ? 'momo' : 'vnpay';
            const paymentResponse = await axiosClient.post(`/payments/${provider}/create`, {
                order_id: orderId,
            });
            const paymentUrl = paymentResponse.data?.payment_url;
            if (!paymentUrl) {
                throw new Error('Cổng thanh toán không trả về liên kết hợp lệ.');
            }

            const destination = new URL(paymentUrl);
            if (!['https:', 'http:'].includes(destination.protocol)) {
                throw new Error('Liên kết thanh toán không hợp lệ.');
            }

            window.location.assign(destination.href);
        } catch (error) {
            setErrorMessage(error.response?.data?.message || error.message || apiError(error).message);
        } finally {
            setSubmitting(false);
        }
    }

    if (items.length === 0) {
        return (
            <section className="mx-auto max-w-2xl rounded-2xl bg-white p-6 text-center shadow-sm">
                <h1 className="text-2xl font-bold text-slate-900">Giỏ hàng đang trống</h1>
                <p className="mt-2 text-sm text-slate-600">Hãy chọn sản phẩm trước khi thanh toán.</p>
                <Link
                    to="/mua-sam"
                    className="mt-5 inline-flex rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-800"
                >
                    Quay lại mua sắm
                </Link>
            </section>
        );
    }

    return (
        <div className="mx-auto max-w-4xl p-4 sm:p-6">
            <h1 className="text-2xl font-bold text-slate-900">Thanh toán đơn hàng</h1>

            <form onSubmit={handlePlaceOrder} className="mt-6 grid gap-6 md:grid-cols-5">
                <div className="space-y-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm md:col-span-3">
                    <div>
                        <label htmlFor="recipient_name" className="block text-sm font-medium text-slate-700">Người nhận hàng</label>
                        <input
                            id="recipient_name"
                            type="text"
                            required
                            maxLength={100}
                            value={form.recipient_name}
                            onChange={(event) => setForm({ ...form, recipient_name: event.target.value })}
                            className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm"
                        />
                    </div>

                    <div>
                        <label htmlFor="recipient_phone" className="block text-sm font-medium text-slate-700">Số điện thoại</label>
                        <input
                            id="recipient_phone"
                            type="tel"
                            required
                            maxLength={20}
                            value={form.recipient_phone}
                            onChange={(event) => setForm({ ...form, recipient_phone: event.target.value })}
                            className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm"
                        />
                    </div>

                    <div>
                        <label htmlFor="delivery_address" className="block text-sm font-medium text-slate-700">Địa chỉ giao hàng</label>
                        <textarea
                            id="delivery_address"
                            required
                            maxLength={500}
                            rows={3}
                            value={form.delivery_address}
                            onChange={(event) => setForm({ ...form, delivery_address: event.target.value })}
                            className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm"
                        />
                    </div>

                    <fieldset>
                        <legend className="block text-sm font-medium text-slate-700">Phương thức thanh toán</legend>
                        <div className="mt-2 grid gap-3 sm:grid-cols-3">
                            {[
                                ['vnpay_sandbox', 'VNPay Sandbox'],
                                ['momo_sandbox', 'MoMo Sandbox'],
                                ['cod', 'Thanh toán khi nhận'],
                            ].map(([value, label]) => (
                                <label
                                    key={value}
                                    className={`cursor-pointer rounded-xl border p-3 text-sm font-medium ${
                                        paymentMethod === value
                                            ? 'border-emerald-700 bg-emerald-50 text-emerald-800'
                                            : 'border-slate-200 text-slate-700'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value={value}
                                        checked={paymentMethod === value}
                                        onChange={() => setPaymentMethod(value)}
                                        className="sr-only"
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                </div>

                <aside className="flex flex-col justify-between rounded-2xl border border-slate-100 bg-white p-5 shadow-sm md:col-span-2">
                    <div>
                        <h2 className="font-semibold text-slate-900">Tóm tắt đơn hàng</h2>
                        <ul className="mt-4 divide-y divide-slate-100 text-sm">
                            {items.map((item) => (
                                <li key={item.product_id} className="flex justify-between gap-3 py-2">
                                    <span>{item.name} × {item.quantity} {item.unit}</span>
                                    <span className="font-medium">
                                        {formatMoney(item.unit_price * item.quantity)} đ
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-4 flex justify-between border-t border-slate-100 pt-3 text-base font-bold text-emerald-800">
                            <span>Tạm tính</span>
                            <span>{formatMoney(subtotal)} đ</span>
                        </div>
                        <div className="mt-2 flex justify-between text-sm text-slate-600">
                            <span>Phí giao hàng dự kiến</span>
                            <span>{formatMoney(shippingFee)} đ</span>
                        </div>
                        <div className="mt-2 flex justify-between border-t border-slate-100 pt-3 text-base font-bold text-emerald-800">
                            <span>Tổng thanh toán</span>
                            <span>{formatMoney(total)} đ</span>
                        </div>
                    </div>

                    {errorMessage ? (
                        <p role="alert" className="mt-4 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">
                            {errorMessage}
                        </p>
                    ) : null}

                    <button
                        type="submit"
                        disabled={submitting}
                        className="mt-6 w-full rounded-xl bg-emerald-700 py-3 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {submitting ? 'Đang xử lý...' : paymentMethod === 'cod' ? 'Đặt hàng' : 'Tiếp tục thanh toán'}
                    </button>
                </aside>
            </form>
        </div>
    );
}
