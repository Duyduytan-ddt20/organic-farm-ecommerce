import { useEffect, useState } from 'react';
import {
  CalendarDays,
  Download,
  Leaf,
  PackageMinus,
  QrCode,
  RefreshCw,
  Sprout,
  TriangleAlert,
} from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import Button from '../components/Button';
import TextField from '../components/TextField';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../utils/apiError';

function toApiDateTime(value) {
  if (!value) return undefined;
  return value.replace('T', ' ') + (value.length === 16 ? ':00' : '');
}

function formatQuantity(value) {
  return Number(value ?? 0).toLocaleString('vi-VN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

export default function ManagementPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const roleName = user?.role?.role_name;
  const permitted = roleName === 'Staff' || roleName === 'Admin';

  const [batchId, setBatchId] = useState('');
  const [batch, setBatch] = useState(null);
  const [qrSvg, setQrSvg] = useState('');
  const [activeTab, setActiveTab] = useState('qr');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [errors, setErrors] = useState({});

  const [logForm, setLogForm] = useState({
    activity: 'Gieo trồng',
    description: '',
    performed_at: '',
  });
  const [disposalForm, setDisposalForm] = useState({
    quantity: '',
    reason: '',
    note: '',
    disposed_at: '',
  });

  useEffect(() => {
    if (user && !permitted) navigate('/');
  }, [navigate, permitted, user]);

  async function loadBatch(event) {
    event?.preventDefault();
    if (!batchId.trim()) {
      setErrors({ batch_id: 'Vui lòng nhập ID lô.' });
      return;
    }

    setLoading(true);
    setMessage('');
    setErrors({});
    setBatch(null);
    setQrSvg('');

    try {
      const batchResponse = await axiosClient.get(`/batches/${batchId.trim()}`);
      const loadedBatch = batchResponse.data.data;
      setBatch(loadedBatch);

      try {
        const qrResponse = await axiosClient.get(`/batches/${loadedBatch.id}/qr`, {
          responseType: 'text',
        });
        setQrSvg(qrResponse.data);
      } catch (qrError) {
        setErrors({
          qr: qrError.response?.data?.message || 'Không thể tải mã QR của lô.',
        });
      }
    } catch (error) {
      const parsed = apiError(error);
      setErrors({ batch_id: parsed.message });
    } finally {
      setLoading(false);
    }
  }

  function updateLog(event) {
    const { name, value } = event.target;
    setLogForm((current) => ({ ...current, [name]: value }));
  }

  function updateDisposal(event) {
    const { name, value } = event.target;
    setDisposalForm((current) => ({ ...current, [name]: value }));
  }

  async function submitLog(event) {
    event.preventDefault();
    setLoading(true);
    setMessage('');
    setErrors({});

    try {
      await axiosClient.post(`/batches/${batch.id}/farming-logs`, {
        activity: logForm.activity,
        description: logForm.description.trim() === '' ? null : logForm.description.trim(),
        performed_at: toApiDateTime(logForm.performed_at),
      });
      setMessage('Đã ghi nhật ký canh tác.');
      setLogForm({ activity: 'Gieo trồng', description: '', performed_at: '' });
    } catch (error) {
      const parsed = apiError(error);
      setMessage(parsed.message);
      setErrors(parsed.errors);
    } finally {
      setLoading(false);
    }
  }

  async function submitDisposal(event) {
    event.preventDefault();
    setLoading(true);
    setMessage('');
    setErrors({});

    try {
      const response = await axiosClient.post(`/batches/${batch.id}/disposals`, {
        quantity: Number(disposalForm.quantity),
        reason: disposalForm.reason,
        note: disposalForm.note.trim() === '' ? null : disposalForm.note.trim(),
        disposed_at: toApiDateTime(disposalForm.disposed_at),
      });
      setBatch((current) => ({
        ...current,
        available_quantity: response.data.data.available_quantity,
        status: response.data.data.status,
      }));
      setMessage('Đã ghi nhận xuất hủy.');
      setDisposalForm({ quantity: '', reason: '', note: '', disposed_at: '' });
    } catch (error) {
      const parsed = apiError(error);
      setMessage(parsed.message);
      setErrors(parsed.errors);
    } finally {
      setLoading(false);
    }
  }

  function downloadQr() {
    if (!qrSvg) return;
    const url = URL.createObjectURL(new Blob([qrSvg], { type: 'image/svg+xml' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `qr-lo-${batch?.batch_code || batch?.id || 'hang'}.svg`;
    link.click();
    URL.revokeObjectURL(url);
  }

  if (!permitted) return null;

  const batchCode = batch?.batch_code || batch?.ma_lo || 'Chưa xác định';
  const productName = batch?.product?.name || batch?.product?.ten_san_pham || 'Chưa có thông tin';
  const unit = batch?.product?.unit || batch?.product?.don_vi_tinh || '';
  const traceUrl = batch?.qr_token
    ? `${window.location.origin}/truy-xuat/${encodeURIComponent(batch.qr_token)}`
    : '';

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <section className="rounded-2xl bg-white p-5 shadow-sm sm:p-7">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-sm font-semibold text-emerald-700">NHÂN VIÊN NÔNG TRẠI</p>
            <h1 className="mt-1 text-2xl font-bold text-slate-900">Quản lý lô hàng</h1>
            <p className="mt-2 text-sm text-slate-600">Quản lý mã QR, nhật ký canh tác và xuất hủy.</p>
          </div>
          <button
            type="button"
            onClick={() => batchId && loadBatch()}
            disabled={!batchId || loading}
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 disabled:opacity-50"
          >
            <RefreshCw size={17} />
            Làm mới
          </button>
        </div>

        <form onSubmit={loadBatch} className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-start">
          <div className="flex-1">
            <TextField
              id="batch_id"
              name="batch_id"
              type="number"
              label="ID lô hàng"
              placeholder="Ví dụ: 4"
              min="1"
              value={batchId}
              onChange={(event) => setBatchId(event.target.value)}
              error={errors.batch_id}
            />
          </div>
          <Button type="submit" className="sm:mt-[26px]" disabled={loading}>
            {loading ? 'Đang tải...' : 'Tìm lô'}
          </Button>
        </form>
      </section>

      {batch ? (
        <>
          <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <article className="rounded-2xl bg-white p-4 shadow-sm">
              <p className="text-xs text-slate-500">Mã lô</p>
              <p className="mt-1 font-bold text-slate-900">{batchCode}</p>
            </article>
            <article className="rounded-2xl bg-white p-4 shadow-sm">
              <p className="text-xs text-slate-500">Trạng thái</p>
              <p className="mt-1 font-bold capitalize text-slate-900">{batch.status || batch.trang_thai}</p>
            </article>
            <article className="rounded-2xl bg-white p-4 shadow-sm">
              <p className="text-xs text-slate-500">Tồn hiện có</p>
              <p className="mt-1 font-bold text-slate-900">
                {formatQuantity(batch.available_quantity ?? batch.so_luong_ton)} {unit}
              </p>
            </article>
            <article className="rounded-2xl bg-white p-4 shadow-sm">
              <p className="text-xs text-slate-500">Sản phẩm</p>
              <p className="mt-1 truncate font-bold text-slate-900">{productName}</p>
            </article>
          </section>

          <section className="overflow-hidden rounded-2xl bg-white shadow-sm">
            <div className="flex border-b border-slate-100 px-3">
              {[
                { id: 'qr', label: 'Mã QR', Icon: QrCode },
                { id: 'log', label: 'Nhật ký', Icon: Sprout },
                { id: 'disposal', label: 'Xuất hủy', Icon: PackageMinus },
              ].map(({ id, label, Icon }) => (
                <button
                  key={id}
                  type="button"
                  onClick={() => {
                    setActiveTab(id);
                    setMessage('');
                    setErrors({});
                  }}
                  className={`flex min-h-14 flex-1 items-center justify-center gap-2 border-b-2 px-2 text-sm font-semibold ${
                    activeTab === id
                      ? 'border-emerald-700 text-emerald-800'
                      : 'border-transparent text-slate-500'
                  }`}
                >
                  <Icon size={18} />
                  {label}
                </button>
              ))}
            </div>

            <div className="p-5 sm:p-7">
              {errors.qr ? (
                <p className="mb-4 flex items-center gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
                  <TriangleAlert size={18} />
                  {errors.qr}
                </p>
              ) : null}

              {activeTab === 'qr' ? (
                <div className="grid gap-6 md:grid-cols-[260px_1fr] md:items-center">
                  <div className="mx-auto flex h-64 w-64 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    {qrSvg ? (
                      <img
                        src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(qrSvg)}`}
                        alt={`Mã QR lô ${batchCode}`}
                        className="h-full w-full"
                      />
                    ) : (
                      <QrCode className="text-slate-300" size={72} />
                    )}
                  </div>
                  <div>
                    <p className="text-sm font-semibold text-emerald-700">LÔ {batchCode}</p>
                    <h2 className="mt-1 text-xl font-bold text-slate-900">Mã truy xuất nguồn gốc</h2>
                    {traceUrl ? (
                      <div className="mt-4">
                        <p className="text-sm text-slate-500">Liên kết truy xuất</p>
                        <Link
                          to={`/truy-xuat/${encodeURIComponent(batch.qr_token)}`}
                          target="_blank"
                          className="mt-1 block break-all font-semibold text-emerald-800"
                        >
                          {traceUrl}
                        </Link>
                      </div>
                    ) : (
                      <p className="mt-4 text-sm text-amber-700">Lô chưa có mã truy xuất.</p>
                    )}
                    <Button
                      type="button"
                      variant="secondary"
                      className="mt-5"
                      onClick={downloadQr}
                      disabled={!qrSvg}
                    >
                      <Download size={18} />
                      Tải mã QR
                    </Button>
                  </div>
                </div>
              ) : null}

              {activeTab === 'log' ? (
                <form onSubmit={submitLog} className="grid gap-4 sm:grid-cols-2" noValidate>
                  <label className="block">
                    <span className="mb-1.5 block text-sm font-medium text-slate-700">Hoạt động</span>
                    <select
                      name="activity"
                      value={logForm.activity}
                      onChange={updateLog}
                      className="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 outline-none focus:border-emerald-700"
                    >
                      <option>Gieo trồng</option>
                      <option>Chăm sóc</option>
                      <option>Thu hoạch</option>
                    </select>
                  </label>
                  <TextField
                    id="performed_at"
                    name="performed_at"
                    type="datetime-local"
                    label="Thời điểm thực hiện"
                    value={logForm.performed_at}
                    onChange={updateLog}
                    error={errors.performed_at}
                    required
                  />
                  <label className="block sm:col-span-2">
                    <span className="mb-1.5 block text-sm font-medium text-slate-700">Mô tả</span>
                    <textarea
                      name="description"
                      value={logForm.description}
                      onChange={updateLog}
                      rows="4"
                      className="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-emerald-700"
                      placeholder="Nội dung công việc..."
                    />
                    {errors.description ? (
                      <span className="mt-1 block text-sm text-red-600">{errors.description}</span>
                    ) : null}
                  </label>
                  <div className="sm:col-span-2">
                    <Button type="submit" disabled={loading}>
                      <Leaf size={18} />
                      {loading ? 'Đang lưu...' : 'Ghi nhật ký'}
                    </Button>
                  </div>
                </form>
              ) : null}

              {activeTab === 'disposal' ? (
                batch.status === 'closed' || batch.trang_thai === 'closed' ? (
                  <div className="rounded-xl border border-red-100 bg-red-50 p-5 text-red-700">
                    Lô đã đóng, không thể ghi nhận xuất hủy.
                  </div>
                ) : (
                  <form onSubmit={submitDisposal} className="grid gap-4 sm:grid-cols-2" noValidate>
                    <TextField
                      id="quantity"
                      name="quantity"
                      type="number"
                      step="0.01"
                      min="0.01"
                      label={`Số lượng xuất hủy (${unit || 'đơn vị'})`}
                      value={disposalForm.quantity}
                      onChange={updateDisposal}
                      error={errors.quantity}
                      required
                    />
                    <TextField
                      id="disposed_at"
                      name="disposed_at"
                      type="datetime-local"
                      label="Thời điểm xuất hủy"
                      value={disposalForm.disposed_at}
                      onChange={updateDisposal}
                      error={errors.disposed_at}
                    />
                    <TextField
                      id="reason"
                      name="reason"
                      label="Nguyên nhân"
                      value={disposalForm.reason}
                      onChange={updateDisposal}
                      error={errors.reason}
                      required
                    />
                    <label className="block">
                      <span className="mb-1.5 block text-sm font-medium text-slate-700">Ghi chú</span>
                      <textarea
                        name="note"
                        value={disposalForm.note}
                        onChange={updateDisposal}
                        rows="3"
                        className="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-emerald-700"
                      />
                      {errors.note ? (
                        <span className="mt-1 block text-sm text-red-600">{errors.note}</span>
                      ) : null}
                    </label>
                    <div className="sm:col-span-2">
                      <Button type="submit" variant="danger" disabled={loading}>
                        <PackageMinus size={18} />
                        {loading ? 'Đang lưu...' : 'Ghi nhận xuất hủy'}
                      </Button>
                    </div>
                  </form>
                )
              ) : null}

              {message ? (
                <p
                  className={`mt-5 rounded-xl px-4 py-3 text-sm ${
                    Object.keys(errors).length
                      ? 'bg-red-50 text-red-700'
                      : 'bg-emerald-50 text-emerald-800'
                  }`}
                  role="status"
                >
                  {message}
                </p>
              ) : null}
            </div>
          </section>
        </>
      ) : (
        <section className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
          <CalendarDays className="mx-auto text-slate-300" size={36} />
          <p className="mt-3 font-medium">Nhập ID lô để xem QR và thực hiện quản lý.</p>
        </section>
      )}
    </div>
  );
}
