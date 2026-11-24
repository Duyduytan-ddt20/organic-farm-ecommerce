import CustomerLayout from './layouts/CustomerLayout';
import Button from './components/Button';

export default function App() {
  return (
    <CustomerLayout>
      <section className="rounded-2xl bg-white p-5 shadow-sm sm:p-8">
        <p className="text-sm font-semibold text-emerald-700">
          ĐỒ ÁN NHÓM 9
        </p>

        <h2 className="mt-2 text-2xl font-bold text-slate-900 sm:text-3xl">
          Website thương mại điện tử nông sản sạch
        </h2>

        <p className="mt-3 max-w-2xl leading-7 text-slate-600">
          Mua nông sản trực tiếp từ nông trại, xem thông tin
          lô thu hoạch và tra cứu nhật ký canh tác.
        </p>

        <div className="mt-6">
          <Button
            onClick={() => {
              document
                .getElementById('project-scope')
                ?.scrollIntoView({ behavior: 'smooth' });
            }}
          >
            Xem nội dung dự án
          </Button>
        </div>
      </section>

      <section
        id="project-scope"
        className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2"
      >
        <article className="rounded-2xl bg-white p-5 shadow-sm">
          <h3 className="text-lg font-semibold">
            Chi tiết sản phẩm
          </h3>
          <p className="mt-2 leading-7 text-slate-600">
            Hiển thị giá bán, lô hàng, ngày thu hoạch,
            hạn sử dụng và nhật ký canh tác.
          </p>
        </article>

        <article className="rounded-2xl bg-white p-5 shadow-sm">
          <h3 className="text-lg font-semibold">
            Quản lý lô hàng và hao hụt
          </h3>
          <p className="mt-2 leading-7 text-slate-600">
            Theo dõi tồn kho theo lô, ghi nhận xuất hủy
            và cảnh báo lô sắp hết hạn.
          </p>
        </article>
      </section>
    </CustomerLayout>
  );
}