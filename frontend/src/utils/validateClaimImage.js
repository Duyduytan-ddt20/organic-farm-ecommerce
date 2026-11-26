export const CLAIM_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

const MIME_BY_EXTENSION = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
};

export function validateClaimImage(file) {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
  const expectedMime = MIME_BY_EXTENSION[extension];

  if (!expectedMime || (file.type && file.type !== expectedMime)) {
    return 'Chỉ chấp nhận ảnh .jpg, .png, .webp.';
  }

  if (!file.size || file.size >= CLAIM_IMAGE_MAX_BYTES) {
    return 'Dung lượng ảnh phải nhỏ hơn 5MB.';
  }

  return null;
}
