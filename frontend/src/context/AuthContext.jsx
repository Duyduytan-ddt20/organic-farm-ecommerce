import { useMemo, useState } from 'react';
import axiosClient from '../api/axiosClient';
import AuthContext from './AuthContext.js';

function readStoredUser() {
  const raw = localStorage.getItem('user');
  if (!raw) {
    return null;
  }
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(readStoredUser);

  const value = useMemo(() => {
    function persist(nextUser, token) {
      localStorage.setItem('access_token', token);
      localStorage.setItem('user', JSON.stringify(nextUser));
      setUser(nextUser);
    }

    async function clearSession() {
      try {
        await axiosClient.post('/auth/logout');
      } catch {
        // A stale token still needs to be removed from this browser.
      }
      localStorage.removeItem('access_token');
      localStorage.removeItem('user');
      setUser(null);
    }

    return {
      user,
      async register(payload) {
        const { data } = await axiosClient.post('/auth/register', payload);
        persist(data.data.user, data.data.token);
        return data.data.user;
      },
      async login(payload) {
        const { data } = await axiosClient.post('/auth/login', payload);
        persist(data.data.user, data.data.token);
        return data.data.user;
      },
      async signIn(token, authenticatedUser) {
        persist(authenticatedUser, token);
      },
      async signOut() {
        await clearSession();
      },
      async logout() {
        await clearSession();
      },
    };
  }, [user]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
