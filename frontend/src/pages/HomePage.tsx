import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import { useProducts } from '@/components/layout/ProductNav'
import { ArrowRight } from 'lucide-react'
import clsx from 'clsx'
import OnboardingChecklist from '@/components/saas/OnboardingChecklist'
import { BRAND } from '@/lib/brand'

// 8 alt ürün ana sayfada büyük kart; CRM + Ayarlar altta kompakt satır
const MAIN_KEYS = ['inbox', 'chatbot']

export default function HomePage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const products = useProducts()

  const mainProducts = MAIN_KEYS.map((k) => products.find((p) => p.key === k)).filter(
    (p): p is NonNullable<typeof p> => !!p
  )
  const utilityProducts = products.filter((p) => !MAIN_KEYS.includes(p.key) && p.key !== 'overview')

  return (
    <div className="max-w-6xl mx-auto space-y-8">
      {/* Karşılama */}
      <div className="pt-2">
        <h1 className="text-2xl font-extrabold text-gray-900">
          {t('home.welcome', { name: user?.name?.split(' ')[0] ?? '' })}
        </h1>
        <p className="text-sm text-gray-500 mt-1">{t('home.subtitle')}</p>
      </div>

      <OnboardingChecklist />

      {/* 7 ürün kartı */}
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
        {mainProducts.map((p) => {
          const first = p.groups[0]?.items[0]
          const quickLinks = p.groups.flatMap((g) => g.items).slice(0, 4)
          return (
            <div
              key={p.key}
              onClick={() => first && navigate(first.to)}
              className="group bg-white rounded-xl shadow-sm border border-gray-100 p-5 cursor-pointer transition-all hover:-translate-y-0.5 hover:shadow-md"
            >
              <div className="flex items-start justify-between">
                <div className={clsx('w-12 h-12 rounded-2xl flex items-center justify-center', p.chip)}>
                  <p.icon size={22} />
                </div>
                <span className="flex items-center gap-1 text-xs font-semibold text-gray-300 group-hover:text-[var(--primary-strong)] transition-colors">
                  {t('home.open')} <ArrowRight size={13} />
                </span>
              </div>
              <p className="mt-4 text-[11px] font-semibold text-gray-400 leading-none">{BRAND.name}</p>
              <h2 className="text-[17px] font-extrabold text-gray-900 leading-tight">{p.label}</h2>
              <p className="mt-1.5 text-[13px] text-gray-500 leading-snug min-h-[36px]">
                {t(`home.desc.${p.key}`)}
              </p>
              {quickLinks.length > 1 && (
                <div className="mt-3 pt-3 border-t border-gray-100 flex flex-wrap gap-1.5">
                  {quickLinks.map((item) => (
                    <Link
                      key={item.to}
                      to={item.to}
                      onClick={(e) => e.stopPropagation()}
                      className="px-2.5 py-1 rounded-full bg-[var(--app-bg)] text-[11px] font-semibold text-gray-500 hover:bg-[var(--primary-soft)] hover:text-[var(--primary-strong)] transition-colors"
                    >
                      {item.label}
                    </Link>
                  ))}
                </div>
              )}
            </div>
          )
        })}
      </div>

      {/* CRM + Ayarlar — kompakt */}
      {utilityProducts.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
          {utilityProducts.map((p) => {
            const first = p.groups[0]?.items[0]
            return (
              <div
                key={p.key}
                onClick={() => first && navigate(first.to)}
                className="group bg-white rounded-xl shadow-sm border border-gray-100 px-5 py-4 cursor-pointer transition-all hover:-translate-y-0.5 hover:shadow-md flex items-center gap-4"
              >
                <div className={clsx('w-10 h-10 rounded-xl flex items-center justify-center shrink-0', p.chip)}>
                  <p.icon size={18} />
                </div>
                <div className="min-w-0 flex-1">
                  <h2 className="text-sm font-bold text-gray-900">{p.label}</h2>
                  <p className="text-xs text-gray-500 truncate">{t(`home.desc.${p.key}`)}</p>
                </div>
                <ArrowRight size={15} className="text-gray-300 group-hover:text-[var(--primary-strong)] transition-colors shrink-0" />
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
