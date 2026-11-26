import { useState } from 'react';
import axiosClient from '../api/axiosClient';
import AuthContext from './AuthContext';

const USER_STORAGE_KEY = 'organic_farm_user';

function getStoredUser() {
  const savedUser = localStorage.getItem(USER_STORAGE_KEY);
  if (!savedUser) return null;

  try {
    return JSON.parse(savedUser);
  } catch {
    localStorage.removeItem(USER_STORAGE_KEY);
    localStorage.removeItem('access_token');
    return null;
  }
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(getStoredUser);

  async function signIn(token, authenticatedUser) {
    if (localStorage.getItem('access_token')) {
      await axiosClient.post('/auth/logout');
    }

    localStorage.setItem('access_token', token);
    localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(authenticatedUser));
    setUser(authenticatedUser);
  }

  async function signOut() {
    try {
      await axiosClient.post('/auth/logout');
    } finally {
      localStorage.removeItem('access_token');
      localStorage.removeItem(USER_STORAGE_KEY);
      setUser(null);
    }
  }

  return (
    <AuthContext.Provider value={{ user, signIn, signOut }}>
      {children}
    </AuthContext.Provider>
  );
}
