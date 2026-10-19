import clsx from 'clsx'

// Backend App\Services\Crm\PaymentMethod ile aynı anahtarlar. 'cod' = iki kapıda türü birden (yalnız filtre).
export type PaymentMethodKey = 'cod_cash' | 'cod_card' | 'card' | 'marketplace' | 'transfer' | 'other'

export const PAYMENT_METHOD_LABELS: Record<PaymentMethodKey, string> = {
  cod_cash: 'Kapıda nakit',
  cod_card: 'Kapıda kart',
  card: 'Kredi kartı (online)',
  marketplace: 'Pazaryeri ödemesi',
  transfer: 'Havale / EFT',
  other: 'Diğer / bilinmiyor',
}

export const PAYMENT_FILTER_OPTIONS: { value: string; label: string }[] = [
  { value: 'cod', label: 'Kapıda ödeme (tümü)' },
  { value: 'cod_cash', label: 'Kapıda nakit' },
  { value: 'cod_card', label: 'Kapıda kart' },
  { value: 'card', label: 'Kredi kartı (online)' },
  { value: 'marketplace', label: 'Pazaryeri ödemesi' },
  { value: 'transfer', label: 'Havale / EFT' },
]

const SOURCE_LABELS: Record<string, string> = {
  TicimaxWeb: 'Web',
  NativeAndroid: 'Android uygulama',
  NativeIOS: 'iOS uygulama',
}
export const sourceLabel = (s: string | null | undefined) => (s ? SOURCE_LABELS[s] || s : '—')

export function PaymentMethodBadge({ method, className }: { method?: string | null; className?: string }) {
  if (!method || method === 'other') return null
  const cod = method === 'cod_cash' || method === 'cod_card'
  return (
    <span
      className={clsx(
        'px-1.5 py-0.5 rounded border text-[10px] font-medium whitespace-nowrap',
        cod ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-white text-gray-600 border-gray-200',
        className,
      )}
    >
      {PAYMENT_METHOD_LABELS[method as PaymentMethodKey] || method}
    </span>
  )
}
