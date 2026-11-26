export function apiError(error) {
  const data = error?.response?.data;
  const fieldErrors = {};

  if (data?.errors && typeof data.errors === 'object') {
    for (const [field, messages] of Object.entries(data.errors)) {
      fieldErrors[field] = Array.isArray(messages) ? messages[0] : String(messages);
    }
  }

  return {
    message: data?.message || 'Không kết nối được máy chủ. Hãy kiểm tra backend đang chạy.',
    errors: fieldErrors,
  };
}
