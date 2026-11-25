export default function Button({
  children,
  variant = 'primary',
  type = 'button',
  className = '',
  ...props
}) {
  const variants = {
    primary: 'bg-emerald-700 hover:bg-emerald-800 text-white',
    danger: 'bg-red-600 hover:bg-red-700 text-white',
    secondary: 'bg-slate-600 hover:bg-slate-700 text-white',
  };

  return (
    <button
      type={type}
      className={`min-h-12 px-5 py-3 rounded-xl font-semibold text-base transition-colors flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50 ${variants[variant]} ${className}`}
      {...props}
    >
      {children}
    </button>
  );
}