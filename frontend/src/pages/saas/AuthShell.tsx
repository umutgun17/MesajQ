import type { ReactNode } from 'react'
import BrandMark from '@/components/saas/BrandMark'
import { BRAND } from '@/lib/brand'

/** Giriş / kayıt / şifre sayfalarının ortak kabuğu. */
export default function AuthShell({ title, subtitle, children, footer, wide = false }: { title: string; subtitle?: string; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  return (
    <div className="min-h-screen flex items-center justify-center bg-[var(--app-bg)] px-4 py-10">
      <div className={wide ? 'w-full max-w-md' : 'w-full max-w-sm'}>
        <a href={BRAND.siteUrl} className="flex justify-center mb-7"><BrandMark size="lg" /></a>
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
          <h1 className="text-lg font-extrabold text-gray-900">{title}</h1>
          {subtitle && <p className="text-sm text-gray-500 mt-1 mb-4">{subtitle}</p>}
          {children}
        </div>
        {footer && <div className="text-center text-sm text-gray-500 mt-5">{footer}</div>}
      </div>
    </div>
  )
}

export const inputCls = 'w-full px-4 py-2.5 bg-[var(--app-bg)] border border-transparent rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white transition-colors'
export const btnCls = 'w-full py-2.5 bg-[var(--primary)] hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-lg transition-colors'
export function errorsOf(err: unknown): string {
  const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  const errs = e.response?.data?.errors
  if (errs) return Object.values(errs).flat().join(' ')
  return e.response?.data?.message || 'Bir hata oluştu, lütfen tekrar deneyin.'
}
