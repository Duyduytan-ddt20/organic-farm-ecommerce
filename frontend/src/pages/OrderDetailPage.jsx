import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import axiosClient from '../api/axiosClient';
import ClaimForm from '../components/ClaimForm';
import EditClaimForm from '../components/EditClaimForm';

const STATUS_LABELS = {
  pending: 'Chờ xác nhận',
  confirmed: 'Đã xác nhận',
  shipping: 'Đang giao',
  delivered: 'Đã giao',
  cancelled: 'Đã hủy',
};

export default function OrderDetailPage() {
  const { id } = useParams();
  const [result, setResult] = useState(null);
  const [deletingAttachmentId, setDeletingAttachmentId] = useState(null);
  const [attachmentDeleteError, setAttachmentDeleteError] = useState('');
  const [editingClaimId, setEditingClaimId] = useState(null);

  useEffect(() => {
    let active = true;
    const orderPath = import.meta.env.DEV && id === 'preview' ? '/orders/preview' : `/orders/${id}`;
    axiosClient
      .get(orderPath)
      .then((response) => {
        if (active) setResult({ id, order: response.data.data, error: '', loading: false });
      })
      .catch((requestError) => {
        if (!active) return;
        setResult({
          id,
          order: null,
          error: requestError.response?.data?.message || 'Không tải được chi tiết đơn hàng.',
          loading: false,
        });
      });

    return () => {
      active = false;
    };
  }, [id]);

  function addClaim(claim) {
    setResult((current) => {
      if (!current || current.id !== id || !current.order) return current;
      return {
        ...current,
        order: {
          ...current.order,
          items: current.order.items.map((item) => (
            String(item.id) === String(claim.order_item_id)
              ? { ...item, claims: [...(item.claims ?? []), claim] }
              : item
          )),
        },
      };
    });
  }

  function updateClaim(updatedClaim) {
    setResult((current) => {
      if (!current || current.id !== id || !current.order) return current;

      return {
        ...current,
        order: {
          ...current.order,
          items: current.order.items.map((item) => ({
            ...item,
            claims: (item.claims ?? []).map((claim) => (
              String(claim.id) === String(updatedClaim.id) ? updatedClaim : claim
            )),
          })),
        },
      };
    });
    setEditingClaimId(null);
  }

  async function deleteAttachment(claimId, attachmentId) {
    setDeletingAttachmentId(attachmentId);
    setAttachmentDeleteError('');

    try {
      await axiosClient.delete(`/claims/${claimId}/attachments/${attachmentId}`);
      setResult((current) => {
        if (!current || current.id !== id || !current.order) return current;

        return {
          ...current,
          order: {
            ...current.order,
            items: current.order.items.map((item) => ({
              ...item,
              claims: (item.claims ?? []).map((claim) => (
                claim.id === claimId
                  ? { ...claim, attachments: claim.attachments.filter((attachment) => attachment.id !== attachmentId) }
                  : claim
              )),
            })),
          },
        };
      });
    } catch (requestError) {
      const payload = requestError.response?.data;
      const firstFieldError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
      setAttachmentDeleteError(firstFieldError || payload?.message || 'Không xóa được ảnh minh chứng.');
    } finally {
      setDeletingAttachmentId(null);
    }
  }

  const { order, error, loading } = result?.id === id
    ? result
    : { order: null, error: '', loading: true };

  if (loading) {
    return <p className="text-slate-500">Đang tải đơn hàng...</p>;
  }

  if (error || !order) {
    return (
      <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
        <p>{error || 'Không tìm thấy đơn hàng.'}</p>
        {error.includes('Unauthenticated') && (
          <Link className="mt-2 inline-block font-semibold underline" to="/login">
            Đăng nhập để mở đơn hàng
          </Link>
        )}
      </div>
    );
  }

  const claims = order.items?.flatMap((item) => item.claims ?? []) ?? [];

  return (
    <div className="space-y-4">
      <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-sm text-slate-500">Mã đơn</p>
            <h2 className="text-xl font-bold">{order.order_code}</h2>
          </div>
          <span className="rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-800">
            {STATUS_LABELS[order.status] ?? order.status}
          </span>
        </div>
        <dl className="mt-4 grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
          <div>
            <dt className="text-slate-400">Người nhận</dt>
            <dd>{order.recipient_name} — {order.recipient_phone}</dd>
          </div>
          <div>
            <dt className="text-slate-400">Địa chỉ</dt>
            <dd>{order.delivery_address}</dd>
          </div>
        </dl>
        <ul className="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-100">
          {order.items?.map((item) => (
            <li key={item.id} className="flex items-center justify-between gap-3 px-3 py-3 text-sm">
              <span>{item.product?.name ?? `Sản phẩm #${item.product_id}`}</span>
              <span className="text-slate-500">{item.quantity} {item.product?.unit ?? ''} × {item.unit_price}</span>
            </li>
          ))}
        </ul>
      </section>

      {order.status === 'delivered' ? (
        <ClaimForm order={order} onCreated={addClaim} />
      ) : (
        <p className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Form gửi khiếu nại chỉ gắn trên đơn hàng đã giao.
        </p>
      )}

      {claims.length > 0 && (
        <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
          <h2 className="font-bold">Khiếu nại đã gửi</h2>
          {attachmentDeleteError && (
            <p role="alert" className="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
              {attachmentDeleteError}
            </p>
          )}
          <ul className="mt-3 space-y-3 text-sm">
            {claims.map((claim) => (
              <li key={claim.id} className="rounded-xl bg-slate-50 px-3 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-medium">#{claim.id} — {claim.reason} ({claim.status})</p>
                  {claim.status === 'pending' && (
                    <button
                      type="button"
                      className="rounded-lg px-3 py-1 text-sm font-semibold text-emerald-800 hover:bg-emerald-50"
                      onClick={() => setEditingClaimId(editingClaimId === claim.id ? null : claim.id)}
                    >
                      {editingClaimId === claim.id ? 'Đóng sửa' : 'Sửa khiếu nại'}
                    </button>
                  )}
                </div>
                <p className="mt-1 text-sm text-slate-600">Số lượng khiếu nại: {claim.claimed_quantity}</p>
                {claim.description && (
                  <p className="mt-1 whitespace-pre-wrap text-sm text-slate-600">{claim.description}</p>
                )}
                {editingClaimId === claim.id && (
                  <EditClaimForm
                    claim={claim}
                    orderItem={order.items.find((item) => String(item.id) === String(claim.order_item_id))}
                    onSaved={updateClaim}
                    onCancel={() => setEditingClaimId(null)}
                  />
                )}
                {claim.attachments?.length === 0 && (
                  <p className="mt-2 text-sm text-slate-500">Khiếu nại này hiện không còn ảnh minh chứng.</p>
                )}
                <ul className="mt-2 flex flex-wrap gap-3">
                  {claim.attachments?.map((attachment) => (
                    <li key={attachment.id} className="flex items-start gap-2">
                      <a
                        className="inline-flex flex-col gap-1 text-emerald-800 underline"
                        href={attachment.url}
                        target="_blank"
                        rel="noreferrer"
                      >
                        <img
                          className="h-28 w-40 rounded-lg border border-slate-200 object-cover"
                          src={attachment.url}
                          alt={`Ảnh minh chứng ${attachment.original_name}`}
                        />
                        {attachment.original_name}
                      </a>
                      {claim.status === 'pending' && (
                        <button
                          type="button"
                          className="inline-flex min-h-9 items-center gap-1 rounded-lg px-2 text-red-700 hover:bg-red-50 disabled:opacity-60"
                          aria-label={`Xóa ảnh ${attachment.original_name}`}
                          disabled={deletingAttachmentId !== null}
                          onClick={() => deleteAttachment(claim.id, attachment.id)}
                        >
                          <Trash2 size={16} />
                          {deletingAttachmentId === attachment.id ? 'Đang xóa...' : 'Xóa ảnh'}
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
