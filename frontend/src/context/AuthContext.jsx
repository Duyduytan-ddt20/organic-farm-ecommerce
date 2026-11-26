import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import axiosClient from '../api/axiosClient';
import {
  AUTH_SESSION_LOCK_NAME,
  AUTH_SESSION_STORAGE_KEY,
  clearAuthSnapshot,
  readAuthSnapshot,
  writeAuthSnapshot,
} from '../utils/authSession';

const AuthContext = createContext(null);

async function withAuthSessionLock(callback) {
  const lockRequest = globalThis.navigator?.locks?.request;
  if (!lockRequest) {
    return callback();
  }

  return lockRequest.call(globalThis.navigator.locks, AUTH_SESSION_LOCK_NAME, callback);
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => readAuthSnapshot().user);

  useEffect(() => {
    const syncAuthSession = (event) => {
      if (
        event
        && event.key !== null
        && ![AUTH_SESSION_STORAGE_KEY, 'access_token', 'user'].includes(event.key)
      ) {
        return;
      }

      setUser(readAuthSnapshot().user);
    };

    window.addEventListener('storage', syncAuthSession);
    return () => window.removeEventListener('storage', syncAuthSession);
  }, []);

  const value = useMemo(() => {
    async function persist(nextUser, token) {
      await withAuthSessionLock(() => writeAuthSnapshot(nextUser, token));
      setUser(nextUser);
    }

    return {
      user,
      async register(payload) {
        const { data } = await axiosClient.post('/auth/register', payload);
        await persist(data.data.user, data.data.token);
        return data.data.user;
      },
      async login(payload) {
        const { data } = await axiosClient.post('/auth/login', payload);
        await persist(data.data.user, data.data.token);
        return data.data.user;
      },
      async logout() {
        const session = readAuthSnapshot();
        if (!user || session.userId !== String(user.id)) {
          setUser(session.user);
          return;
        }

        try {
          await axiosClient.post('/auth/logout', {}, {
            headers: { Authorization: `Bearer ${session.token}` },
            expectedAuthUserId: session.userId,
            expectedAuthSessionId: session.sessionId,
          });
        } catch {
          // Token hết hạn vẫn xóa phiên trên trình duyệt.
        }

        await withAuthSessionLock(() => {
          const currentSession = readAuthSnapshot();
          if (
            currentSession.userId === session.userId
            && currentSession.token === session.token
            && currentSession.sessionId === session.sessionId
          ) {
            clearAuthSnapshot();
            setUser(null);
          } else {
            setUser(currentSession.user);
          }
        });
      },
    };
  }, [user]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

// Keep the shared context hook beside its provider; it is not a refreshable component.
// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth phải được dùng trong AuthProvider.');
  }
  return context;
}
