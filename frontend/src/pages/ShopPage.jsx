import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { Search } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import Button from '../components/Button';
import TextField from '../components/TextField';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../utils/apiError';
import { validateClaimEvidence } from '../utils/claimEvidence';
import {
  AUTH_SESSION_LOCK_NAME,
  canClearRejectedCheckout,
  readAuthSnapshot,
} from '../utils/authSession';
import { assignProductImages } from '../utils/productImages';
import { normalizeSearchText } from '../utils/searchText';

const SHIPPING_DEBOUNCE_MS = 500;
const MAX_DELIVERY_ADDRESS_LENGTH = 500;
const DEFAULT_SHIPPING_RATE = 5000;
const CHECKOUT_STORAGE_VERSION = 1;
const PAYMENT_METHODS = [
  { value: 'momo_sandbox', label: 'MoMo', description: 'Thanh toán qua MoMo hoặc mô phỏng khi chạy local.' },
  ...(import.meta.env.DEV
    ? [{
      value: 'demo_local',
      label: 'Demo thanh toán thành công (local-only)',
      description: 'Mô phỏng thanh toán để demo; không thu tiền thật và chỉ hoạt động với backend local/testing.',
    }]
    : []),
  { value: 'cod', label: 'Thanh toán khi nhận hàng', description: 'Trả tiền mặt khi nhận đơn (COD).' },
];
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
  const location = useLocation();
  const navigate = useNavigate();
  const userId = user?.id == null ? '' : String(user.id);
  const [initialCheckoutState] = useState(() => readCheckoutRecord(userId));
  const purchaseProduct = location.state?.purchaseProduct;
  const purchaseProductId = Number(purchaseProduct?.id);
  const validPurchaseProduct = Number.isSafeInteger(purchaseProductId)
    && purchaseProductId > 0
    && Boolean(purchaseProduct?.is_active);
  const [products, setProducts] = useState([]);
  const [productSearch, setProductSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [pendingCartProducts, setPendingCartProducts] = useState({});
  const [quantityDrafts, setQuantityDrafts] = useState({});
  const [toast, setToast] = useState(null);
  const [cart, setCart] = useState(() => (
    initialCheckoutState.record?.status === 'pending'
      ? initialCheckoutState.record.snapshot.cart
      : validPurchaseProduct
        ? {
          [purchaseProductId]: {
            product: purchaseProduct,
            quantity: 1,
          },
        }
        : {}
  ));
  const [orders, setOrders] = useState([]);
  const [claimMessages, setClaimMessages] = useState({});
  const [claimSubmittingKey, setClaimSubmittingKey] = useState(null);
  const [form, setForm] = useState(() => customerForm(
    user,
    initialCheckoutState.record?.status === 'pending'
      ? initialCheckoutState.record.snapshot.form
      : {},
  ));
  const [paymentMethod, setPaymentMethod] = useState(
    initialCheckoutState.record?.snapshot?.paymentMethod || 'cod',
  );
  const [message, setMessage] = useState(() => {
    if (location.state?.purchaseProduct && !validPurchaseProduct) {
      return 'Sản phẩm không còn được bán. Vui lòng chọn sản phẩm khác.';
    }
    if (validPurchaseProduct && initialCheckoutState.record?.status === 'pending') {
      return 'Bạn đang có checkout chưa hoàn tất. Hãy hoàn tất checkout hiện tại trước khi thêm sản phẩm.';
    }
    return '';
  });
  const [messageType, setMessageType] = useState(() => (
    message || (validPurchaseProduct && initialCheckoutState.record?.status === 'pending')
      ? 'error'
      : 'success'
  ));
  const [successOrder, setSuccessOrder] = useState(null);
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
  const [paymentRetryOrderId, setPaymentRetryOrderId] = useState(
    initialCheckoutState.record?.status === 'pending'
      ? initialCheckoutState.record.snapshot?.orderId || null
      : null,
  );
  const checkoutStateReady = Boolean(userId);
  const [newCheckoutIntent, setNewCheckoutIntent] = useState(
    () => validPurchaseProduct && initialCheckoutState.record?.status === 'completed',
  );
  const shippingRequestId = useRef(0);
  const checkoutInFlight = useRef(false);
  const cartOperationIdsRef = useRef(new Set());
  const toastTimerRef = useRef(null);
  const checkoutRecordRef = useRef(initialCheckoutState.record);
  const activeUserIdRef = useRef(userId);

  function showCartToast(text, type = 'success') {
    if (toastTimerRef.current) {
      window.clearTimeout(toastTimerRef.current);
    }

    setToast({ text, type });
    toastTimerRef.current = window.setTimeout(() => setToast(null), 3500);
  }

  function updateCheckoutRecord(record) {
    checkoutRecordRef.current = record;
    setCheckoutRecord(record);
  }

  useEffect(() => () => {
    if (toastTimerRef.current) {
      window.clearTimeout(toastTimerRef.current);
    }
  }, []);

  useEffect(() => {
    if (activeUserIdRef.current === userId) {
      return;
    }

    activeUserIdRef.current = userId;
    shippingRequestId.current += 1;
    cartOperationIdsRef.current.clear();
    setPendingCartProducts({});
    setQuantityDrafts({});
    const restored = readCheckoutRecord(userId);
    const pending = restored.record?.status === 'pending' ? restored.record : null;
    checkoutRecordRef.current = restored.record;
    setCheckoutRecord(restored.record);
    setCheckoutStorageError(restored.error);
    setNewCheckoutIntent(false);
    setPaymentRetryOrderId(
      pending?.snapshot?.orderId
        ? Number(pending.snapshot.orderId)
        : null,
    );
    setCart(pending?.snapshot.cart || {});
    setForm(customerForm(user, pending?.snapshot.form));
    setPaymentMethod(pending?.snapshot.paymentMethod || 'cod');
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
    if (location.state?.purchaseProduct) {
      navigate(location.pathname, { replace: true, state: null });
    }
  }, [location.pathname, location.state, navigate]);

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
      setCheckoutStorageError(error);
      checkoutRecordRef.current = record;
      setCheckoutRecord(record);

      if (record?.status === 'pending') {
        setNewCheckoutIntent(false);
        setPaymentRetryOrderId(
          record.snapshot?.orderId
            ? Number(record.snapshot.orderId)
            : null,
        );
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
      } else if (record?.status === 'completed') {
        setCart({});
        setForm(customerForm(user, {}));
        setPaymentMethod('cod');
        setShippingQuote(null);
        setShippingError('');
        setErrors({});
        setNewCheckoutIntent(false);
        setPaymentRetryOrderId(null);
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

  async function checkAndUpdateCart(product, quantity, operation) {
    const productId = Number(product.id);
    const productKey = String(productId);
    const operationId = `${userId}:${productKey}`;

    if (checkoutInFlight.current || checkoutRecord?.status === 'pending') {
      showCartToast('Hoàn tất checkout đang chờ trước khi thay đổi giỏ hàng.', 'error');
      return;
    }

    if (cartOperationIdsRef.current.has(operationId)) {
      return;
    }

    if (!Number.isFinite(quantity) || quantity < 0) {
      showCartToast('Vui lòng nhập số lượng hợp lệ.', 'error');
      return;
    }

    let authSnapshot;
    try {
      authSnapshot = readAuthSnapshot();
    } catch {
      showCartToast('Không thể xác minh phiên đăng nhập. Vui lòng đăng nhập lại.', 'error');
      return;
    }

    if (
      !userId
      || activeUserIdRef.current !== userId
      || authSnapshot.userId !== userId
      || !authSnapshot.token
    ) {
      showCartToast('Tài khoản đăng nhập đã thay đổi. Vui lòng tải lại trang.', 'error');
      return;
    }

    cartOperationIdsRef.current.add(operationId);
    setPendingCartProducts((current) => ({ ...current, [productKey]: true }));

    try {
      await axiosClient.post('/customer/cart/check-stock', {
        product_id: productId,
        quantity,
      }, {
        expectedAuthUserId: userId,
        expectedAuthSessionId: authSnapshot.sessionId,
      });

      if (activeUserIdRef.current !== userId) {
        return;
      }

      setCart((current) => {
        const next = { ...current };
        if (quantity === 0) {
          delete next[productKey];
        } else {
          next[productKey] = { product, quantity };
        }
        return next;
      });

      if (checkoutRecord?.status === 'completed') {
        setNewCheckoutIntent(true);
      }

      showCartToast(
        quantity === 0
          ? 'Đã xóa sản phẩm khỏi giỏ hàng.'
          : operation === 'add'
            ? 'Đã thêm sản phẩm vào giỏ hàng.'
            : 'Đã cập nhật số lượng trong giỏ hàng.',
      );
    } catch (error) {
      if (activeUserIdRef.current === userId) {
        const parsed = apiError(error);
        showCartToast(Object.values(parsed.errors)[0] || parsed.message, 'error');
      }
    } finally {
      cartOperationIdsRef.current.delete(operationId);
      if (activeUserIdRef.current === userId) {
        setPendingCartProducts((current) => {
          const next = { ...current };
          delete next[productKey];
          return next;
        });
        setQuantityDrafts((current) => {
          const next = { ...current };
          delete next[productKey];
          return next;
        });
      }
    }
  }

  function addProduct(product) {
    const nextQuantity = Number(cart[product.id]?.quantity || 0) + 1;
    void checkAndUpdateCart(product, nextQuantity, 'add');
  }

  function updateQuantity(productId, quantity) {
    const productKey = String(productId);
    const product = cart[productKey]?.product;
    const quantityText = String(quantity).trim();
    const nextQuantity = Number(quantityText);

    if (!product) {
      return;
    }

    if (!quantityText || !Number.isFinite(nextQuantity) || nextQuantity < 0) {
      setQuantityDrafts((current) => {
        const next = { ...current };
        delete next[productKey];
        return next;
      });
      showCartToast('Vui lòng nhập số lượng hợp lệ.', 'error');
      return;
    }

    if (nextQuantity === Number(cart[productKey].quantity)) {
      setQuantityDrafts((current) => {
        const next = { ...current };
        delete next[productKey];
        return next;
      });
      return;
    }

    void checkAndUpdateCart(product, nextQuantity, 'quantity');
  }

  function updateClaimMessage(key, type, text) {
    setClaimMessages((current) => ({ ...current, [key]: { type, text } }));
  }

  function validateClaimFile(event, key) {
    const file = event.currentTarget.files?.[0];
    if (!file) {
      return;
    }

    const validationMessage = validateClaimEvidence(file);
    if (validationMessage) {
      event.currentTarget.value = '';
      updateClaimMessage(key, 'error', validationMessage);
      return;
    }

    setClaimMessages((current) => {
      const next = { ...current };
      delete next[key];
      return next;
    });
  }

  async function submitClaim(event, order, item) {
    event.preventDefault();
    const formElement = event.currentTarget;
    const claimKey = `${order.id}:${item.id}`;
    const formData = new FormData(formElement);
    const evidence = formData.get('evidence');
    const fileError = validateClaimEvidence(evidence);

    if (fileError) {
      updateClaimMessage(claimKey, 'error', fileError);
      return;
    }

    const claimedQuantity = Number(formData.get('claimed_quantity'));
    if (
      !Number.isFinite(claimedQuantity)
      || claimedQuantity <= 0
      || claimedQuantity > Number(item.quantity)
    ) {
      updateClaimMessage(
        claimKey,
        'error',
        'Số lượng khiếu nại phải lớn hơn 0 và không vượt quá số lượng đã mua.',
      );
      return;
    }

    formData.set('order_item_id', String(item.id));
    formData.set('claimed_quantity', String(claimedQuantity));
    setClaimSubmittingKey(claimKey);
    setClaimMessages((current) => {
      const next = { ...current };
      delete next[claimKey];
      return next;
    });

    try {
      const response = await axiosClient.post(
        `/customer/orders/${order.id}/claims`,
        formData,
        { headers: { 'Content-Type': 'multipart/form-data' } },
      );
      updateClaimMessage(claimKey, 'success', response.message);
      formElement.reset();
    } catch (error) {
      const parsed = apiError(error);
      updateClaimMessage(
        claimKey,
        'error',
        Object.values(parsed.errors)[0] || parsed.message,
      );
    } finally {
      setClaimSubmittingKey(null);
    }
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

  function resetForNewCheckout() {
    setCart({});
    setForm(customerForm(user, {}));
    setPaymentMethod('cod');
    setShippingQuote(null);
    setShippingError('');
    setErrors({});
    setNewCheckoutIntent(false);
    setPaymentRetryOrderId(null);
  }

  async function retryPaymentInitialization() {
    if (!paymentRetryOrderId || checkoutLoading) {
      return;
    }

    const pendingRecord = checkoutRecordRef.current?.status === 'pending'
      ? checkoutRecordRef.current
      : null;
    if (!pendingRecord) {
      setMessage('Không tìm thấy trạng thái checkout đang chờ. Vui lòng tải lại trang.');
      setMessageType('error');
      return;
    }

    setCheckoutLoading(true);
    setMessage('');
    setMessageType('success');

    try {
      if (paymentMethod === 'cod') {
        const completedRecord = {
          version: CHECKOUT_STORAGE_VERSION,
          userId,
          idempotencyKey: pendingRecord.idempotencyKey,
          status: 'completed',
          fingerprint: pendingRecord.fingerprint,
        };
        writeCheckoutRecord(userId, completedRecord);
        updateCheckoutRecord(completedRecord);
        resetForNewCheckout();
        setMessage('Đơn hàng đã được xác nhận bằng thanh toán khi nhận hàng.');
        setMessageType('success');
        return;
      }

      const activeSession = readAuthSnapshot();
      const isLocalDemo = paymentMethod === 'demo_local';
      const paymentResponse = await axiosClient.post(
        isLocalDemo ? '/payments/demo/confirm' : '/payments/momo/create',
        {
          order_id: paymentRetryOrderId,
        },
        {
          expectedAuthUserId: userId,
          expectedAuthSessionId: activeSession.sessionId,
        },
      );
      const paymentReference = paymentResponse?.data?.payment_reference;
      const paymentUrl = isLocalDemo && paymentReference
        ? new URL(
          `/thanh-toan/ket-qua?payment_reference=${encodeURIComponent(paymentReference)}`,
          window.location.origin,
        ).href
        : paymentResponse?.data?.payment_url || paymentResponse?.payment_url;
      if (!paymentUrl) {
        throw new Error('Cổng thanh toán không trả về liên kết hợp lệ.');
      }

      const destination = new URL(paymentUrl);
      if (!['https:', 'http:'].includes(destination.protocol)) {
        throw new Error('Liên kết thanh toán không hợp lệ.');
      }

      const completedRecord = {
        version: CHECKOUT_STORAGE_VERSION,
        userId,
        idempotencyKey: pendingRecord.idempotencyKey,
        status: 'completed',
        fingerprint: pendingRecord.fingerprint,
      };
      writeCheckoutRecord(userId, completedRecord);
      updateCheckoutRecord(completedRecord);
      resetForNewCheckout();
      window.location.assign(destination.href);
    } catch (error) {
      const parsed = apiError(error);
      setMessage(`Không thể khởi tạo thanh toán: ${parsed.message}`);
      setMessageType('error');
    } finally {
      setCheckoutLoading(false);
    }
  }

  async function checkout(event) {
    event.preventDefault();

    if (checkoutInFlight.current) {
      return;
    }

    if (cartOperationIdsRef.current.size > 0) {
      showCartToast('Đang kiểm tra tồn kho. Vui lòng đợi trước khi đặt hàng.', 'error');
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
        setPaymentMethod(storedAtStart.record.snapshot.paymentMethod || 'cod');
        setShippingQuote(storedAtStart.record.snapshot.shippingQuote);
      }
      setMessage('Đã khôi phục checkout đang chờ. Hãy thử lại cùng nội dung, không thay đổi giỏ hàng hoặc địa chỉ.');
      setMessageType('error');
      return;
    }

    const currentPending = checkoutRecordRef.current?.status === 'pending'
      ? checkoutRecordRef.current
      : null;
    const selectedPaymentMethod = currentPending?.snapshot?.paymentMethod || paymentMethod;
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
    let paymentInitializationStarted = false;
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
              snapshot: { form: { ...form }, cart, shippingQuote, paymentMethod: selectedPaymentMethod },
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
          const responseOrderId = Number(response?.data?.id);
          if (Number.isInteger(responseOrderId) && responseOrderId > 0) {
            pendingRecord.snapshot.orderId = responseOrderId;
            if (activeUserIdRef.current === userId) {
              setPaymentRetryOrderId(responseOrderId);
            }
          }
          try {
            writeCheckoutRecord(userId, pendingRecord);
          } catch {
            // Response đã được tạo; trạng thái retry có thể tiếp tục trong tab hiện tại.
          }
          if (activeUserIdRef.current === userId) {
            updateCheckoutRecord(pendingRecord);
          }

          let paymentUrl = null;
          if (selectedPaymentMethod !== 'cod') {
            paymentInitializationStarted = true;
            const isLocalDemo = selectedPaymentMethod === 'demo_local';
            const paymentResponse = await axiosClient.post(
              isLocalDemo ? '/payments/demo/confirm' : '/payments/momo/create',
              { order_id: response.data.id },
              {
                expectedAuthUserId: userId,
                expectedAuthSessionId: requestAuthSnapshot.sessionId,
              },
            );
            const paymentReference = paymentResponse?.data?.payment_reference;
            paymentUrl = isLocalDemo && paymentReference
              ? new URL(
                `/thanh-toan/ket-qua?payment_reference=${encodeURIComponent(paymentReference)}`,
                window.location.origin,
              ).href
              : paymentResponse?.data?.payment_url || paymentResponse?.payment_url;
            if (!paymentUrl) {
              throw new Error('Cổng thanh toán không trả về liên kết hợp lệ.');
            }

            const destination = new URL(paymentUrl);
            if (!['https:', 'http:'].includes(destination.protocol)) {
              throw new Error('Liên kết thanh toán không hợp lệ.');
            }
            paymentUrl = destination.href;
          }

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
            paymentUrl,
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

        if (result.paymentUrl) {
          if (result.completionPersisted) {
            resetForNewCheckout();
          }
          window.location.assign(result.paymentUrl);
          return;
        }

        setOrders((current) => [result.response.data, ...current]);
        setSuccessOrder({
          orderCode: result.response.data.order_code,
          completionPersisted: result.completionPersisted,
        });
        if (result.completionPersisted) {
          resetForNewCheckout();
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
      if (paymentInitializationStarted) {
        if (activeUserIdRef.current === userId) {
          const orderId = attemptedRecord?.snapshot?.orderId;
          setPaymentRetryOrderId(orderId ? Number(orderId) : null);
          setMessage(`Đơn đã được tạo nhưng chưa khởi tạo được thanh toán: ${parsed.message} Hãy thử lại với cùng thông tin checkout.`);
          setMessageType('error');
        }
        return;
      }

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
  const cartMutationPending = Object.keys(pendingCartProducts).length > 0;
  const checkoutUnresolved = checkoutRecord?.status === 'pending';
  const checkoutLocked = checkoutLoading || (checkoutUnresolved && !paymentRetryOrderId);
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
  const activeProducts = products.filter((product) => product.is_active);
  const productSearchTerm = normalizeSearchText(productSearch);
  const visibleProducts = activeProducts.filter((product) => (
    !productSearchTerm
    || normalizeSearchText([
      product.name,
      product.description,
      product.slug,
      product.category?.name,
    ].filter(Boolean).join(' ')).includes(productSearchTerm)
  ));

  return (
    <div className="space-y-5">
      {toast ? (
        <div
          role={toast.type === 'error' ? 'alert' : 'status'}
          aria-live={toast.type === 'error' ? 'assertive' : 'polite'}
          className={`fixed right-4 top-4 z-[70] max-w-sm rounded-xl px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === 'error' ? 'bg-red-700' : 'bg-emerald-700'}`}
        >
          {toast.text}
        </div>
      ) : null}
      {successOrder ? (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs"
          role="dialog"
          aria-modal="true"
          aria-labelledby="order-success-title"
        >
          <div className="w-full max-w-md rounded-2xl border border-slate-100 bg-white p-6 shadow-xl">
            <div className="flex items-center gap-3 text-emerald-700">
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 font-bold">
                ✓
              </span>
              <h2 id="order-success-title" className="text-lg font-bold text-slate-900">
                Đặt hàng thành công
              </h2>
            </div>
            <p className="mt-3 text-sm leading-6 text-slate-600">
              {successOrder.orderCode ? `Mã đơn ${successOrder.orderCode}. ` : ''}
              Đơn hàng đang chờ nhân viên bán hàng xác nhận.
            </p>
            {!successOrder.completionPersisted ? (
              <p className="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">
                Không lưu được trạng thái hoàn tất trên trình duyệt. Vui lòng không tạo đơn mới; hãy tải lại và thử lại checkout đã lưu.
              </p>
            ) : null}
            <Button className="mt-6 w-full" onClick={() => setSuccessOrder(null)}>
              Tiếp tục
            </Button>
          </div>
        </div>
      ) : null}
      <section className="grid gap-5 lg:grid-cols-[1fr_360px]">
        <div className="grid content-start gap-4 sm:grid-cols-2">
          <label className="relative block sm:col-span-2">
            <span className="sr-only">Tìm kiếm sản phẩm</span>
            <Search
              aria-hidden="true"
              size={18}
              className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"
            />
            <input
              type="search"
              value={productSearch}
              onChange={(event) => setProductSearch(event.target.value)}
              placeholder="Tìm theo tên, mô tả hoặc danh mục sản phẩm"
              className="min-h-12 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-base text-slate-900 outline-none focus:border-emerald-700"
            />
          </label>
          {visibleProducts.map((product) => (
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
              <Button
                type="button"
                variant="secondary"
                className="mt-4"
                disabled={checkoutLocked || checkoutUnresolved || pendingCartProducts[product.id]}
                onClick={() => addProduct(product)}
              >
                {pendingCartProducts[product.id] ? (
                  <>
                    <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" />
                    Đang kiểm tra tồn kho...
                  </>
                ) : 'Thêm vào đơn'}
              </Button>
            </article>
          ))}
          {loading ? (
            <p role="status" className="text-sm text-slate-500 sm:col-span-2">Đang tải sản phẩm...</p>
          ) : null}
          {!loading && activeProducts.length === 0 ? (
            <p className="text-sm text-slate-500 sm:col-span-2">Chưa có sản phẩm đang kinh doanh.</p>
          ) : null}
          {!loading && activeProducts.length > 0 && visibleProducts.length === 0 ? (
            <p className="text-sm text-slate-500 sm:col-span-2">Không tìm thấy sản phẩm phù hợp.</p>
          ) : null}
        </div>

        <aside className="h-fit rounded-2xl bg-white p-5 shadow-sm">
          <h2 className="text-xl font-bold text-slate-900">Giỏ hàng</h2>
          {cartEntries.length === 0 ? (
            <p className="mt-3 text-sm text-slate-500">Chưa có sản phẩm.</p>
          ) : (
            <div className="mt-4 space-y-3">
              {cartEntries.map((entry) => (
                <div key={entry.product.id} className="grid grid-cols-[1fr_auto] items-center gap-3">
                  <div>
                    <p className="text-sm font-semibold">{entry.product.name}</p>
                    <p className="text-xs text-slate-500">{formatMoney(entry.product.base_price)} đ/{entry.product.unit}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <input
                      type="number"
                      min="0"
                      step="1"
                      aria-label={`Số lượng ${entry.product.name}`}
                      value={quantityDrafts[entry.product.id] ?? entry.quantity}
                      disabled={checkoutLocked || checkoutUnresolved || pendingCartProducts[entry.product.id]}
                      onChange={(event) => setQuantityDrafts((current) => ({
                        ...current,
                        [entry.product.id]: event.target.value,
                      }))}
                      onBlur={(event) => updateQuantity(entry.product.id, event.currentTarget.value)}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                          event.preventDefault();
                          event.currentTarget.blur();
                        }
                      }}
                      className="min-h-10 w-20 rounded-lg border border-slate-300 px-2"
                    />
                    {pendingCartProducts[entry.product.id] ? (
                      <span role="status" aria-label="Đang kiểm tra tồn kho">
                        <span aria-hidden="true" className="block h-4 w-4 animate-spin rounded-full border-2 border-emerald-700 border-r-transparent" />
                      </span>
                    ) : null}
                  </div>
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
            {checkoutUnresolved && !checkoutLoading && !paymentRetryOrderId ? (
              <p role="status" className="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
                Kết quả checkout trước chưa rõ. Hãy thử lại với nội dung đã lưu; không thay đổi giỏ hàng hoặc thông tin giao hàng.
              </p>
            ) : null}
            {checkoutStorageError ? <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{checkoutStorageError}</p> : null}
            <fieldset disabled={checkoutLocked} className="space-y-2 border-t border-slate-100 pt-3">
              <legend className="text-sm font-semibold text-slate-800">Phương thức thanh toán</legend>
              {PAYMENT_METHODS.map((method) => (
                <label
                  key={method.value}
                  className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 ${
                    paymentMethod === method.value
                      ? 'border-emerald-700 bg-emerald-50'
                      : 'border-slate-200'
                  } ${checkoutLocked ? 'cursor-not-allowed opacity-60' : ''}`}
                >
                  <input
                    type="radio"
                    name="payment_method"
                    value={method.value}
                    checked={paymentMethod === method.value}
                    onChange={() => setPaymentMethod(method.value)}
                    className="mt-1 accent-emerald-700"
                  />
                  <span>
                    <span className="block text-sm font-medium text-slate-900">{method.label}</span>
                    <span className="mt-0.5 block text-xs text-slate-600">{method.description}</span>
                  </span>
                </label>
              ))}
            </fieldset>
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
            {paymentRetryOrderId ? (
              <Button
                type="button"
                className="w-full"
                disabled={checkoutLoading}
                onClick={retryPaymentInitialization}
              >
                {checkoutLoading
                  ? 'Đang xử lý...'
                  : paymentMethod === 'cod'
                    ? 'Xác nhận thanh toán khi nhận hàng'
                    : 'Thử lại thanh toán'}
              </Button>
            ) : null}
            {!paymentRetryOrderId ? (
              <Button
                type="submit"
                className="w-full"
                disabled={checkoutLoading || shippingLoading || cartMutationPending || !quoteIsCurrent || cartEntries.length === 0 || !checkoutStateReady || Boolean(checkoutStorageError)}
              >
                {checkoutLoading
                  ? 'Đang xử lý...'
                  : checkoutUnresolved
                    ? 'Thử lại checkout đang chờ'
                    : paymentMethod === 'cod'
                      ? 'Đặt hàng'
                      : 'Đặt đơn & thanh toán'}
              </Button>
            ) : null}
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
                {order.payment_status ? (
                  <p className="mt-1 text-xs text-slate-600">
                    Thanh toán: {order.payment_status === 'succeeded'
                      ? 'Đã thanh toán'
                      : order.payment_status === 'failed'
                        ? 'Thất bại'
                        : 'Đang chờ'}
                    {order.payment_method === 'mock' ? ' · Demo local' : ''}
                    {order.payment_method === 'mock' && order.status === 'pending'
                      ? ' · Chờ Sales xác nhận'
                      : ''}
                  </p>
                ) : null}
                <ul className="mt-2 space-y-2">
                  {order.items.map((item) => (
                    <li key={item.id} className="rounded-lg bg-slate-50 p-3">
                      <p className="text-sm font-medium text-slate-800">
                        {item.product_name} × {item.quantity}
                      </p>
                      {item.allocated_batches?.length ? (
                        <p className="mt-1 text-xs text-emerald-700">
                          Lấy từ lô: {item.allocated_batches
                            .map((detail) => `${detail.batch_code} (${detail.quantity})`)
                            .join(', ')}
                        </p>
                      ) : (
                        <p className="mt-1 text-xs text-slate-500">
                          Lô sẽ được phân bổ khi đơn được xác nhận.
                        </p>
                      )}
                      {order.status === 'delivered' ? (
                        <details className="mt-3 rounded-xl border border-emerald-200 bg-white p-3">
                          <summary className="cursor-pointer text-sm font-semibold text-emerald-800">
                            Gửi yêu cầu khiếu nại
                          </summary>
                          <form
                            className="mt-3 space-y-3"
                            onSubmit={(event) => submitClaim(event, order, item)}
                          >
                            <label className="block text-sm font-medium text-slate-700">
                              Số lượng sản phẩm cần khiếu nại
                              <input
                                type="number"
                                name="claimed_quantity"
                                min="0.01"
                                max={Number(item.quantity)}
                                step="0.01"
                                defaultValue={item.quantity}
                                required
                                className="mt-1 min-h-10 w-full rounded-lg border border-slate-300 px-3"
                              />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                              Lý do khiếu nại
                              <select
                                name="reason"
                                required
                                defaultValue=""
                                className="mt-1 min-h-10 w-full rounded-lg border border-slate-300 px-3"
                              >
                                <option value="" disabled>Chọn lý do</option>
                                <option value="Rau củ bị hư hỏng">Rau củ bị hư hỏng</option>
                                <option value="Sản phẩm không tươi">Sản phẩm không tươi</option>
                                <option value="Sai sản phẩm hoặc số lượng">Sai sản phẩm hoặc số lượng</option>
                                <option value="Khác">Lý do khác</option>
                              </select>
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                              Mô tả thêm (không bắt buộc)
                              <textarea
                                name="description"
                                rows="3"
                                maxLength="5000"
                                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                              />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                              Ảnh minh chứng (bắt buộc, .jpg hoặc .png, nhỏ hơn 5 MB)
                              <input
                                type="file"
                                name="evidence"
                                accept=".jpg,.png,image/jpeg,image/png"
                                required
                                onChange={(event) => validateClaimFile(event, `${order.id}:${item.id}`)}
                                className="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:font-semibold file:text-emerald-800"
                              />
                            </label>
                            {claimMessages[`${order.id}:${item.id}`] ? (
                              <p
                                role={claimMessages[`${order.id}:${item.id}`].type === 'error' ? 'alert' : 'status'}
                                className={`text-sm ${claimMessages[`${order.id}:${item.id}`].type === 'error' ? 'text-red-700' : 'text-emerald-700'}`}
                              >
                                {claimMessages[`${order.id}:${item.id}`].text}
                              </p>
                            ) : null}
                            <Button
                              type="submit"
                              disabled={claimSubmittingKey !== null}
                            >
                              {claimSubmittingKey === `${order.id}:${item.id}` ? 'Đang gửi...' : 'Gửi yêu cầu'}
                            </Button>
                          </form>
                        </details>
                      ) : null}
                    </li>
                  ))}
                </ul>
              </article>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
