import { buildVietnamDeliveryAddress, VIETNAM_PROVINCES } from '../utils/vietnamAddress';

export default function VietnamDeliveryAddressFields({
  value,
  onChange,
  disabled = false,
}) {
  const province = VIETNAM_PROVINCES.find((item) => item.code === value.provinceCode);
  const wards = province?.wards || [];
  const inputClassName = 'mt-1 w-full rounded-xl border border-slate-300 px-4 py-3';
  const updateValue = (nextValue) => onChange({
    ...nextValue,
    address: buildVietnamDeliveryAddress(nextValue),
  });

  return (
    <div className="space-y-3">
      <label className="block text-sm font-medium">
        Tỉnh/thành phố
        <select
          name="delivery_province"
          value={value.provinceCode || ''}
          disabled={disabled}
          onChange={(event) => updateValue({
            ...value,
            provinceCode: event.target.value,
            wardCode: '',
          })}
          className={inputClassName}
          required
        >
          <option value="">Chọn tỉnh/thành phố</option>
          {VIETNAM_PROVINCES.map((item) => (
            <option key={item.code} value={item.code}>{item.name}</option>
          ))}
        </select>
      </label>

      <label className="block text-sm font-medium">
        Phường/xã/đặc khu
        <select
          name="delivery_ward"
          value={value.wardCode || ''}
          disabled={disabled || !province}
          onChange={(event) => updateValue({ ...value, wardCode: event.target.value })}
          className={inputClassName}
          required
        >
          <option value="">Chọn phường/xã</option>
          {wards.map(([code, name]) => (
            <option key={code} value={code}>{name}</option>
          ))}
        </select>
      </label>

      <label className="block text-sm font-medium">
        Địa chỉ cụ thể
        <textarea
          name="delivery_detail"
          value={value.detail || ''}
          disabled={disabled}
          onChange={(event) => updateValue({ ...value, detail: event.target.value })}
          rows={3}
          maxLength={250}
          placeholder="Số nhà, tên đường, tòa nhà, ghi chú giao hàng..."
          className={inputClassName}
          required
        />
      </label>
    </div>
  );
}
