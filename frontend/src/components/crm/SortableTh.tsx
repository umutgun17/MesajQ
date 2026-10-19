import type { ReactNode } from 'react'
import { ChevronDown, ChevronUp } from 'lucide-react'
import clsx from 'clsx'

/**
 * Tıklanınca sıralanan tablo başlığı: ilk tık büyükten küçüğe (desc),
 * ikinci tık küçükten büyüğe (asc). Aktif kolon ok ikonuyla vurgulanır.
 */
interface Props {
  col: string
  sortBy: string
  sortDir: 'asc' | 'desc'
  onSort: (col: string) => void
  className?: string
  children: ReactNode
}

export default function SortableTh({ col, sortBy, sortDir, onSort, className, children }: Props) {
  const active = sortBy === col

  return (
    <th
      onClick={() => onSort(col)}
      className={clsx(
        'px-4 py-3 font-medium cursor-pointer select-none transition-colors hover:text-gray-800',
        active ? 'text-indigo-600' : 'text-gray-500',
        className
      )}
    >
      <span className="inline-flex items-center gap-1">
        {children}
        {active && (sortDir === 'desc' ? <ChevronDown size={12} /> : <ChevronUp size={12} />)}
      </span>
    </th>
  )
}
