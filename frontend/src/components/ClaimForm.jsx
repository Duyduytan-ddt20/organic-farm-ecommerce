import { useEffect, useRef, useState } from 'react';
import { AlertCircle, ImagePlus, Trash2 } from 'lucide-react';
import axiosClient from '../api/axiosClient';
import Button from './Button';
import { CLAIM_REASONS } from '../utils/claimReasons';
import { validateClaimImage } from '../utils/validateClaimImage';

export default function ClaimForm({ order, onCreated }) {
  const [orderItemId, setOrderItemId] = useState(String(order.items?.[0]?.id ?? ''));
  const [reason, setReason] = useState(CLAIM_REASONS[0]);
  const [description, setDescription] = useState('');
  const [claimedQuantity, setClaimedQuantity] = useState('1');
  const [files, setFiles] = useState([]);
  const filesRef = useRef([]);
  const [fileError, setFileError] = useState('');
  const [formError, setFormError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [createdClaim, setCreatedClaim] = useState(null);

  const selectedItem = order.items?.find((item) => String(item.id) === String(orderItemId));

  useEffect(() => () => {
    filesRef.current.forEach(({ preview }) => URL.revokeObjectURL(preview));
  }, []);

  function handleFiles(event) {
    const picked = Array.from(event.target.files ?? []);
    event.target.value = '';

    const errors = picked
      .map((file) => {
        const message = validateClaimImage(file);
        return message ? `${file.name}: ${message}` : null;
      })
      .filter(Boolean);

    if (errors.length > 0) {
      setFileError(errors.join(' '));
      return;
    }

    setFileError('');
    const remainingSlots = Math.max(0, 5 - filesRef.current.length);
    const added = picked.slice(0, remainingSlots).map((file) => ({
      file,
      preview: URL.createObjectURL(file),
    }));
    filesRef.current = [...filesRef.current, ...added];
    setFiles(filesRef.current);
  }

  function removeFile(index) {
    const removed = filesRef.current[index];
    if (removed) URL.revokeObjectURL(removed.preview);
    filesRef.current = filesRef.current.filter((_, itemIndex) => itemIndex !== index);
    setFiles(filesRef.current);
    setFileError('');
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setFormError('');

    const blocked = files
      .map(({ file }) => {
        const message = validateClaimImage(file);
        return message ? `${file.name}: ${message}` : null;
      })
      .filter(Boolean);

    if (files.length === 0 || blocked.length > 0) {
      setFileError(blocked.join(' ') || 'Chọn ít nhất một ảnh .jpg, .png hoặc .webp dưới 5MB.');
      return;
    }

    const formData = new FormData();
    formData.append('order_item_id', orderItemId);
    formData.append('reason', reason);
    formData.append('description', description);
    formData.append('claimed_quantity', claimedQuantity);
    files.forEach(({ file }) => formData.append('images[]', file));

    setSubmitting(true);
    try {
      const response = await axiosClient.post(`/orders/${order.id}/claims`, formData);
      setCreatedClaim(response.data.data);
      filesRef.current.forEach(({ preview }) => URL.revokeObjectURL(preview));
      filesRef.current = [];
      setFiles([]);
      setDescription('');
      onCreated?.(response.data.data);
    } catch (error) {
      const payload = error.response?.data;
      const firstFieldError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
      setFormError(firstFieldError || payload?.message || 'Không gửi được khiếu nại.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
      <h2 className="text-lg font-bold text-slate-900">Gửi yêu cầu khiếu nại</h2>
      <p className="mt-1 text-sm text-slate-500">
        Ảnh được kiểm tra ngay khi chọn. Chỉ .jpg, .png, .webp và nhỏ hơn 5MB mới được gửi lên Storage.
      </p>

      <form className="mt-4 space-y-4" onSubmit={handleSubmit}>
        <label className="block text-sm font-medium text-slate-700">
          Sản phẩm
          <select
            className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-3"
            value={orderItemId}
            onChange={(event) => setOrderItemId(event.target.value)}
          >
            {order.items?.map((item) => (
              <option key={item.id} value={item.id}>
                {item.product?.name ?? `Sản phẩm #${item.product_id}`} — {item.quantity} {item.product?.unit ?? ''}
              </option>
            ))}
          </select>
        </label>

        <label className="block text-sm font-medium text-slate-700">
          Số lượng khiếu nại
          <input
            className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3"
            type="number"
            min="0.01"
            max={selectedItem?.quantity ?? undefined}
            step="0.01"
            required
            value={claimedQuantity}
            onChange={(event) => setClaimedQuantity(event.target.value)}
          />
        </label>

        <label className="block text-sm font-medium text-slate-700">
          Lý do
          <select
            className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-3"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
          >
            {CLAIM_REASONS.map((item) => (
              <option key={item} value={item}>
                {item}
              </option>
            ))}
          </select>
        </label>

        <label className="block text-sm font-medium text-slate-700">
          Mô tả
          <textarea
            className="mt-1 min-h-24 w-full rounded-xl border border-slate-300 px-3 py-3"
            value={description}
            maxLength={2000}
            onChange={(event) => setDescription(event.target.value)}
            placeholder="Mô tả tình trạng hàng khi nhận"
          />
        </label>

        <div>
          <label className="inline-flex min-h-12 cursor-pointer items-center gap-2 rounded-xl border border-dashed border-emerald-400 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
            <ImagePlus size={18} />
            Chọn ảnh minh chứng
            <input
              className="sr-only"
              type="file"
              accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
              multiple
              aria-invalid={fileError ? 'true' : 'false'}
              onChange={handleFiles}
            />
          </label>
          {fileError && (
            <p role="alert" className="mt-3 flex gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
              <AlertCircle size={18} className="mt-0.5 shrink-0" />
              <span>{fileError}</span>
            </p>
          )}
          {files.length > 0 && (
            <ul className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
              {files.map(({ file, preview }, index) => (
                <li key={`${file.name}-${file.lastModified}-${index}`} className="overflow-hidden rounded-xl border border-slate-200">
                  <img src={preview} alt={file.name} className="h-28 w-full object-cover" />
                  <div className="flex items-center justify-between gap-2 px-2 py-2 text-xs text-slate-600">
                    <span className="truncate">{file.name}</span>
                    <button
                      type="button"
                      className="inline-flex min-h-9 shrink-0 items-center gap-1 rounded-lg px-2 text-red-700 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600"
                      onClick={() => removeFile(index)}
                      aria-label={`Xóa ${file.name}`}
                    >
                      <Trash2 size={16} />
                      <span>Xóa</span>
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>

        {formError && (
          <p role="alert" className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
            {formError}
          </p>
        )}

        <Button type="submit" disabled={submitting || Boolean(fileError) || files.length === 0}>
          {submitting ? 'Đang gửi...' : 'Gửi khiếu nại'}
        </Button>
      </form>

      {createdClaim && (
        <div className="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
          <p className="font-semibold">Đã lưu khiếu nại #{createdClaim.id} và ảnh minh chứng vào Storage.</p>
          <p className="mt-1">Bạn có thể xem hoặc xóa ảnh trong phần lịch sử khiếu nại bên dưới.</p>
        </div>
      )}
    </section>
  );
}
