import { createContext, useContext, useMemo, useState } from 'react';
import axiosClient from '../api/axiosClient';

const AuthContext = createContext(null);

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
      async logout() {
        try {
          await axiosClient.post('/auth/logout');
        } catch {
          // Token hết hạn vẫn xóa phiên trên trình duyệt.
        }
        localStorage.removeItem('access_token');
        localStorage.removeItem('user');
        setUser(null);
      },
    };
  }, [user]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth phải được dùng trong AuthProvider.');
  }
  return context;
}
