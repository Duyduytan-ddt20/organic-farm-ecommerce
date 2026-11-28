const MAX_CLAIM_EVIDENCE_BYTES = 5 * 1024 * 1024;
const CLAIM_EVIDENCE_TYPES = {
  jpg: 'image/jpeg',
  png: 'image/png',
};

export function validateClaimEvidence(file) {
  if (!file || typeof file.name !== 'string') {
    return 'Vui lòng tải lên ảnh minh chứng.';
  }

  const extension = file.name.split('.').pop()?.toLowerCase();
  if (!extension || CLAIM_EVIDENCE_TYPES[extension] !== file.type) {
    return 'Chỉ chấp nhận ảnh định dạng .jpg hoặc .png.';
  }

  if (file.size >= MAX_CLAIM_EVIDENCE_BYTES) {
    return 'Dung lượng ảnh phải nhỏ hơn 5 MB.';
  }

  return '';
}
