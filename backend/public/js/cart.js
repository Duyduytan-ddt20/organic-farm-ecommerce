document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // 1. Tạo Container cho Toast Notification ở góc trên bên phải
    let toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:12px;max-width:380px;width:calc(100% - 40px);pointer-events:none;';
        document.body.appendChild(toastContainer);
    }

    function showToast(message, type = 'success') {
        const isSuccess = type === 'success';
        const toast = document.createElement('div');

        // Dùng màu chữ đậm (xanh đậm / đỏ đậm) trên nền sáng có viền rõ nét, không phụ thuộc Tailwind build
        toast.style.cssText = isSuccess
            ? 'pointer-events:auto;display:flex;align-items:flex-start;gap:10px;padding:14px 16px;border-radius:12px;background:#ecfdf5;border:1.5px solid #10b981;color:#065f46;font-size:14px;font-weight:600;box-shadow:0 10px 25px -5px rgba(0,0,0,0.12);transition:all 0.3s ease;transform:translateX(120%);opacity:0;'
            : 'pointer-events:auto;display:flex;align-items:flex-start;gap:10px;padding:14px 16px;border-radius:12px;background:#fef2f2;border:1.5px solid #ef4444;color:#991b1b;font-size:14px;font-weight:600;box-shadow:0 10px 25px -5px rgba(0,0,0,0.12);transition:all 0.3s ease;transform:translateX(120%);opacity:0;';

        const iconColor = isSuccess ? '#059669' : '#dc2626';
        const icon = isSuccess
            ? `<svg style="width:20px;height:20px;flex-shrink:0;margin-top:1px;color:${iconColor};" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 13l4 4L19 7"/></svg>`
            : `<svg style="width:20px;height:20px;flex-shrink:0;margin-top:1px;color:${iconColor};" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;

        toast.innerHTML = `${icon}<div style="flex:1;line-height:1.4;color:${isSuccess ? '#065f46' : '#991b1b'};">${message}</div>`;
        toastContainer.appendChild(toast);

        requestAnimationFrame(() => {
            toast.style.transform = 'translateX(0)';
            toast.style.opacity = '1';
        });

        setTimeout(() => {
            toast.style.transform = 'translateX(120%)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    // 2. Cập nhật Badge số lượng trên Header
    function updateCartBadge(totalQuantity) {
        const badges = document.querySelectorAll('[data-cart-badge], #cart-badge');
        badges.forEach(badge => {
            badge.textContent = totalQuantity;
            if (totalQuantity > 0) {
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        });
    }

    // 3. Helper gọi Fetch API chuẩn JSON
    async function apiRequest(url, method, body = null) {
        const options = {
            method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        };
        if (body) {
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);
        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            data = { message: 'Phản hồi từ máy chủ không hợp lệ.' };
        }

        if (!response.ok) {
            const error = new Error(data.message || 'Có lỗi xảy ra.');
            error.status = response.status;
            error.data = data.data || {};
            throw error;
        }
        return data;
    }

    // 4. Sự kiện Thêm vào giỏ hàng (AJAX + Khóa nút + Loading Spinner)
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-add-to-cart]');
        if (!btn || btn.disabled) return;

        e.preventDefault();
        const productId = parseInt(btn.dataset.productId, 10);
        let quantity = parseInt(btn.dataset.quantity || '1', 10);

        if (btn.dataset.qtySource) {
            const sourceInput = document.querySelector(btn.dataset.qtySource);
            if (sourceInput) {
                quantity = Math.max(1, parseInt(sourceInput.value || '1', 10));
            }
        }

        const spinner = btn.querySelector('[data-spinner]');
        const btnText = btn.querySelector('[data-btn-text]');
        const originalText = btnText ? btnText.textContent : '';

        btn.disabled = true;
        if (spinner) spinner.classList.remove('hidden');
        if (btnText) btnText.textContent = 'Đang kiểm tra...';

        try {
            const addUrl = window.CART_CONFIG?.addUrl || '/cart/add';
            const res = await apiRequest(addUrl, 'POST', {
                product_id: productId,
                quantity: quantity,
            });

            updateCartBadge(res.data.total_quantity);
            showToast(res.message || 'Thêm sản phẩm thành công!', 'success');
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            btn.disabled = false;
            if (spinner) spinner.classList.add('hidden');
            if (btnText) btnText.textContent = originalText;
        }
    });

    // 5. Khóa/Mở khóa dòng sản phẩm trong giỏ & Bật/Tắt Spinner
    function setRowLoading(productId, isLoading) {
        const row = document.querySelector(`[data-cart-row="${productId}"]`);
        if (!row) return;

        const spinner = row.querySelector(`[data-row-spinner="${productId}"]`);
        const controls = row.querySelectorAll('button, input');

        controls.forEach(el => {
            el.disabled = isLoading;
        });

        if (spinner) {
            if (isLoading) {
                spinner.classList.remove('hidden');
                spinner.classList.add('inline-flex');
            } else {
                spinner.classList.add('hidden');
                spinner.classList.remove('inline-flex');
            }
        }
    }

    // 6. Hàm gọi API cập nhật số lượng trong /cart
    async function updateCartQuantity(productId, newQuantity, inputEl) {
        const prevValue = parseInt(inputEl.defaultValue || inputEl.value, 10);

        if (isNaN(newQuantity) || newQuantity < 1) {
            inputEl.value = prevValue;
            showToast('Số lượng tối thiểu là 1.', 'error');
            return;
        }

        setRowLoading(productId, true);

        try {
            const updateBase = window.CART_CONFIG?.updateBaseUrl || '/cart/update';
            const res = await apiRequest(`${updateBase}/${productId}`, 'PATCH', {
                quantity: newQuantity,
            });

            inputEl.value = res.data.quantity;
            inputEl.defaultValue = res.data.quantity;

            const subtotalEl = document.querySelector(`[data-item-subtotal="${productId}"]`);
            if (subtotalEl) subtotalEl.textContent = res.data.formatted_item_subtotal;

            const stockEl = document.querySelector(`[data-item-stock="${productId}"]`);
            if (stockEl && res.data.available_stock !== undefined) {
                stockEl.textContent = res.data.available_stock;
            }

            const cartSubtotalEl = document.getElementById('cart-subtotal-amount');
            const cartTotalEl = document.getElementById('cart-total-amount');
            if (cartSubtotalEl) cartSubtotalEl.textContent = res.data.formatted_total_amount;
            if (cartTotalEl) cartTotalEl.textContent = res.data.formatted_total_amount;

            updateCartBadge(res.data.total_quantity);
            showToast(res.message || 'Cập nhật số lượng thành công.', 'success');
        } catch (err) {
            const validQty = err.data?.current_quantity || prevValue;
            inputEl.value = validQty;
            inputEl.defaultValue = validQty;
            showToast(err.message, 'error');
        } finally {
            setRowLoading(productId, false);
        }
    }

    // Sự kiện bấm nút Giảm (-) hoặc Tăng (+)
    document.addEventListener('click', (e) => {
        const decBtn = e.target.closest('[data-qty-decrease]');
        if (decBtn && !decBtn.disabled) {
            const productId = parseInt(decBtn.dataset.productId, 10);
            const input = document.querySelector(`[data-qty-input][data-product-id="${productId}"]`);
            if (!input) return;
            const current = parseInt(input.value || '1', 10);
            if (current <= 1) {
                showToast('Số lượng tối thiểu là 1. Bấm biểu tượng thùng rác nếu muốn xóa.', 'error');
                return;
            }
            updateCartQuantity(productId, current - 1, input);
            return;
        }

        const incBtn = e.target.closest('[data-qty-increase]');
        if (incBtn && !incBtn.disabled) {
            const productId = parseInt(incBtn.dataset.productId, 10);
            const input = document.querySelector(`[data-qty-input][data-product-id="${productId}"]`);
            if (!input) return;
            const current = parseInt(input.value || '1', 10);
            updateCartQuantity(productId, current + 1, input);
        }
    });

    // Sự kiện nhập trực tiếp vào ô số lượng
    document.addEventListener('change', (e) => {
        const input = e.target.closest('[data-qty-input]');
        if (!input || input.disabled) return;
        const productId = parseInt(input.dataset.productId, 10);
        const newVal = parseInt(input.value, 10);
        updateCartQuantity(productId, newVal, input);
    });

    // 7. Sự kiện Xóa sản phẩm khỏi giỏ hàng (AJAX)
    document.addEventListener('click', async (e) => {
        const removeBtn = e.target.closest('[data-remove-item]');
        if (!removeBtn || removeBtn.disabled) return;

        const productId = parseInt(removeBtn.dataset.productId, 10);
        setRowLoading(productId, true);

        try {
            const removeBase = window.CART_CONFIG?.removeBaseUrl || '/cart/remove';
            const res = await apiRequest(`${removeBase}/${productId}`, 'DELETE');

            const row = document.querySelector(`[data-cart-row="${productId}"]`);
            if (row) row.remove();

            updateCartBadge(res.data.total_quantity);

            if (res.data.is_empty) {
                document.getElementById('cart-content-wrapper')?.classList.add('hidden');
                document.getElementById('cart-content-wrapper')?.classList.remove('grid');
                document.getElementById('cart-empty-state')?.classList.remove('hidden');
            } else {
                const cartSubtotalEl = document.getElementById('cart-subtotal-amount');
                const cartTotalEl = document.getElementById('cart-total-amount');
                if (cartSubtotalEl) cartSubtotalEl.textContent = res.data.formatted_total_amount;
                if (cartTotalEl) cartTotalEl.textContent = res.data.formatted_total_amount;
            }

            showToast(res.message || 'Đã xóa sản phẩm khỏi giỏ hàng.', 'success');
        } catch (err) {
            setRowLoading(productId, false);
            showToast(err.message, 'error');
        }
    });
});