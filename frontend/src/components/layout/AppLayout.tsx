import { useEffect, useState } from 'react'
import { Outlet, Navigate, NavLink, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import ProductNav, { useProducts, useActiveProduct } from './ProductNav'
import TenantSwitcher from './TenantSwitcher'
import LanguageSwitcher from './LanguageSwitcher'
import SubscriptionBanner from '@/components/saas/SubscriptionBanner'
import { BRAND } from '@/lib/brand'
import { Menu, LogOut, Sun, Moon, Eye } from 'lucide-react'
import clsx from 'clsx'

export default function AppLayout() {
  const { t } = useTranslation()
  const { user, isLoading, logout, isImpersonating, stopImpersonation } = useAuth()
  const navigate = useNavigate()
  const [navOpen, setNavOpen] = useState(false)
  // Kullanıcı kararı: Skin A 'Soft' seçildi (crisp token'ları index.css'te yedek durur)
  useEffect(() => {
    document.documentElement.dataset.skin = 'soft'
  }, [])
  // Açık/koyu tema — localStorage'da kalıcı
  const [theme, setTheme] = useState<'light' | 'dark'>(
    () => (localStorage.getItem('beta_theme') as 'light' | 'dark') || 'light'
  )
  useEffect(() => {
    document.documentElement.dataset.theme = theme
    localStorage.setItem('beta_theme', theme)
  }, [theme])
  const products = useProducts()
  const active = useActiveProduct(products)

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[var(--app-bg)]">
        <div className="w-8 h-8 border-4 border-[var(--primary)] border-t-transparent rounded-full animate-spin" />
      </div>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  return (
    <div className="flex min-h-screen bg-[var(--app-bg)]">
      {/* Mobil overlay */}
      {navOpen && (
        <div className="fixed inset-0 z-30 bg-black/40 md:hidden" onClick={() => setNavOpen(false)} />
      )}

      <ProductNav mobileOpen={navOpen} onClose={() => setNavOpen(false)} />

      <div className="flex-1 flex flex-col min-w-0">
        {/* Kullanıcı gözünden görme bandı — süper admin başka kullanıcının oturumunda */}
        {isImpersonating && (
          <div className="sticky top-0 z-30 h-8 bg-violet-600 text-white flex items-center gap-2 px-4 text-xs md:text-[13px]">
            <Eye size={14} className="shrink-0" />
            <span className="truncate">
              {t('impersonation.banner', { name: user?.name ?? '' })}
              {user?.tenant?.name ? ` · ${user.tenant.name}` : ''}
            </span>
            <button
              onClick={async () => {
                await stopImpersonation()
                navigate('/settings/users')
              }}
              className="ml-auto shrink-0 px-2.5 py-0.5 rounded-md bg-white/15 hover:bg-white/25 font-semibold transition-colors"
            >
              {t('impersonation.exit')}
            </button>
          </div>
        )}

        <SubscriptionBanner />

        {/* Üst bar */}
        <header className={clsx(
          'sticky z-20 h-14 bg-[var(--surface)]/85 backdrop-blur border-b border-[var(--line)] flex items-center gap-3 px-4',
          isImpersonating ? 'top-8' : 'top-0'
        )}>
          <button
            onClick={() => setNavOpen(true)}
            className="p-2 -ml-2 rounded-lg hover:bg-gray-100 transition-colors md:hidden"
          >
            <Menu size={20} />
          </button>

          <div className="min-w-0 flex items-baseline gap-2">
            <span className="text-sm font-bold text-gray-900 truncate">
              {active && active.key !== 'overview' ? `${active.kicker ?? BRAND.name} ${active.label}` : BRAND.name}
            </span>
            {user?.tenant?.name && (
              <span className="hidden sm:inline text-xs text-gray-400 truncate">{user.tenant.name}</span>
            )}
          </div>

          <div className="ml-auto flex items-center gap-2">
            {/* Kaydırmalı gece/gündüz anahtarı */}
            <button
              onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
              role="switch"
              aria-checked={theme === 'dark'}
              className="relative w-[52px] h-7 rounded-full bg-gray-100 hover:bg-gray-200 transition-colors shrink-0"
              title={theme === 'dark' ? 'Açık tema' : 'Koyu tema'}
            >
              <Sun size={13} className="absolute left-[7px] top-1/2 -translate-y-1/2 text-amber-500" />
              <Moon size={13} className="absolute right-[7px] top-1/2 -translate-y-1/2 text-indigo-300" />
              <span
                className={clsx(
                  'absolute top-0.5 left-0.5 w-6 h-6 rounded-full shadow-md flex items-center justify-center transition-transform duration-300 ease-out',
                  theme === 'dark' && 'translate-x-[24px]'
                )}
                style={{ background: theme === 'dark' ? '#3b4059' : '#ffffff' }}
              >
                {theme === 'dark'
                  ? <Moon size={13} className="text-indigo-300" />
                  : <Sun size={13} className="text-amber-500" />}
              </span>
            </button>
            <LanguageSwitcher />
            {user?.is_super_admin && <TenantSwitcher />}
            <NavLink
              to="/profile"
              className="flex items-center gap-2 pl-1 pr-2 py-1 rounded-full hover:bg-gray-100 transition-colors"
            >
              <span className="w-7 h-7 rounded-full bg-[var(--primary-soft)] text-[var(--primary-strong)] flex items-center justify-center text-xs font-bold">
                {user?.name?.charAt(0).toUpperCase()}
              </span>
              <span className="hidden lg:block max-w-[140px] text-left">
                <span className="block text-xs font-semibold text-gray-700 truncate leading-tight">{user?.name}</span>
                <span className="block text-[10px] font-medium text-gray-400 truncate leading-tight">
                  {user?.is_super_admin ? t('superAdmin') : user?.role === 'admin' ? t('roles.admin') : t('roles.agent')}
                </span>
              </span>
            </NavLink>
            <button
              onClick={logout}
              className="p-2 text-gray-400 hover:text-gray-700 rounded-lg hover:bg-gray-100 transition-colors"
              title={t('common.logout')}
            >
              <LogOut size={15} />
            </button>
          </div>
        </header>

        <main className="flex-1 overflow-auto">
          <div className="p-3 md:p-6">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
