import { useEffect, useState } from 'react';
import { Search } from 'lucide-react';
import { Link } from 'react-router-dom';
import axiosClient from '../api/axiosClient';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../utils/apiError';
import { assignProductImages } from '../utils/productImages';
import { normalizeSearchText } from '../utils/searchText';

export default function HomePage() {
  const { user } = useAuth();
  const roleName = user?.role?.role_name;
  const canPurchase = roleName === 'Customer' || roleName === 'Admin';
  const [products, setProducts] = useState([]);
  const [productSearch, setProductSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;

    axiosClient.get('/products').then((response) => {
      if (active) {
        const availableProducts = Array.isArray(response.data)
          ? response.data.filter((product) => product.is_active)
          : [];
        setProducts(assignProductImages(availableProducts));
      }
    }).catch((requestError) => {
      if (active) {
        setError(apiError(requestError).message);
      }
    }).finally(() => {
      if (active) {
        setLoading(false);
      }
    });

    return () => {
      active = false;
    };
  }, []);

  const searchTerm = normalizeSearchText(productSearch);
  const visibleProducts = products.filter((product) => (
    normalizeSearchText([
      product.name,
      product.description,
      product.slug,
      product.category?.name,
    ].filter(Boolean).join(' ')).includes(searchTerm)
  ));

  return (
      <section id="san-pham" className="scroll-mt-6">
        <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-sm font-semibold text-emerald-700">TỪ NÔNG TRẠI</p>
            <h2 className="mt-1 text-2xl font-bold text-slate-900">Rau củ quả đang bán</h2>
          </div>
          <p className="text-sm text-slate-600">Xem thông tin thoải mái; đăng nhập để đặt mua.</p>
        </div>

        <label className="relative mb-4 block">
          <span className="sr-only">Tìm kiếm sản phẩm</span>
          <Search
            aria-hidden="true"
            size={18}
            className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"
          />
          <input
            type="search"
            value={productSearch}
            onChange={(event) => setProductSearch(event.target.value)}
            placeholder="Tìm theo tên, mô tả hoặc danh mục sản phẩm"
            className="min-h-12 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-base text-slate-900 outline-none focus:border-emerald-700"
          />
        </label>

        {loading ? (
          <p role="status" className="rounded-xl bg-white p-5 text-slate-600 shadow-sm">
            Đang tải danh sách sản phẩm...
          </p>
        ) : null}
        {error ? (
          <p role="alert" className="rounded-xl bg-red-50 p-5 text-red-700">{error}</p>
        ) : null}
        {!loading && !error && products.length === 0 ? (
          <p className="rounded-xl bg-white p-5 text-slate-600 shadow-sm">
            Hiện chưa có sản phẩm đang kinh doanh.
          </p>
        ) : null}

        {visibleProducts.length > 0 ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {visibleProducts.map((product) => (
              <article key={product.id} className="rounded-2xl bg-white p-4 shadow-sm">
                <img
                  src={product.display_image}
                  alt={product.name}
                  loading="lazy"
                  className="aspect-[4/3] w-full rounded-xl object-cover"
                />
                <p className="mt-4 text-xs font-semibold uppercase text-emerald-700">
                  {product.category?.name || 'Nông sản'}
                </p>
                <h3 className="mt-1 text-lg font-bold text-slate-900">{product.name}</h3>
                <p className="mt-2 min-h-12 text-sm leading-6 text-slate-600">
                  {product.description || 'Nông sản tươi từ nông trại.'}
                </p>
                <div className="mt-4 flex items-center justify-between gap-3">
                  <p className="font-bold text-emerald-800">
                    {Number(product.base_price || 0).toLocaleString('vi-VN')} đ/{product.unit}
                  </p>
                  <div className="flex items-center gap-2">
                    <Link
                      to={`/products/${product.id}`}
                      className="rounded-xl border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800"
                    >
                      Chi tiết
                    </Link>
                    {canPurchase ? (
                      <Link
                        to="/mua-sam"
                        state={{ purchaseProduct: product }}
                        className="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white"
                      >
                        Mua
                      </Link>
                    ) : !user ? (
                      <Link
                        to="/dang-nhap"
                        state={{ from: '/mua-sam', purchaseProduct: product }}
                        className="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white"
                      >
                        Mua
                      </Link>
                    ) : (
                      <span className="text-xs text-slate-500">Chỉ dành cho tài khoản khách hàng</span>
                    )}
                  </div>
                </div>
              </article>
            ))}
          </div>
        ) : null}
        {!loading && !error && products.length > 0 && visibleProducts.length === 0 ? (
          <p className="rounded-xl bg-white p-5 text-slate-600 shadow-sm">
            Không tìm thấy sản phẩm phù hợp.
          </p>
        ) : null}
      </section>
  );
}
