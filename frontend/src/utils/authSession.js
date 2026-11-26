export const AUTH_SESSION_STORAGE_KEY = 'organic-farm:auth-session:v1';
export const AUTH_SESSION_LOCK_NAME = 'organic-farm:auth-session';
export const AUTH_SESSION_REVISION = 1;

const SESSION_ID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function emptySnapshot() {
  return {
    user: null,
    userId: '',
    token: '',
    revision: null,
    sessionId: '',
  };
}

function createSnapshot(user, token, revision, sessionId) {
  if (
    !user
    || typeof user !== 'object'
    || Array.isArray(user)
    || user.id == null
    || typeof token !== 'string'
    || !token
    || revision !== AUTH_SESSION_REVISION
    || typeof sessionId !== 'string'
    || !SESSION_ID_PATTERN.test(sessionId)
  ) {
    return emptySnapshot();
  }

  return {
    user,
    userId: String(user.id),
    token,
    revision,
    sessionId,
  };
}

function createSessionId() {
  const randomUUID = globalThis.crypto?.randomUUID;
  if (typeof randomUUID !== 'function') {
    throw new Error('Không thể tạo mã phiên đăng nhập an toàn.');
  }

  return randomUUID.call(globalThis.crypto);
}

export function readAuthSnapshot(storage = globalThis.localStorage) {
  const rawSession = storage.getItem(AUTH_SESSION_STORAGE_KEY);
  if (rawSession === null) {
    return emptySnapshot();
  }

  try {
    const session = JSON.parse(rawSession);
    return createSnapshot(session?.user, session?.token, session?.revision, session?.sessionId);
  } catch {
    return emptySnapshot();
  }
}

export function writeAuthSnapshot(user, token, storage = globalThis.localStorage) {
  const snapshot = createSnapshot(user, token, AUTH_SESSION_REVISION, createSessionId());
  if (!snapshot.user) {
    throw new Error('Không thể lưu phiên đăng nhập không hợp lệ.');
  }

  storage.setItem(AUTH_SESSION_STORAGE_KEY, JSON.stringify({
    revision: snapshot.revision,
    sessionId: snapshot.sessionId,
    user: snapshot.user,
    token: snapshot.token,
  }));
  storage.removeItem('user');
  storage.removeItem('access_token');
}

export function clearAuthSnapshot(storage = globalThis.localStorage) {
  storage.removeItem('user');
  storage.removeItem('access_token');
  storage.removeItem(AUTH_SESSION_STORAGE_KEY);
}

export function authorizationForUser(snapshot, expectedUserId, expectedSessionId) {
  if (
    expectedUserId == null
    || !snapshot?.token
    || !snapshot?.sessionId
    || snapshot.revision !== AUTH_SESSION_REVISION
    || snapshot.userId !== String(expectedUserId)
    || (expectedSessionId != null && snapshot.sessionId !== expectedSessionId)
  ) {
    const error = new Error('Tài khoản đăng nhập đã thay đổi. Checkout đang chờ được giữ nguyên.');
    error.code = 'ERR_AUTH_CONTEXT_CHANGED';
    throw error;
  }

  return `Bearer ${snapshot.token}`;
}

export function canClearRejectedCheckout({
  requestSnapshot,
  currentSnapshot,
  activeUserId,
  ownerUserId,
  validationErrors,
}) {
  const sameOwnerSession = Boolean(
    requestSnapshot
    && currentSnapshot
    && requestSnapshot.sessionId
    && currentSnapshot.sessionId === requestSnapshot.sessionId
    && requestSnapshot.revision === AUTH_SESSION_REVISION
    && currentSnapshot.revision === requestSnapshot.revision
    && requestSnapshot.userId === String(ownerUserId)
    && currentSnapshot.userId === String(ownerUserId)
    && currentSnapshot.token === requestSnapshot.token
    && String(activeUserId) === String(ownerUserId)
  );
  const fields = Object.keys(validationErrors || {});

  return sameOwnerSession
    && fields.length > 0
    && fields.every((field) => ['recipient_name', 'recipient_phone', 'items'].includes(field.split('.')[0]));
}
