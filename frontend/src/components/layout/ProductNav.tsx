import { Fragment } from 'react'
import { NavLink, useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import {
  Activity,
  LayoutDashboard,
  Inbox,
  MessagesSquare,
  Settings,
  MessageSquare,
  Mail,
  Bot,
  FileBarChart2,
  BarChart3,
  Radio,
  Sparkles,
  Users,
  UserRound,
  BookOpen,
  Building2,
  X,
  CreditCard,
} from 'lucide-react'
import clsx from 'clsx'
import type { LucideIcon } from 'lucide-react'
import BrandMark from '@/components/saas/BrandMark'
import { BRAND } from '@/lib/brand'

interface NavItem {
  to: string
  icon: LucideIcon
  label: string
  end?: boolean
  show: boolean
}

interface NavGroup {
  title?: string
  items: NavItem[]
}

interface Product {
  key: string
  label: string
  kicker?: string // ürün adının üst satırı; varsayılan "Yunbi" (CRM'de "Ticimax")
  icon: LucideIcon
  chip: string // panel başlığındaki renkli ikon çipi (Base SaaS stili)
  subscription?: string // abonelik kontrolünde kullanılacak ürün anahtarı (store → comments)
  prefixes: string[]
  groups: NavGroup[]
}

export function useProducts(): Product[] {
  const { t } = useTranslation()
  const { user, hasPermission, hasProduct } = useAuth()
  const su = !!user?.is_super_admin

  const products: Product[] = [
    {
      key: 'overview',
      label: t('products.overview'),
      icon: LayoutDashboard,
      chip: 'bg-indigo-100 text-indigo-600',
      prefixes: ['/'],
      groups: [
        {
          items: [
            { to: '/', icon: LayoutDashboard, label: t('nav.dashboard'), end: true, show: true },
          ],
        },
      ],
    },
    {
      key: 'inbox',
      label: t('products.inbox'),
      icon: Inbox,
      chip: 'bg-indigo-100 text-indigo-600',
      prefixes: ['/messages', '/comments', '/email-archive', '/reports/messages', '/settings/channels', '/settings/knowledge-base', '/settings/ai'],
      groups: [
        {
          items: [
            { to: '/messages/dashboard', icon: LayoutDashboard, label: t('nav.dashboard'), show: hasPermission('dashboard') },
          ],
        },
        {
          title: t('nav.managementGroup'),
          items: [
            { to: '/messages', icon: MessageSquare, label: t('nav.messages'), end: true, show: hasPermission('messages') },
            { to: '/comments', icon: MessagesSquare, label: t('nav.comments'), show: hasPermission('comments') },
            { to: '/email-archive', icon: Mail, label: t('nav.emailArchive'), show: hasPermission('email_archive') },
          ],
        },
        {
          title: t('nav.analysisGroup'),
          items: [
            { to: '/reports/messages', icon: FileBarChart2, label: t('nav.messageAnalysis'), show: hasPermission('reports') },
          ],
        },
        // Ürün-bazlı ayarlar: mesaj kanalları + AI bilgi bankası/yönetimi + Meta uygulaması bu ürüne ait
        {
          title: t('products.settings'),
          items: [
            { to: '/settings/channels', icon: Radio, label: t('nav.channels'), show: hasPermission('settings') },
            { to: '/settings/knowledge-base', icon: BookOpen, label: t('nav.knowledgeBase'), show: hasPermission('settings') },
            { to: '/settings/ai', icon: Bot, label: t('nav.aiManagement'), show: hasPermission('settings') },
            { to: '/settings/ai-reply', icon: Sparkles, label: t('nav.aiReplySettings'), show: hasPermission('settings') },
          ],
        },
      ],
    },
    {
      key: 'chatbot',
      label: t('products.chatbot'),
      icon: Bot,
      chip: 'bg-emerald-100 text-emerald-600',
      prefixes: ['/chatbot'],
      groups: [
        {
          items: [
            { to: '/chatbot', icon: Bot, label: t('nav.chatbot'), end: true, show: hasPermission('chatbot') },
            { to: '/chatbot/konusmalar', icon: MessageSquare, label: 'Konuşmalar', show: hasPermission('chatbot') },
            { to: '/chatbot/urun-performansi', icon: BarChart3, label: 'Ürün Performansı', show: hasPermission('chatbot') },
          ],
        },
      ],
    },
    {
      key: 'settings',
      label: t('products.settings'),
      icon: Settings,
      chip: 'bg-gray-200 text-gray-600',
      prefixes: ['/settings', '/admin', '/profile', '/billing'],
      groups: [
        {
          title: t('nav.accountGroup'),
          items: [
            { to: '/profile', icon: UserRound, label: t('nav.myProfile'), show: true },
          ],
        },
        {
          title: t('nav.platformGroup'),
          items: [
            { to: '/settings/general', icon: Settings, label: t('nav.general'), show: hasPermission('settings') },
            { to: '/settings/users', icon: Users, label: t('nav.users'), show: hasPermission('settings') },
            { to: '/settings/users/activity', icon: Activity, label: t('nav.userActivity'), show: hasPermission('settings') && !!user?.is_admin },
            { to: '/billing', icon: CreditCard, label: t('nav.billing'), show: !!user?.is_owner || !!user?.is_admin || su },
          ],
        },
        {
          title: t('superAdmin'),
          items: [
            { to: '/admin/tenants', icon: Building2, label: t('nav.tenants'), show: su },
            { to: '/admin/subscriptions', icon: CreditCard, label: t('nav.subscriptions'), show: su },
          ],
        },
      ],
    },
  ]

  // Tenant ürün aboneliği: 7 üründen yalnızca sahip olunanlar görünür
  const subscribed = products.filter(
    (p) => p.key === 'overview' || p.key === 'settings' || hasProduct(p.subscription ?? p.key)
  )

  // Görünür öğesi olmayan ürünler ray'de gizlenir
  return subscribed
    .map((p) => ({
      ...p,
      groups: p.groups
        .map((g) => ({ ...g, items: g.items.filter((i) => i.show) }))
        .filter((g) => g.items.length > 0),
    }))
    .filter((p) => p.groups.length > 0)
}

export function useActiveProduct(products: Product[]): Product | undefined {
  const location = useLocation()
  const path = location.pathname
  // Tanım sırası önemli: comments '/crm/reviews'u store'dan, review-widget'ı settings'ten önce yakalar
  return products.find((p) =>
    p.prefixes.some((prefix) => (prefix === '/' ? path === '/' : path.startsWith(prefix)))
  )
}

// 'store' (Ticimax CRM) bilinçli olarak üst listede değil: alt bölümde Ayarlar'ın hemen üstünde durur
const RAIL_TOP_KEYS = ['overview', 'inbox', 'chatbot']

interface ProductNavProps {
  mobileOpen: boolean
  onClose: () => void
}

export default function ProductNav({ mobileOpen, onClose }: ProductNavProps) {
  const navigate = useNavigate()
  const products = useProducts()
  const active = useActiveProduct(products)
  const topProducts = RAIL_TOP_KEYS.map((k) => products.find((p) => p.key === k)).filter(Boolean) as Product[]
  const bottomProducts = products.filter((p) => !RAIL_TOP_KEYS.includes(p.key))

  const openProduct = (p: Product) => {
    const first = p.groups[0]?.items[0]
    if (first) navigate(first.to)
  }

  const railButton = (p: Product) => {
    const isActive = active?.key === p.key
    return (
      <button
        key={p.key}
        onClick={() => openProduct(p)}
        title={p.label}
        className={clsx(
          'flex flex-col items-center gap-1 w-20 py-2.5 rounded-2xl transition-colors',
          isActive
            ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]'
            : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'
        )}
      >
        <p.icon size={22} strokeWidth={isActive ? 2.2 : 1.8} />
        {p.kicker && (
          <span className="text-[10px] leading-none opacity-70 -mb-0.5">{p.kicker}</span>
        )}
        <span className={clsx('text-[11px] leading-none', isActive ? 'font-bold' : 'font-medium')}>
          {p.label.length > 12 ? p.label.slice(0, 11) + '…' : p.label}
        </span>
      </button>
    )
  }

  const showPanel = active && !(active.key === 'overview')

  return (
    <div
      className={clsx(
        'z-40 h-screen shrink-0 flex',
        // Mobil: overlay çekmece; masaüstü: yapışkan çift kolon
        'fixed inset-y-0 left-0 md:sticky md:top-0 md:translate-x-0 transition-transform duration-300',
        mobileOpen ? 'translate-x-0' : '-translate-x-full'
      )}
    >
      {/* İkon ray — 7 ürün */}
      <aside className="w-24 bg-[var(--surface)] border-r border-[var(--line)] flex flex-col items-center py-4 gap-1">
        {/* Marka kilidi — ikon + word-mark, ray genişliğine sığar */}
        <div className="mb-3"><BrandMark size="sm" stacked /></div>
        {/* m-auto: yer varken dikey ortalar, taşarsa kırpmadan kaydırılabilir kalır */}
        <div className="flex-1 overflow-y-auto w-full flex flex-col">
          <div className="m-auto flex flex-col items-center gap-1 py-1">
            {topProducts.map(railButton)}
          </div>
        </div>
        <div className="flex flex-col items-center gap-1 pt-2 border-t border-[var(--line)] w-full">
          {bottomProducts.map((p) => (
            <Fragment key={p.key}>
              {railButton(p)}
            </Fragment>
          ))}
        </div>
      </aside>

      {/* Alt menü paneli — aktif ürünün alt başlıkları */}
      {showPanel && (
        <aside className="w-60 bg-[var(--surface)] border-r border-[var(--line)] flex flex-col">
          <div className="px-4 pt-5 pb-3 flex items-center justify-between">
            <div className="flex items-center gap-2.5">
              <div className={clsx('w-8 h-8 rounded-lg flex items-center justify-center', active.chip)}>
                <active.icon size={16} />
              </div>
              <div>
                <p className="text-[11px] font-semibold text-gray-400 leading-none">{active.kicker ?? BRAND.name}</p>
                <p className="text-[15px] font-bold text-gray-900 leading-tight">{active.label}</p>
              </div>
            </div>
            <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-gray-100 md:hidden">
              <X size={16} />
            </button>
          </div>
          <nav className="flex-1 overflow-y-auto px-3 pb-4 flex flex-col">
            <div className="space-y-1">
            {active.groups.map((group, gi) => (
              <div key={gi}>
                {group.title && (
                  <p className="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                    {group.title}
                  </p>
                )}
                {group.items.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    onClick={onClose}
                    className={({ isActive }) =>
                      clsx(
                        'flex items-center gap-2.5 px-3 py-2 rounded-xl text-[13px] transition-colors mb-0.5',
                        isActive
                          ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)] font-semibold'
                          : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium'
                      )
                    }
                  >
                    <item.icon size={16} strokeWidth={1.9} />
                    {item.label}
                  </NavLink>
                ))}
              </div>
            ))}
            </div>
          </nav>
        </aside>
      )}
    </div>
  )
}
