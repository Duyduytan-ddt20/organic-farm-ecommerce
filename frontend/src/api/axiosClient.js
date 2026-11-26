import axios from 'axios';
import { authorizationForUser, readAuthSnapshot } from '../utils/authSession';

const axiosClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api/v1',
  headers: {
    Accept: 'application/json',
  },
});

axiosClient.interceptors.request.use((config) => {
  const snapshot = readAuthSnapshot();
  if (config.expectedAuthUserId != null || config.expectedAuthSessionId != null) {
    config.headers.Authorization = authorizationForUser(
      snapshot,
      config.expectedAuthUserId,
      config.expectedAuthSessionId,
    );
  } else if (snapshot.token) {
    config.headers.Authorization = `Bearer ${snapshot.token}`;
  }
  return config;
});

export default axiosClient;