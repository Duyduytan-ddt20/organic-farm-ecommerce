import administrativeDivisions from '../data/vietnamAdministrativeDivisions.json';

export const VIETNAM_PROVINCES = administrativeDivisions.provinces;

export function buildVietnamDeliveryAddress({ provinceCode, wardCode, detail }) {
  const province = VIETNAM_PROVINCES.find((item) => item.code === provinceCode);
  const ward = province?.wards.find(([code]) => code === wardCode);
  const normalizedDetail = String(detail || '').trim();

  if (!province || !ward || !normalizedDetail) {
    return '';
  }

  return `${normalizedDetail}, ${ward[1]}, ${province.name}, Việt Nam`;
}
