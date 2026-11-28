import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import { apiError } from '../utils/apiError';

export default function PaymentResultPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const paymentReference = searchParams.get('vnp_TxnRef')
        || searchParams.get('orderId')
        || searchParams.get('payment_reference');
    const [payment, setPayment] = useState(null);
    const [errorMessage, setErrorMessage] = useState('');
    const [retry, setRetry] = useState(0);
    const [demoLoading, setDemoLoading] = useState(false);
    const [demoError, setDemoError] = useState('');

    useEffect(() => {
        if (!paymentReference) return undefined;

        let cancelled = false;
        let timer;
        let attempts = 0;

        async function checkStatus() {
            try {
                const response = await axiosClient.get(
                    `/payments/${encodeURIComponent(paymentReference)}`,
                );
                if (cancelled) return;

                setPayment(response.data);
                setErrorMessage('');
                if (response.data.payment_status === 'pending' && attempts < 10) {
                    attempts += 1;
                    timer = window.setTimeout(checkStatus, 1500);
                }
            } catch (error) {
                if (!cancelled) setErrorMessage(apiError(error).message);
            }
        }

        checkStatus();
        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [paymentReference, retry]);

    const isPaid = payment?.payment_status === 'succeeded';
    const isFailed = payment?.payment_status === 'failed';

    async function confirmDemoPayment() {
        if (!payment?.order_id || demoLoading) return;

        setDemoLoading(true);
        setDemoError('');
        try {
            const response = await axiosClient.post('/payments/demo/confirm', {
                order_id: payment.order_id,
            });
            const demoPaymentReference = response?.data?.payment_reference;
            if (!demoPaymentReference) {
                throw new Error('Backend không trả về mã giao dịch demo.');
            }
            setSearchParams({ payment_reference: demoPaymentReference }, { replace: true });
        } catch (error) {
            setDemoError(apiError(error).message);
        } finally {
            setDemoLoading(false);
        }
    }

    return (
        <section className="mx-auto max-w-2xl rounded-2xl bg-white p-6 shadow-sm sm:p-8">
            <h1 className="text-2xl font-bold text-slate-900">
                {isPaid
                    ? 'Thanh toán thành công'
                    : isFailed
                        ? 'Thanh toán chưa thành công'
                        : 'Đang xác nhận thanh toán'}
            </h1>

            {!paymentReference ? (
                <p className="mt-3 text-sm text-red-700">Không tìm thấy mã giao dịch trong kết quả trả về.</p>
            ) : null}
            {errorMessage ? <p role="alert" className="mt-3 text-sm text-red-700">{errorMessage}</p> : null}
            {payment?.payment_reference ? (
                <dl className="mt-5 space-y-2 text-sm text-slate-700">
                    <div className="flex justify-between gap-4">
                        <dt>Mã giao dịch</dt>
                        <dd className="font-medium">{payment.payment_reference}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt>Số tiền</dt>
                        <dd className="font-medium">{Number(payment.amount).toLocaleString('vi-VN')} đ</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt>Trạng thái</dt>
                        <dd className="font-medium">
                            {isPaid ? 'Đã thanh toán' : isFailed ? 'Thất bại' : 'Đang chờ IPN từ cổng thanh toán'}
                        </dd>
                    </div>
                </dl>
            ) : null}
            {payment?.payment_status === 'pending' ? (
                <p className="mt-4 text-sm text-slate-600">
                    Kết quả chuyển về trình duyệt chưa xác nhận thanh toán. Hệ thống đang chờ IPN xác thực từ nhà cung cấp.
                </p>
            ) : null}
            {isPaid && payment?.order_status === 'pending' ? (
                <p className="mt-4 text-sm text-amber-800">
                    Thanh toán demo đã được ghi nhận. Đơn hàng đang chờ nhân viên Sales xác nhận và phân bổ FEFO.
                </p>
            ) : null}
            {import.meta.env.DEV && isFailed && payment?.order_status === 'pending' ? (
                <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p className="text-sm text-amber-900">
                        Không có MoMo UAT? Bạn có thể mô phỏng thanh toán cho đơn này ở môi trường local. Không thu tiền thật.
                    </p>
                    {demoError ? <p role="alert" className="mt-2 text-sm text-red-700">{demoError}</p> : null}
                    <button
                        type="button"
                        onClick={confirmDemoPayment}
                        disabled={demoLoading}
                        className="mt-3 rounded-xl bg-amber-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {demoLoading ? 'Đang xác nhận demo...' : 'Mô phỏng thanh toán thành công'}
                    </button>
                </div>
            ) : null}

            <div className="mt-6 flex flex-wrap gap-3">
                {payment?.payment_status === 'pending' ? (
                    <button
                        type="button"
                        onClick={() => setRetry((value) => value + 1)}
                        className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700"
                    >
                        Kiểm tra lại
                    </button>
                ) : null}
                <Link
                    to="/don-hang"
                    className="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
                >
                    Xem đơn hàng
                </Link>
            </div>
        </section>
    );
}
