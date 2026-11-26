import { useEffect, useState } from 'react';

export default function SessionExpiredModal({ onRedirectLogin }) {
    const [expiredInfo, setExpiredInfo] = useState(null);

    useEffect(() => {
        const handleSessionExpired = (event) => {
            setExpiredInfo(event.detail);
        };

        window.addEventListener('auth:session-expired', handleSessionExpired);
        return () => window.removeEventListener('auth:session-expired', handleSessionExpired);
    }, []);

    if (!expiredInfo) return null;

    const handleConfirm = () => {
        setExpiredInfo(null);
        if (onRedirectLogin) {
            onRedirectLogin();
        } else {
            window.location.href = '/login';
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
            <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl border border-slate-100">
                <div className="flex items-center gap-3 text-amber-600">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 font-bold">
                        !
                    </span>
                    <h3 className="text-lg font-bold text-slate-900">
                        Phiên làm việc đã hết hạn (Mã {expiredInfo.status})
                    </h3>
                </div>

                <p className="mt-3 text-sm leading-6 text-slate-600">
                    {expiredInfo.message}
                </p>

                <div className="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        onClick={handleConfirm}
                        className="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition cursor-pointer"
                    >
                        Đăng nhập lại ngay
                    </button>
                </div>
            </div>
        </div>
    );
}