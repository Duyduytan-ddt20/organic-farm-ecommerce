import { useEffect, useRef, useState } from 'react';
import { Trash2 } from 'lucide-react';
import axiosClient from '../api/axiosClient';
import { CLAIM_REASONS } from '../utils/claimReasons';
import { validateClaimImage } from '../utils/validateClaimImage';

const MAX_CLAIM_IMAGES = 5;

export default function EditClaimForm({ claim, orderItem, onSaved, onCancel }) {
  const [reason, setReason] = useState(claim.reason);
  const [description, setDescription] = useState(claim.description ?? '');
  const [claimedQuantity, setClaimedQuantity] = useState(String(claim.claimed_quantity));
  const [files, setFiles] = useState([]);
  const filesRef = useRef([]);
  const [fileError, setFileError] = useState('');
  const [formError, setFormError] = useState('');
  const [saving, setSaving] = useState(false);

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

    const existingCount = claim.attachments?.length ?? 0;
    const remainingSlots = Math.max(0, MAX_CLAIM_IMAGES - existingCount - filesRef.current.length);
    if (picked.length > remainingSlots) {
      setFileError(`Mỗi khiếu nại chỉ được có tối đa ${MAX_CLAIM_IMAGES} ảnh.`);
      return;
    }

    setFileError('');
    const added = picked.map((file) => ({
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
    if (blocked.length > 0) {
      setFileError(blocked.join(' '));
      return;
    }

    const formData = new FormData();
    formData.append('_method', 'PUT');
    formData.append('reason', reason);
    formData.append('description', description);
    formData.append('claimed_quantity', claimedQuantity);
    files.forEach(({ file }) => formData.append('images[]', file));

    setSaving(true);
    try {
      const response = await axiosClient.post(`/claims/${claim.id}`, formData);
      onSaved(response.data.data);
    } catch (requestError) {
      const payload = requestError.response?.data;
      const firstFieldError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
      setFormError(firstFieldError || payload?.message || 'Không cập nhật được khiếu nại.');
    } finally {
      setSaving(false);
    }
  }

  const noImageSlots = (claim.attachments?.length ?? 0) + files.length >= MAX_CLAIM_IMAGES;

  return (
    <form className="mt-3 space-y-3 rounded-xl border border-emerald-200 bg-white p-3" onSubmit={handleSubmit}>
      <label className="block text-sm font-medium text-slate-700">
        Lý do
        <select
          className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
        >
          {CLAIM_REASONS.map((item) => (
            <option key={item} value={item}>{item}</option>
          ))}
        </select>
      </label>
      <label className="block text-sm font-medium text-slate-700">
        Số lượng khiếu nại
        <input
          className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"
          type="number"
          min="0.01"
          max={orderItem?.quantity ?? undefined}
          step="0.01"
          required
          value={claimedQuantity}
          onChange={(event) => setClaimedQuantity(event.target.value)}
        />
      </label>
      <label className="block text-sm font-medium text-slate-700">
        Mô tả
        <textarea
          className="mt-1 min-h-20 w-full rounded-xl border border-slate-300 px-3 py-2"
          maxLength={2000}
          value={description}
          onChange={(event) => setDescription(event.target.value)}
        />
      </label>
      {!noImageSlots && (
        <label className="inline-flex min-h-10 cursor-pointer items-center rounded-xl border border-dashed border-emerald-400 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">
          Thêm ảnh minh chứng
          <input
            className="sr-only"
            type="file"
            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            multiple
            onChange={handleFiles}
          />
        </label>
      )}
      {files.length > 0 && (
        <ul className="flex flex-wrap gap-2">
          {files.map(({ file }, index) => (
            <li key={`${file.name}-${file.lastModified}-${index}`} className="flex items-center gap-2 rounded-lg bg-slate-100 px-2 py-1 text-xs">
              <span className="max-w-40 truncate">{file.name}</span>
              <button
                type="button"
                className="inline-flex items-center gap-1 text-red-700"
                aria-label={`Xóa ảnh mới ${file.name}`}
                onClick={() => removeFile(index)}
              >
                <Trash2 size={14} />
                Xóa
              </button>
            </li>
          ))}
        </ul>
      )}
      {fileError && <p role="alert" className="text-sm text-red-700">{fileError}</p>}
      {formError && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{formError}</p>}
      <div className="flex flex-wrap gap-2">
        <button
          className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
          type="submit"
          disabled={saving || Boolean(fileError)}
        >
          {saving ? 'Đang lưu...' : 'Lưu thay đổi'}
        </button>
        <button
          className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700"
          type="button"
          disabled={saving}
          onClick={onCancel}
        >
          Hủy
        </button>
      </div>
    </form>
  );
}
