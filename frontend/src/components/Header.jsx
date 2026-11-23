export default function Header({ subtitle = 'KHÁCH HÀNG', title = 'Organic Farm' }) {
  return (
    <header className="bg-emerald-800 text-white px-4 py-5 shadow-sm">
      <div className="max-w-7xl mx-auto">
        <p className="text-xs font-semibold tracking-wider uppercase text-emerald-200">
          ORGANIC FARM / {subtitle}
        </p>
        <h1 className="text-2xl font-bold mt-1">{title}</h1>
      </div>
    </header>
  );
}