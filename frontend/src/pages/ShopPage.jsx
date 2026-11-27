import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import Button from '../components/Button';
import TextField from '../components/TextField';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../utils/apiError';
import {
  AUTH_SESSION_LOCK_NAME,
  canClearRejectedCheckout,
  readAuthSnapshot,
} from '../utils/authSession';
import { assignProductImages } from '../utils/productImages';

const SHIPPING_DEBOUNCE_MS = 500;
const MAX_DELIVERY_ADDRESS_LENGTH = 500;
const DEFAULT_SHIPPING_RATE = 5000;
const CHECKOUT_STORAGE_VERSION = 1;
const VietnamDeliveryAddressFields = lazy(() => import('../components/VietnamDeliveryAddressFields'));

function formatMoney(value) {
  return Number(value || 0).toLocaleString('vi-VN');
}

function normalizeAddress(address) {
  return String(address || '').trim().replace(/\s+/g, ' ');
}

function customerForm(user, savedForm = {}) {
  return {
    recipient_name: savedForm.recipient_name ?? user?.full_name ?? '',
    recipient_phone: savedForm.recipient_phone ?? user?.phone ?? '',
    delivery_address: savedForm.delivery_address ?? '',
    delivery_province_code: savedForm.delivery_province_code ?? '',
    delivery_ward_code: savedForm.delivery_ward_code ?? '',
    delivery_detail: savedForm.delivery_detail ?? '',
  };
}

function checkoutStorageKey(userId) {
  return `organic-farm:checkout:v${CHECKOUT_STORAGE_VERSION}:${userId}`;
}

function parseCheckoutRecord(raw, userId) {
  if (!raw) {
    return { record: null, error: '' };
  }

  try {
    const record = JSON.parse(raw);
    if (
      record?.version !== CHECKOUT_STORAGE_VERSION
      || record.userId !== String(userId)
      || typeof record.idempotencyKey !== 'string'
      || !['pending', 'completed'].includes(record.status)
      || typeof record.fingerprint !== 'string'
    ) {
      return { record: null, error: 'Thông tin checkout đã lưu không hợp lệ. Không thể gửi đơn an toàn.' };
    }

    if (
      record.status === 'pending'
      && (
        !record.payload
        || !Array.isArray(record.payload.items)
        || !record.snapshot?.form
        || !record.snapshot?.cart
        || !record.snapshot?.shippingQuote
      )
    ) {
      return { record: null, error: 'Checkout trước chưa được lưu đầy đủ. Không thể gửi đơn mới an toàn.' };
    }

    return { record, error: '' };
  } catch {
    return { record: null, error: 'Không thể đọc trạng thái checkout đã lưu. Không gửi đơn để tránh tạo trùng.' };
  }
}

function readCheckoutRecord(userId) {
  if (!userId) {
    return { record: null, error: '' };
  }

  try {
    return parseCheckoutRecord(localStorage.getItem(checkoutStorageKey(userId)), userId);
  } catch {
    return { record: null, error: 'Trình duyệt không thể đọc trạng thái checkout đã lưu.' };
  }
}

function writeCheckoutRecord(userId, record) {
  localStorage.setItem(checkoutStorageKey(userId), JSON.stringify(record));
}

function removeCheckoutRecord(userId) {
  localStorage.removeItem(checkoutStorageKey(userId));
}

function createIdempotencyKey() {
  if (typeof globalThis.crypto?.randomUUID !== 'function') {
    throw new Error('Trình duyệt không hỗ trợ tạo khóa checkout an toàn.');
  }

  return globalThis.crypto.randomUUID();
}

function buildCheckoutPayload(form, cart, shippingQuote) {
  const items = Object.values(cart)
    .filter((entry) => Number(entry.quantity) > 0)
    .map((entry) => ({
      product_id: Number(entry.product.id),
      quantity: Number(entry.quantity),
    }))
    .sort((left, right) => left.product_id - right.product_id || left.quantity - right.quantity);

  return {
    ...form,
    quote_token: shippingQuote.quote_token,
    items,
  };
}

async function checkoutFingerprint(payload, idempotencyKey) {
  const items = payload.items
    .map((item) => ({
      product_id: Number(item.product_id),
      quantity: Number(item.quantity).toFixed(2),
    }))
    .sort((left, right) => left.product_id - right.product_id || left.quantity.localeCompare(right.quantity));
  const businessContent = JSON.stringify({
    recipient_name: payload.recipient_name,
    recipient_phone: payload.recipient_phone,
    delivery_address: payload.delivery_address,
    items,
  });
  const digest = await globalThis.crypto.subtle.digest(
    'SHA-256',
    new TextEncoder().encode(`${idempotencyKey}\u0000${businessContent}`),
  );

  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

export default function ShopPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const userId = user?.id == null ? '' : String(user.id);
  const [initialCheckoutState] = useState(() => readCheckoutRecord(userId));
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [cart, setCart] = useState(() => (
    initialCheckoutState.record?.status === 'pending'
      ? initialCheckoutState.record.snapshot.cart
      : {}
  ));
  const [orders, setOrders] = useState([]);
  const [form, setForm] = useState(() => customerForm(
    user,
    initialCheckoutState.record?.status === 'pending'
      ? initialCheckoutState.record.snapshot.form
      : {},
  ));
  const [message, setMessage] = useState('');
  const [messageType, setMessageType] = useState('success');
  const [errors, setErrors] = useState({});
  const [shippingQuote, setShippingQuote] = useState(() => (
    initialCheckoutState.record?.status === 'pending'
      ? initialCheckoutState.record.snapshot.shippingQuote
      : null
  ));
  const [shippingLoading, setShippingLoading] = useState(false);
  const [shippingError, setShippingError] = useState('');
  const [shippingRetryVersion, setShippingRetryVersion] = useState(0);
  const [checkoutLoading, setCheckoutLoading] = useState(false);
  const [checkoutRecord, setCheckoutRecord] = useState(initialCheckoutState.record);
  const [checkoutStorageError, setCheckoutStorageError] = useState(initialCheckoutState.error);
  const checkoutStateReady = Boolean(userId);
  const [newCheckoutIntent, setNewCheckoutIntent] = useState(false);
  const shippingRequestId = useRef(0);
  const checkoutInFlight = useRef(false);
  const checkoutRecordRef = useRef(initialCheckoutState.record);
  const activeUserIdRef = useRef(userId);

  function updateCheckoutRecord(record) {
    checkoutRecordRef.current = record;
    setCheckoutRecord(record);
  }

  useEffect(() => {
    if (activeUserIdRef.current === userId) {
      return;
    }

    activeUserIdRef.current = userId;
    shippingRequestId.current += 1;
    const restored = readCheckoutRecord(userId);
    const pending = restored.record?.status === 'pending' ? restored.record : null;
    checkoutRecordRef.current = restored.record;
    setCheckoutRecord(restored.record);
    setCheckoutStorageError(restored.error);
    setNewCheckoutIntent(false);
    setCart(pending?.snapshot.cart || {});
    setForm(customerForm(user, pending?.snapshot.form));
    setShippingQuote(pending?.snapshot.shippingQuote || null);
    setOrders([]);
    setShippingLoading(false);
    setShippingError('');
    setErrors({});
    setMessage('');
  }, [user, user?.full_name, user?.phone, userId]);

  useEffect(() => {
    if (!user) {
      navigate('/dang-nhap');
      return;
    }

    Promise.all([
      axiosClient.get('/products'),
      axiosClient.get('/customer/orders'),
    ]).then(([productsResponse, ordersResponse]) => {
      setProducts(Array.isArray(productsResponse.data) ? assignProductImages(productsResponse.data) : []);
      setOrders(Array.isArray(ordersResponse.data) ? ordersResponse.data : []);
    }).catch((error) => {
      setMessage(apiError(error).message);
      setMessageType('error');
    }).finally(() => setLoading(false));
  }, [navigate, user]);

  useEffect(() => {
    if (!userId) {
      return undefined;
    }

    const storageKey = checkoutStorageKey(userId);
    const handleStorage = (event) => {
      if (event.key !== storageKey) {
        return;
      }

      const { record, error } = parseCheckoutRecord(event.newValue, userId);
      const previousRecord = checkoutRecordRef.current;
      setCheckoutStorageError(error);
      checkoutRecordRef.current = record;
      setCheckoutRecord(record);

      if (record?.status === 'pending') {
        setNewCheckoutIntent(false);
        setCart((current) => (
          Object.values(current).some((entry) => Number(entry.quantity) > 0)
            ? current
            : record.snapshot.cart
        ));
        setForm((current) => (
          !current.delivery_address
          && current.recipient_name === (user?.full_name || '')
          && current.recipient_phone === (user?.phone || '')
            ? customerForm(user, record.snapshot.form)
            : current
        ));
        setShippingQuote((current) => current || record.snapshot.shippingQuote);
      } else if (
        record?.status === 'completed'
        && previousRecord?.status === 'pending'
        && previousRecord.idempotencyKey === record.idempotencyKey
      ) {
        setCart({});
        setShippingQuote(null);
        setMessage('Đơn hàng này đã được xác nhận ở một tab khác.');
        setMessageType('success');
      }
    };

    window.addEventListener('storage', handleStorage);
    return () => window.removeEventListener('storage', handleStorage);
  }, [user, user?.full_name, user?.phone, userId]);

  useEffect(() => {
    if (checkoutRecord?.status === 'pending') {
      return undefined;
    }

    const address = normalizeAddress(form.delivery_address);
    const addressIsComplete = Boolean(
      form.delivery_province_code
      && form.delivery_ward_code
      && form.delivery_detail.trim(),
    );
    const requestId = ++shippingRequestId.current;
    const invalidateRequest = () => {
      if (shippingRequestId.current === requestId) {
        shippingRequestId.current += 1;
      }
    };

    if (!address || !addressIsComplete) {
      return invalidateRequest;
    }

    if (Array.from(address).length > MAX_DELIVERY_ADDRESS_LENGTH) {
      return invalidateRequest;
    }

    const timeoutId = setTimeout(async () => {
      try {
        const response = await axiosClient.post('/shipping/calculate', {
          delivery_address: address,
        });
        if (requestId !== shippingRequestId.current) {
          return;
        }

        const distance = Number(response.distance_km);
        const rate = Number(response.shipping_rate);
        const fee = Number(response.shipping_fee);
        const quoteToken = response.quote_token;
        if (
          !Number.isFinite(distance) || distance < 0
          || !Number.isInteger(rate) || rate <= 0
          || !Number.isInteger(fee) || fee < 0
          || typeof quoteToken !== 'string' || quoteToken.length === 0 || quoteToken.length > 8192
        ) {
          setShippingError('Máy chủ trả báo giá không hợp lệ. Vui lòng kiểm tra địa chỉ.');
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
    }, SHIPPING_DEBOUNCE_MS);

    return () => {
      clearTimeout(timeoutId);
      invalidateRequest();
    };
  }, [
    checkoutRecord?.status,
    form.delivery_address,
    form.delivery_detail,
    form.delivery_province_code,
    form.delivery_ward_code,
    shippingRetryVersion,
  ]);

  function addProduct(product) {
    if (checkoutInFlight.current || checkoutRecord?.status === 'pending') {
      return;
    }

    if (
      !Object.values(cart).some((entry) => Number(entry.quantity) > 0)
      && checkoutRecord?.status === 'completed'
    ) {
      setNewCheckoutIntent(true);
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
    if (checkoutInFlight.current || checkoutRecord?.status === 'pending') {
      return;
    }

    if (checkoutRecord?.status === 'completed') {
      setNewCheckoutIntent(true);
    }

    setCart((current) => ({
      ...current,
      [productId]: { ...current[productId], quantity: Number(quantity) },
    }));
  }

  function updateDeliveryAddress(address, locationFields = {}) {
    if (checkoutInFlight.current || checkoutRecord?.status === 'pending') {
      return;
    }

    if (checkoutRecord?.status === 'completed') {
      setNewCheckoutIntent(true);
    }

    shippingRequestId.current += 1;
    const normalizedAddress = normalizeAddress(address);
    const addressLength = Array.from(normalizedAddress).length;
    const nextForm = { ...form, ...locationFields };
    const addressIsComplete = Boolean(
      nextForm.delivery_province_code
      && nextForm.delivery_ward_code
      && nextForm.delivery_detail.trim(),
    );
    setShippingLoading(
      addressIsComplete && Boolean(normalizedAddress) && addressLength <= MAX_DELIVERY_ADDRESS_LENGTH,
    );
    setShippingQuote(null);
    setShippingError(
      addressLength > MAX_DELIVERY_ADDRESS_LENGTH
        ? `Địa chỉ giao hàng tối đa ${MAX_DELIVERY_ADDRESS_LENGTH} ký tự.`
        : (!normalizedAddress && address ? 'Vui lòng nhập địa chỉ giao hàng hợp lệ.' : ''),
    );
    setMessage('');
    setErrors((current) => {
      const next = { ...current };
      delete next.delivery_address;
      return next;
    });
    setForm((current) => ({ ...current, ...locationFields, delivery_address: address }));
  }

  function updateDeliveryLocation(value) {
    const locationFields = {
      delivery_province_code: value.provinceCode,
      delivery_ward_code: value.wardCode,
      delivery_detail: value.detail,
    };
    updateDeliveryAddress(value.address, locationFields);
  }

  function retryShippingCalculation() {
    const address = normalizeAddress(form.delivery_address);
    const addressIsComplete = Boolean(
      form.delivery_province_code
      && form.delivery_ward_code
      && form.delivery_detail.trim(),
    );
    if (
      shippingLoading
      || checkoutRecord?.status === 'pending'
      || !addressIsComplete
      || !address
      || Array.from(address).length > MAX_DELIVERY_ADDRESS_LENGTH
    ) {
      return;
    }

    setShippingError('');
    setShippingLoading(true);
    setShippingRetryVersion((current) => current + 1);
  }

  function updateRecipientField(field, value) {
    if (checkoutInFlight.current || checkoutRecord?.status === 'pending') {
      return;
    }

    if (checkoutRecord?.status === 'completed') {
      setNewCheckoutIntent(true);
    }

    setForm((current) => ({ ...current, [field]: value }));
  }

  async function checkout(event) {
    event.preventDefault();

    if (checkoutInFlight.current) {
      return;
    }

    let activeAuthSnapshot;
    try {
      activeAuthSnapshot = readAuthSnapshot();
    } catch {
      setMessage('Không thể xác minh phiên đăng nhập hiện tại. Checkout đang chờ được giữ nguyên.');
      setMessageType('error');
      return;
    }

    if (
      !userId
      || activeUserIdRef.current !== userId
      || activeAuthSnapshot.userId !== userId
      || !activeAuthSnapshot.token
    ) {
      setMessage('Tài khoản đăng nhập đã thay đổi. Không gửi checkout đang chờ; hãy đăng nhập lại bằng tài khoản sở hữu checkout.');
      setMessageType('error');
      return;
    }

    if (!userId || !checkoutStateReady || checkoutStorageError) {
      setMessage(checkoutStorageError || 'Đang khôi phục trạng thái checkout. Vui lòng thử lại.');
      setMessageType('error');
      return;
    }

    if (!globalThis.navigator?.locks?.request) {
      setMessage('Trình duyệt không hỗ trợ khóa checkout giữa các tab. Không gửi đơn để tránh tạo trùng.');
      setMessageType('error');
      return;
    }

    const storedAtStart = readCheckoutRecord(userId);
    if (storedAtStart.error) {
      setCheckoutStorageError(storedAtStart.error);
      setMessage(storedAtStart.error);
      setMessageType('error');
      return;
    }

    if (
      storedAtStart.record?.status === 'pending'
      && checkoutRecordRef.current?.idempotencyKey !== storedAtStart.record.idempotencyKey
    ) {
      updateCheckoutRecord(storedAtStart.record);
      if (!cartEntries.length) {
        setCart(storedAtStart.record.snapshot.cart);
        setForm(customerForm(user, storedAtStart.record.snapshot.form));
        setShippingQuote(storedAtStart.record.snapshot.shippingQuote);
      }
      setMessage('Đã khôi phục checkout đang chờ. Hãy thử lại cùng nội dung, không thay đổi giỏ hàng hoặc địa chỉ.');
      setMessageType('error');
      return;
    }

    const currentPending = checkoutRecordRef.current?.status === 'pending'
      ? checkoutRecordRef.current
      : null;
    const currentPayload = currentPending?.payload
      || (shippingQuote?.quote_token ? buildCheckoutPayload(form, cart, shippingQuote) : null);
    if (!currentPayload?.items?.length) {
      setMessage('Vui lòng chọn ít nhất một sản phẩm.');
      setMessageType('error');
      return;
    }

    if (
      !currentPending
      && (!shippingQuote?.quote_token || shippingQuote.address !== normalizeAddress(form.delivery_address))
    ) {
      setMessage('Vui lòng tính phí vận chuyển cho địa chỉ hiện tại trước khi đặt hàng.');
      setMessageType('error');
      return;
    }

    checkoutInFlight.current = true;
    setCheckoutLoading(true);
    setMessage('');
    setMessageType('success');
    setErrors({});

    let requestWasSent = false;
    let attemptedRecord = null;
    let requestAuthSnapshot = null;
    try {
      const result = await globalThis.navigator.locks.request(
        `organic-farm:checkout:${userId}`,
        async () => {
          const currentAuthSnapshot = readAuthSnapshot();
          if (
            activeUserIdRef.current !== userId
            || currentAuthSnapshot.userId !== userId
            || !currentAuthSnapshot.token
          ) {
            return {
              type: 'blocked',
              message: 'Tài khoản đăng nhập đã thay đổi. Checkout đang chờ được giữ nguyên và không được gửi.',
            };
          }

          const stored = readCheckoutRecord(userId);
          if (stored.error) {
            return { type: 'blocked', message: stored.error };
          }

          const existing = stored.record;
          let idempotencyKey;
          let fingerprint;
          let payload = currentPayload;

          if (existing?.status === 'pending') {
            fingerprint = await checkoutFingerprint(currentPayload, existing.idempotencyKey);
            if (fingerprint !== existing.fingerprint) {
              if (activeUserIdRef.current === userId) {
                updateCheckoutRecord(existing);
              }
              return {
                type: 'blocked',
                message: 'Một checkout khác đang chờ kết quả với nội dung khác. Khôi phục nội dung đã lưu trước khi tiếp tục.',
              };
            }

            idempotencyKey = existing.idempotencyKey;
            payload = existing.payload;
          } else if (existing?.status === 'completed' && !newCheckoutIntent) {
            fingerprint = await checkoutFingerprint(currentPayload, existing.idempotencyKey);
            if (fingerprint !== existing.fingerprint) {
              if (activeUserIdRef.current === userId) {
                updateCheckoutRecord(existing);
              }
              return {
                type: 'blocked',
                message: 'Checkout trước đã hoàn tất. Hãy bắt đầu một lần checkout mới trước khi gửi nội dung khác.',
              };
            }

            idempotencyKey = existing.idempotencyKey;
          } else {
            idempotencyKey = createIdempotencyKey();
            fingerprint = await checkoutFingerprint(currentPayload, idempotencyKey);
          }

          const pendingRecord = existing?.status === 'pending'
            ? existing
            : {
              version: CHECKOUT_STORAGE_VERSION,
              userId,
              idempotencyKey,
              status: 'pending',
              fingerprint,
              payload,
              snapshot: { form: { ...form }, cart, shippingQuote },
            };

          requestAuthSnapshot = readAuthSnapshot();
          if (
            activeUserIdRef.current !== userId
            || requestAuthSnapshot.userId !== userId
            || !requestAuthSnapshot.token
          ) {
            return {
              type: 'blocked',
              message: 'Tài khoản đăng nhập đã thay đổi. Checkout đang chờ được giữ nguyên và không được gửi.',
            };
          }

          try {
            writeCheckoutRecord(userId, pendingRecord);
          } catch {
            return {
              type: 'blocked',
              message: 'Không thể lưu trạng thái checkout trên trình duyệt. Đơn chưa được gửi.',
            };
          }

          attemptedRecord = pendingRecord;
          if (activeUserIdRef.current === userId) {
            updateCheckoutRecord(pendingRecord);
            setCheckoutStorageError('');
            setNewCheckoutIntent(false);
          }
          requestWasSent = true;

          const response = await axiosClient.post('/customer/orders', payload, {
            headers: { 'Idempotency-Key': idempotencyKey },
            expectedAuthUserId: userId,
            expectedAuthSessionId: requestAuthSnapshot.sessionId,
          });
          const completedRecord = {
            version: CHECKOUT_STORAGE_VERSION,
            userId,
            idempotencyKey,
            status: 'completed',
            fingerprint,
          };
          let completionPersisted = true;
          try {
            writeCheckoutRecord(userId, completedRecord);
          } catch {
            completionPersisted = false;
          }
          if (activeUserIdRef.current === userId) {
            updateCheckoutRecord(completionPersisted ? completedRecord : pendingRecord);
          }

          return {
            type: 'success',
            response,
            completionPersisted,
          };
        },
      );

      if (result.type === 'blocked') {
        if (activeUserIdRef.current === userId) {
          setMessage(result.message);
          setMessageType('error');
        }
        return;
      }

      if (result.type === 'success') {
        if (activeUserIdRef.current !== userId) {
          return;
        }

        setOrders((current) => [result.response.data, ...current]);
        if (result.completionPersisted) {
          setCart({});
          setShippingQuote(null);
          setMessage('Tạo đơn hàng thành công. Đơn đang chờ nhân viên bán hàng xác nhận.');
        } else {
          setMessage('Đơn đã được tạo, nhưng trình duyệt chưa lưu được trạng thái hoàn tất. Hãy tải lại và thử lại checkout đã lưu; không bắt đầu đơn mới.');
          setMessageType('error');
        }
        if (result.completionPersisted) {
          setMessageType('success');
        }
        return;
      }
    } catch (error) {
      const parsed = apiError(error);
      const status = error?.response?.status;
      const sessionState = (() => {
        try {
          const currentAuthSnapshot = readAuthSnapshot();
          const authStillMatches = Boolean(
            requestAuthSnapshot
            && requestAuthSnapshot.sessionId
            && activeUserIdRef.current === userId
            && requestAuthSnapshot.userId === userId
            && currentAuthSnapshot.userId === userId
            && currentAuthSnapshot.revision === requestAuthSnapshot.revision
            && currentAuthSnapshot.sessionId === requestAuthSnapshot.sessionId
            && currentAuthSnapshot.token === requestAuthSnapshot.token
          );
          return { authStillMatches };
        } catch {
          return { authStillMatches: false };
        }
      })();

      if (activeUserIdRef.current === userId) {
        setErrors(parsed.errors);
      }

      if (status === 422 && attemptedRecord) {
        const quoteOrAddressRejected = Boolean(
          parsed.errors.quote_token || parsed.errors.delivery_address,
        );
        let canClearPending = false;
        let pendingClearError = '';

        if (!quoteOrAddressRejected && sessionState.authStillMatches) {
          const lockManager = globalThis.navigator?.locks;
          if (typeof lockManager?.request === 'function') {
            try {
              const clearResult = await lockManager.request(
                `organic-farm:checkout:${userId}`,
                () => lockManager.request(AUTH_SESSION_LOCK_NAME, () => {
                  const currentAuthSnapshot = readAuthSnapshot();
                  const stillOwnsSession = canClearRejectedCheckout({
                    requestSnapshot: requestAuthSnapshot,
                    currentSnapshot: currentAuthSnapshot,
                    activeUserId: activeUserIdRef.current,
                    ownerUserId: userId,
                    validationErrors: parsed.errors,
                  });
                  if (!stillOwnsSession) {
                    return { cleared: false };
                  }

                  const latest = readCheckoutRecord(userId);
                  if (latest.error) {
                    return { error: latest.error };
                  }
                  if (
                    latest.record?.status !== 'pending'
                    || latest.record.idempotencyKey !== attemptedRecord.idempotencyKey
                    || latest.record.fingerprint !== attemptedRecord.fingerprint
                  ) {
                    return { cleared: false };
                  }

                  removeCheckoutRecord(userId);
                  return { cleared: true };
                }),
              );

              if (clearResult?.cleared) {
                canClearPending = true;
                if (activeUserIdRef.current === userId) {
                  updateCheckoutRecord(null);
                  setNewCheckoutIntent(false);
                }
              } else if (clearResult?.error) {
                pendingClearError = 'Không thể xác minh trạng thái checkout an toàn. Idempotency-Key và dữ liệu đang chờ được giữ nguyên.';
                if (activeUserIdRef.current === userId) {
                  setCheckoutStorageError(clearResult.error);
                }
              } else {
                pendingClearError = 'Phiên hoặc checkout đã thay đổi. Idempotency-Key và dữ liệu đang chờ được giữ nguyên.';
              }
            } catch {
              pendingClearError = 'Không thể khóa trạng thái phiên để xác minh an toàn. Idempotency-Key và dữ liệu đang chờ được giữ nguyên.';
            }
          } else {
            pendingClearError = 'Trình duyệt không hỗ trợ khóa phiên giữa các tab. Idempotency-Key và dữ liệu đang chờ được giữ nguyên.';
          }
        }

        if (activeUserIdRef.current === userId) {
          setMessage(
            !sessionState.authStillMatches
              ? 'Checkout chưa được xác minh trong phiên sở hữu. Idempotency-Key và dữ liệu đang chờ được giữ nguyên; hãy đăng nhập lại đúng tài khoản rồi thử lại.'
              : quoteOrAddressRejected
                ? 'Báo giá hoặc địa chỉ bị từ chối. Idempotency-Key và checkout đang chờ được giữ nguyên để tránh tạo đơn trùng.'
                : canClearPending
                  ? parsed.message
                  : pendingClearError || 'Lỗi xác thực chưa đủ rõ để xóa checkout. Idempotency-Key và dữ liệu đang chờ được giữ nguyên.',
          );
          setMessageType('error');
        }
      } else if (status === 409) {
        if (activeUserIdRef.current === userId) {
          setMessage(parsed.message);
          setMessageType('error');
        }
      } else if (requestWasSent) {
        if (activeUserIdRef.current === userId) {
          setMessage(
            error?.code === 'ERR_AUTH_CONTEXT_CHANGED'
              ? 'Tài khoản đăng nhập đã thay đổi. Không gửi bằng token mới; Idempotency-Key và nội dung checkout cũ được giữ nguyên.'
              : 'Chưa xác định được máy chủ đã nhận đơn hay chưa. Đã giữ nguyên khóa và nội dung checkout; hãy thử lại, không thay đổi dữ liệu.',
          );
          setMessageType('error');
        }
      } else {
        if (activeUserIdRef.current === userId) {
          setMessage(parsed.message);
          setMessageType('error');
        }
      }
    } finally {
      checkoutInFlight.current = false;
      setCheckoutLoading(false);
    }
  }

  const cartEntries = Object.values(cart).filter((entry) => entry.quantity > 0);
  const checkoutUnresolved = checkoutRecord?.status === 'pending';
  const checkoutLocked = checkoutLoading || checkoutUnresolved;
  const hasLegacyDeliveryAddress = Boolean(
    checkoutLocked
    && form.delivery_address
    && !form.delivery_province_code
    && !form.delivery_ward_code
    && !form.delivery_detail,
  );
  const subtotal = cartEntries.reduce(
    (sum, entry) => sum + Number(entry.product.base_price || 0) * Number(entry.quantity || 0),
    0,
  );
  const quoteIsCurrent = Boolean(
    shippingQuote?.quote_token
    && shippingQuote.address === normalizeAddress(form.delivery_address),
  );
  const total = quoteIsCurrent ? subtotal + shippingQuote.shipping_fee : null;

  return (
    <div className="space-y-5">
      <section className="grid gap-5 lg:grid-cols-[1fr_360px]">
        <div className="grid gap-4 sm:grid-cols-2">
          {products.filter((product) => product.is_active).map((product) => (
            <article key={product.id} className="rounded-2xl bg-white p-5 shadow-sm">
              <img
                src={product.display_image}
                alt={product.name}
                loading="lazy"
                className="mb-4 aspect-[4/3] w-full rounded-xl object-cover"
              />
              <p className="text-xs font-semibold uppercase text-emerald-700">{product.category?.name}</p>
              <h2 className="mt-2 text-lg font-bold text-slate-900">{product.name}</h2>
              <p className="mt-1 text-sm text-slate-600">{product.description}</p>
              <p className="mt-3 font-bold text-emerald-800">
                {formatMoney(product.base_price)} đ/{product.unit}
              </p>
              <Button type="button" variant="secondary" className="mt-4" disabled={checkoutLocked} onClick={() => addProduct(product)}>
                Thêm vào đơn
              </Button>
            </article>
          ))}
          {loading ? (
            <p role="status" className="text-sm text-slate-500">Đang tải sản phẩm...</p>
          ) : null}
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
                    disabled={checkoutLocked}
                    onChange={(event) => updateQuantity(entry.product.id, event.target.value)}
                    className="min-h-10 rounded-lg border border-slate-300 px-2"
                  />
                </div>
              ))}
            </div>
          )}

          <form onSubmit={checkout} className="mt-5 space-y-3" noValidate>
            <TextField id="recipient_name" name="recipient_name" label="Người nhận" value={form.recipient_name} disabled={checkoutLocked} onChange={(event) => updateRecipientField('recipient_name', event.target.value)} error={errors.recipient_name} />
            <TextField id="recipient_phone" name="recipient_phone" label="Số điện thoại" value={form.recipient_phone} disabled={checkoutLocked} onChange={(event) => updateRecipientField('recipient_phone', event.target.value)} error={errors.recipient_phone} />
            {hasLegacyDeliveryAddress ? (
              <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-700">
                Địa chỉ của đơn đang chờ: {form.delivery_address}
              </p>
            ) : (
              <Suspense fallback={<p role="status" className="text-sm text-slate-500">Đang tải danh sách địa chỉ...</p>}>
                <VietnamDeliveryAddressFields
                  value={{
                    provinceCode: form.delivery_province_code,
                    wardCode: form.delivery_ward_code,
                    detail: form.delivery_detail,
                  }}
                  disabled={checkoutLocked}
                  onChange={updateDeliveryLocation}
                />
              </Suspense>
            )}
            {errors.delivery_address ? <span className="text-sm text-red-600">{errors.delivery_address}</span> : null}
            <p className="text-xs text-slate-500">
              Phí vận chuyển sẽ tự tính sau khi bạn ngừng nhập địa chỉ.
            </p>
            {shippingLoading ? (
              <p role="status" className="text-sm text-slate-600">
                Đang tính phí vận chuyển...
              </p>
            ) : null}
            {shippingError ? (
              <div className="space-y-2">
                <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{shippingError}</p>
                {!shippingLoading
                  && checkoutRecord?.status !== 'pending'
                  && form.delivery_province_code
                  && form.delivery_ward_code
                  && form.delivery_detail.trim()
                  && normalizeAddress(form.delivery_address)
                  && Array.from(normalizeAddress(form.delivery_address)).length <= MAX_DELIVERY_ADDRESS_LENGTH ? (
                    <Button type="button" variant="secondary" className="w-full" onClick={retryShippingCalculation}>
                      Thử tính lại phí
                    </Button>
                  ) : null}
              </div>
            ) : null}
            {checkoutUnresolved && !checkoutLoading ? (
              <p role="status" className="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
                Kết quả checkout trước chưa rõ. Hãy thử lại với nội dung đã lưu; không thay đổi giỏ hàng hoặc thông tin giao hàng.
              </p>
            ) : null}
            {checkoutStorageError ? <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{checkoutStorageError}</p> : null}
            <div className="space-y-1 border-t border-slate-100 pt-3 text-sm">
              <p>Khoảng cách: {quoteIsCurrent ? `${shippingQuote.distance_km.toLocaleString('vi-VN')} km` : 'Chưa tính'}</p>
              <p>Đơn giá: {formatMoney(quoteIsCurrent ? shippingQuote.shipping_rate : DEFAULT_SHIPPING_RATE)} đ/km</p>
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
              disabled={checkoutLoading || shippingLoading || !quoteIsCurrent || cartEntries.length === 0 || !checkoutStateReady || Boolean(checkoutStorageError)}
            >
              {checkoutLoading ? 'Đang gửi đơn...' : checkoutUnresolved ? 'Thử lại checkout đang chờ' : 'Tạo đơn hàng'}
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
