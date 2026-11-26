import assert from 'node:assert/strict';
import test from 'node:test';
import {
  AUTH_SESSION_REVISION,
  AUTH_SESSION_STORAGE_KEY,
  authorizationForUser,
  canClearRejectedCheckout,
  readAuthSnapshot,
  writeAuthSnapshot,
} from './authSession.js';

const SESSION_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

function createStorage() {
  const values = new Map();
  return {
    getItem(key) {
      return values.has(key) ? values.get(key) : null;
    },
    setItem(key, value) {
      values.set(key, String(value));
    },
    removeItem(key) {
      values.delete(key);
    },
  };
}

function storeSnapshot(storage, overrides = {}) {
  storage.setItem(AUTH_SESSION_STORAGE_KEY, JSON.stringify({
    user: { id: 'customer-a' },
    token: 'token-a',
    revision: AUTH_SESSION_REVISION,
    sessionId: SESSION_A,
    ...overrides,
  }));
}

function readPendingRecord(storage, userId) {
  return storage.getItem(`organic-farm:checkout:v1:${userId}`);
}

test('legacy user and token in separate keys are never treated as an authenticated session', () => {
  const storage = createStorage();
  storage.setItem('user', JSON.stringify({ id: 'customer-a' }));
  storage.setItem('access_token', 'token-b');

  assert.deepEqual(readAuthSnapshot(storage), {
    user: null,
    userId: '',
    token: '',
    revision: null,
    sessionId: '',
  });
  assert.throws(
    () => authorizationForUser(readAuthSnapshot(storage), 'customer-a'),
    { code: 'ERR_AUTH_CONTEXT_CHANGED' },
  );
});

test('snapshots missing user, token, revision, or a valid session id fail closed', async (t) => {
  const invalidCases = [
    ['missing user', { user: undefined }],
    ['missing token', { token: undefined }],
    ['missing revision', { revision: undefined }],
    ['unsupported revision', { revision: AUTH_SESSION_REVISION + 1 }],
    ['missing session id', { sessionId: undefined }],
    ['malformed session id', { sessionId: 'not-a-session-id' }],
  ];

  for (const [label, overrides] of invalidCases) {
    await t.test(`invalid ${label}`, () => {
      const storage = createStorage();
      storeSnapshot(storage, overrides);
      assert.equal(readAuthSnapshot(storage).userId, '');
      assert.equal(readAuthSnapshot(storage).token, '');
    });
  }
});

test('a valid atomic snapshot authenticates only its recorded user and session', () => {
  const storage = createStorage();
  storeSnapshot(storage);

  const snapshot = readAuthSnapshot(storage);
  assert.equal(snapshot.userId, 'customer-a');
  assert.equal(snapshot.token, 'token-a');
  assert.equal(snapshot.revision, AUTH_SESSION_REVISION);
  assert.equal(snapshot.sessionId, SESSION_A);
  assert.equal(authorizationForUser(snapshot, 'customer-a', SESSION_A), 'Bearer token-a');
  assert.equal(canClearRejectedCheckout({
    requestSnapshot: snapshot,
    currentSnapshot: snapshot,
    activeUserId: 'customer-a',
    ownerUserId: 'customer-a',
    validationErrors: { recipient_phone: ['Invalid'] },
  }), true);
  assert.throws(
    () => authorizationForUser(snapshot, 'customer-b', SESSION_A),
    { code: 'ERR_AUTH_CONTEXT_CHANGED' },
  );
});

test('switching from A to B cannot authorize A pending checkout with B session', () => {
  const storage = createStorage();
  const pendingRecord = JSON.stringify({
    userId: 'customer-a',
    idempotencyKey: 'stable-checkout-key',
    status: 'pending',
  });
  storage.setItem('organic-farm:checkout:v1:customer-a', pendingRecord);
  writeAuthSnapshot({ id: 'customer-a' }, 'token-a', storage);
  const requestSnapshot = readAuthSnapshot(storage);

  writeAuthSnapshot({ id: 'customer-b' }, 'token-b', storage);
  const currentSnapshot = readAuthSnapshot(storage);

  assert.throws(
    () => authorizationForUser(currentSnapshot, 'customer-a', requestSnapshot.sessionId),
    { code: 'ERR_AUTH_CONTEXT_CHANGED' },
  );
  assert.equal(readPendingRecord(storage, 'customer-a'), pendingRecord);
});

test('pending A is retained when the session changes before a 422 is handled', () => {
  const storage = createStorage();
  const pendingRecord = JSON.stringify({
    userId: 'customer-a',
    idempotencyKey: 'stable-checkout-key',
    status: 'pending',
  });
  storage.setItem('organic-farm:checkout:v1:customer-a', pendingRecord);
  writeAuthSnapshot({ id: 'customer-a' }, 'token-a', storage);
  const requestSnapshot = readAuthSnapshot(storage);

  writeAuthSnapshot({ id: 'customer-b' }, 'token-b', storage);
  const canClear = canClearRejectedCheckout({
    requestSnapshot,
    currentSnapshot: readAuthSnapshot(storage),
    activeUserId: 'customer-b',
    ownerUserId: 'customer-a',
    validationErrors: { recipient_name: ['Required'] },
  });
  if (canClear) {
    storage.removeItem('organic-farm:checkout:v1:customer-a');
  }

  assert.equal(canClear, false);
  assert.equal(readPendingRecord(storage, 'customer-a'), pendingRecord);
});

test('logging A back in preserves and resumes the original idempotency key', () => {
  const storage = createStorage();
  const pendingRecord = JSON.stringify({
    userId: 'customer-a',
    idempotencyKey: 'stable-checkout-key',
    status: 'pending',
  });
  storage.setItem('organic-farm:checkout:v1:customer-a', pendingRecord);

  writeAuthSnapshot({ id: 'customer-a' }, 'token-a', storage);
  writeAuthSnapshot({ id: 'customer-b' }, 'token-b', storage);
  writeAuthSnapshot({ id: 'customer-a' }, 'token-a-again', storage);

  const snapshot = readAuthSnapshot(storage);
  assert.equal(snapshot.userId, 'customer-a');
  assert.equal(authorizationForUser(snapshot, 'customer-a', snapshot.sessionId), 'Bearer token-a-again');
  assert.equal(readPendingRecord(storage, 'customer-a'), pendingRecord);
  assert.equal(JSON.parse(readPendingRecord(storage, 'customer-a')).idempotencyKey, 'stable-checkout-key');
});
