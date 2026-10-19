import clsx from 'clsx'
import { BRAND } from '@/lib/brand'

/** Marka kilidi: harf monogramlı rozet + marka adı (görsel varlık gerektirmez). */
export default function BrandMark({ size = 'md', stacked = false, className }: { size?: 'sm' | 'md' | 'lg'; stacked?: boolean; className?: string }) {
  const box = size === 'lg' ? 'w-12 h-12 text-xl rounded-2xl' : size === 'sm' ? 'w-7 h-7 text-xs rounded-lg' : 'w-10 h-10 text-base rounded-xl'
  const text = size === 'lg' ? 'text-2xl' : size === 'sm' ? 'text-sm' : 'text-lg'
  const initial = BRAND.name.replace(/q$/i, '')
  return (
    <div className={clsx('select-none flex items-center', stacked ? 'flex-col gap-2' : 'gap-2.5', className)}>
      <span className={clsx('inline-flex items-center justify-center font-black text-white bg-[var(--primary)] shadow-sm', box)}>
        {initial.charAt(0).toUpperCase()}<span className="opacity-80">Q</span>
      </span>
      <span className={clsx('font-extrabold tracking-tight text-gray-900', text)}>
        {initial}<span className="text-[var(--primary-strong)]">Q</span>
      </span>
    </div>
  )
}
