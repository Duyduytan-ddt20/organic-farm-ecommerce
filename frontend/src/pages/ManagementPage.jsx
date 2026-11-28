import { useEffect, useRef, useState } from 'react';
import {
  CalendarDays,
  Download,
  Leaf,
  PackageMinus,
  QrCode,
  RefreshCw,
  Search,
  Sprout,
  TriangleAlert,
} from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import Button from '../components/Button';
import TextField from '../components/TextField';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../utils/apiError';
import { normalizeSearchText } from '../utils/searchText';

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

const batchStatusLabels = {
  growing: 'Đang trồng',
  available: 'Sẵn sàng',
  blocked: 'Tạm khóa',
  closed: 'Đã đóng',
};

export default function ManagementPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const roleName = user?.role?.role_name;
  const permitted = roleName === 'Staff' || roleName === 'Admin';

  const [batchId, setBatchId] = useState('');
  const [batches, setBatches] = useState([]);
  const [batchSearch, setBatchSearch] = useState('');
  const [batchesLoading, setBatchesLoading] = useState(true);
  const [batchesError, setBatchesError] = useState('');
  const [batchesRefreshKey, setBatchesRefreshKey] = useState(0);
  const [batchActionError, setBatchActionError] = useState('');
  const [batch, setBatch] = useState(null);
  const batchDetailsRef = useRef(null);
  const batchToScrollRef = useRef(null);
  const [qrSvg, setQrSvg] = useState('');
  const [farmingLogs, setFarmingLogs] = useState([]);
  const [disposals, setDisposals] = useState([]);
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
  const [logImage, setLogImage] = useState(null);
  const [disposalImage, setDisposalImage] = useState(null);

  useEffect(() => {
    if (user && !permitted) navigate('/');
  }, [navigate, permitted, user]);

  useEffect(() => {
    if (!permitted) {
      return undefined;
    }

    let active = true;
    axiosClient.get('/batches')
      .then((response) => {
        if (!Array.isArray(response.data)) {
          throw new Error('Máy chủ trả về danh sách lô hàng không hợp lệ.');
        }
        if (active) {
          setBatches(response.data);
          setBatchesError('');
        }
      })
      .catch((error) => {
        if (active) {
          setBatchesError(apiError(error).message);
        }
      })
      .finally(() => {
        if (active) {
          setBatchesLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [batchesRefreshKey, permitted]);

  useEffect(() => {
    if (batch && batchToScrollRef.current === batch.id) {
      batchDetailsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      batchToScrollRef.current = null;
    }
  }, [batch]);

  async function loadBatch(event, requestedBatchId = batchId) {
    event?.preventDefault();
    const selectedBatchId = String(requestedBatchId).trim();
    if (!selectedBatchId) {
      setErrors({ batch_id: 'Vui lòng nhập ID lô.' });
      return;
    }

    setBatchId(selectedBatchId);
    setBatchActionError('');
    batchToScrollRef.current = Number(selectedBatchId);
    setLoading(true);
    setMessage('');
    setErrors({});
    setBatch(null);
    setQrSvg('');

    try {
      const batchResponse = await axiosClient.get(`/batches/${selectedBatchId}`);
      const loadedBatch = batchResponse.data;
      setBatch(loadedBatch);

      const [logsResponse, disposalsResponse] = await Promise.all([
        axiosClient.get(`/batches/${loadedBatch.id}/farming-logs`),
        axiosClient.get(`/batches/${loadedBatch.id}/disposals`),
      ]);
      if (!Array.isArray(logsResponse.data) || !Array.isArray(disposalsResponse.data)) {
        throw new Error('Máy chủ trả về lịch sử lô hàng không hợp lệ.');
      }
      setFarmingLogs(logsResponse.data);
      setDisposals(disposalsResponse.data);

      try {
        const qrResponse = await axiosClient.get(`/batches/${loadedBatch.id}/qr`, {
          responseType: 'text',
        });
        setQrSvg(qrResponse);
      } catch (qrError) {
        setErrors({
          qr: qrError.response?.data?.message || 'Không thể tải mã QR của lô.',
        });
      }
    } catch (error) {
      const parsed = apiError(error);
      batchToScrollRef.current = null;
      setBatchActionError(parsed.message);
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
      const formData = new FormData();
      formData.append('activity', logForm.activity);
      if (logForm.description.trim()) formData.append('description', logForm.description.trim());
      formData.append('performed_at', toApiDateTime(logForm.performed_at));
      if (logImage) formData.append('image', logImage);

      const response = await axiosClient.post(`/batches/${batch.id}/farming-logs`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setFarmingLogs((current) => [...current, response.data]);
      setMessage('Đã ghi nhật ký canh tác.');
      setLogForm({ activity: 'Gieo trồng', description: '', performed_at: '' });
      setLogImage(null);
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
      const formData = new FormData();
      formData.append('quantity', String(Number(disposalForm.quantity)));
      formData.append('reason', disposalForm.reason);
      if (disposalForm.note.trim()) formData.append('note', disposalForm.note.trim());
      if (disposalForm.disposed_at) formData.append('disposed_at', toApiDateTime(disposalForm.disposed_at));
      if (disposalImage) formData.append('image', disposalImage);

      const response = await axiosClient.post(`/batches/${batch.id}/disposals`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      const disposal = response.data;
      setBatch((current) => ({
        ...current,
        available_quantity: disposal.available_quantity,
        status: disposal.status,
      }));
      setBatches((current) => current.map((listedBatch) => (
        listedBatch.id === batch.id
          ? {
            ...listedBatch,
            available_quantity: disposal.available_quantity,
            status: disposal.status,
          }
          : listedBatch
      )));
      setMessage('Đã ghi nhận xuất hủy.');
      setDisposalForm({ quantity: '', reason: '', note: '', disposed_at: '' });
      setDisposalImage(null);
      setDisposals((current) => [disposal, ...current]);
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
  const batchSearchTerm = normalizeSearchText(batchSearch);
  const visibleBatches = batches.filter((listedBatch) => (
    !batchSearchTerm
    || normalizeSearchText([
      listedBatch.batch_code,
      listedBatch.id,
      listedBatch.product?.name,
      listedBatch.growing_location,
      batchStatusLabels[listedBatch.status] || listedBatch.status,
    ].filter(Boolean).join(' ')).includes(batchSearchTerm)
  ));
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

      <section className="rounded-2xl bg-white p-5 shadow-sm sm:p-7">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-xl font-bold text-slate-900">Danh sách lô hàng</h2>
            <p className="mt-1 text-sm text-slate-600">Chọn một lô để xem QR, nhật ký canh tác và xuất hủy.</p>
          </div>
          <button
            type="button"
            onClick={() => {
              setBatchesError('');
              setBatchesLoading(true);
              setBatchesRefreshKey((current) => current + 1);
            }}
            disabled={batchesLoading}
            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 disabled:opacity-50"
          >
            <RefreshCw size={16} />
            Tải lại danh sách
          </button>
        </div>

        {batchesLoading ? (
          <p role="status" className="mt-4 text-sm text-slate-600">Đang tải lô hàng...</p>
        ) : null}
        {batchesError ? (
          <p role="alert" className="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-700">{batchesError}</p>
        ) : null}
        {batchActionError ? (
          <p role="alert" className="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-700">
            Không thể mở lô: {batchActionError}
          </p>
        ) : null}
        <label className="relative mt-4 block">
          <span className="sr-only">Tìm kiếm lô hàng</span>
          <Search
            aria-hidden="true"
            size={18}
            className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"
          />
          <input
            type="search"
            value={batchSearch}
            onChange={(event) => setBatchSearch(event.target.value)}
            placeholder="Tìm theo mã lô, sản phẩm, ID hoặc khu vực"
            className="min-h-12 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-base text-slate-900 outline-none focus:border-emerald-700"
          />
        </label>
        {!batchesLoading && !batchesError && batches.length === 0 ? (
          <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Chưa có lô hàng nào.</p>
        ) : null}
        {!batchesLoading && !batchesError && batches.length > 0 && visibleBatches.length === 0 ? (
          <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Không tìm thấy lô hàng phù hợp.</p>
        ) : null}
        {batches.length > 0 ? (
          <div className="mt-4 grid gap-3 md:grid-cols-2">
            {visibleBatches.map((listedBatch) => (
              <article
                key={listedBatch.id}
                className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-4"
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-bold text-slate-900">{listedBatch.batch_code}</p>
                    <span className="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800">
                      {batchStatusLabels[listedBatch.status] || listedBatch.status}
                    </span>
                  </div>
                  <p className="mt-1 truncate text-sm text-slate-600">
                    {listedBatch.product?.name || 'Chưa có thông tin sản phẩm'} · ID {listedBatch.id}
                  </p>
                  <p className="mt-1 text-xs text-slate-500">
                    Tồn: {formatQuantity(listedBatch.available_quantity)} {listedBatch.product?.unit || ''}
                    {' · Hạn dùng: '}
                    {listedBatch.expires_at ? String(listedBatch.expires_at).slice(0, 10) : 'Chưa cập nhật'}
                  </p>
                </div>
                <Button
                  type="button"
                  variant="secondary"
                  className="min-h-10 shrink-0 px-3 py-2 text-sm"
                  disabled={loading}
                  onClick={() => loadBatch(undefined, listedBatch.id)}
                >
                  {loading && batchId === String(listedBatch.id) ? 'Đang tải...' : 'Xem lô'}
                </Button>
              </article>
            ))}
          </div>
        ) : null}
      </section>

      {batch ? (
        <>
          <section ref={batchDetailsRef} className="scroll-mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
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
                  <label className="block sm:col-span-2">
                    <span className="mb-1.5 block text-sm font-medium text-slate-700">Ảnh minh chứng</span>
                    <input
                      type="file"
                      accept="image/jpeg,image/png,image/webp"
                      onChange={(event) => setLogImage(event.target.files?.[0] ?? null)}
                      className="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:font-semibold file:text-emerald-800"
                    />
                  </label>
                  <div className="sm:col-span-2">
                    <Button type="submit" disabled={loading}>
                      <Leaf size={18} />
                      {loading ? 'Đang lưu...' : 'Ghi nhật ký'}
                    </Button>
                  </div>
                </form>
              ) : null}

              {activeTab === 'log' && farmingLogs.length > 0 ? (
                <div className="mt-6 border-t border-slate-100 pt-5">
                  <h3 className="font-bold text-slate-900">Lịch sử nhật ký canh tác</h3>
                  <div className="mt-3 space-y-3">
                    {[...farmingLogs].reverse().map((log) => (
                      <article key={log.id} className="rounded-xl border border-slate-200 p-4">
                        <p className="font-semibold text-slate-900">{log.activity}</p>
                        {log.description ? <p className="mt-1 text-sm text-slate-600">{log.description}</p> : null}
                        <p className="mt-2 text-xs text-slate-500">
                          {log.performed_at} {log.staff?.full_name ? `· ${log.staff.full_name}` : ''}
                        </p>
                        {log.image_url ? (
                          <a href={log.image_url} target="_blank" rel="noreferrer" className="mt-2 inline-block text-sm font-semibold text-emerald-700">
                            Xem ảnh minh chứng
                          </a>
                        ) : null}
                      </article>
                    ))}
                  </div>
                </div>
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
                    <label className="block">
                      <span className="mb-1.5 block text-sm font-medium text-slate-700">Ảnh minh chứng</span>
                      <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        onChange={(event) => setDisposalImage(event.target.files?.[0] ?? null)}
                        className="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-red-50 file:px-3 file:py-2 file:font-semibold file:text-red-700"
                      />
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

              {activeTab === 'disposal' && disposals.length > 0 ? (
                <div className="mt-6 border-t border-slate-100 pt-5">
                  <h3 className="font-bold text-slate-900">Lịch sử xuất hủy</h3>
                  <div className="mt-3 space-y-3">
                    {disposals.map((disposal) => (
                      <article key={disposal.id} className="rounded-xl border border-slate-200 p-4">
                        <p className="font-semibold text-slate-900">
                          {formatQuantity(disposal.quantity)} {unit} - {disposal.reason}
                        </p>
                        {disposal.note ? <p className="mt-1 text-sm text-slate-600">{disposal.note}</p> : null}
                        <p className="mt-2 text-xs text-slate-500">
                          {disposal.disposed_at} {disposal.staff?.full_name ? `· ${disposal.staff.full_name}` : ''}
                        </p>
                        {disposal.image_url ? (
                          <a href={disposal.image_url} target="_blank" rel="noreferrer" className="mt-2 inline-block text-sm font-semibold text-red-700">
                            Xem ảnh minh chứng
                          </a>
                        ) : null}
                      </article>
                    ))}
                  </div>
                </div>
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
