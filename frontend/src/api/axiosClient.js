import axios from 'axios';
import {
    authorizationForUser,
    clearAuthSnapshot,
    readAuthSnapshot,
} from '../utils/authSession';

const axiosClient = axios.create({
    baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api/v1',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
});

// Request Interceptor: Tự động gắn Access Token vào mọi request bảo vệ
axiosClient.interceptors.request.use(
    (config) => {
        const snapshot = readAuthSnapshot();
        if (config.expectedAuthUserId != null) {
            config.headers.Authorization = authorizationForUser(
                snapshot,
                config.expectedAuthUserId,
                config.expectedAuthSessionId,
            );
        } else if (snapshot.token) {
            config.headers.Authorization = `Bearer ${snapshot.token}`;
        }
        return config;
    },
    (error) => Promise.reject(error)
);

// Response Interceptor: Bắt lỗi 401 (Token hết hạn) hoặc 419 (CSRF/Session Timeout)
axiosClient.interceptors.response.use(
    (response) => response.data,
    (error) => {
        const status = error.response?.status;
        const requestUrl = error.config?.url || '';

        // Bỏ qua nếu đang gọi API login để tránh bật popup nhầm khi nhập sai mật khẩu
        if ((status === 401 || status === 419) && !requestUrl.includes('/auth/login')) {
            clearAuthSnapshot();

            // Phát sự kiện toàn cục để App.jsx mở Popup yêu cầu đăng nhập lại
            window.dispatchEvent(
                new CustomEvent('auth:session-expired', {
                    detail: {
                        status,
                        message:
                            status === 419
                                ? 'Phiên làm việc (CSRF Token) đã hết hạn do để trang quá lâu. Vui lòng đăng nhập lại.'
                                : 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại để tiếp tục thao tác.',
                    },
                })
            );
        }

        return Promise.reject(error);
    }
);

export default axiosClient;