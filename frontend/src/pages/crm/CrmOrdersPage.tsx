import { useEffect, useState, useCallback, useRef, memo, useLayoutEffect } from 'react'
import { createPortal } from 'react-dom'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import type { CrmOrder, OrderStats, OrderSource, PaginatedResponse } from '@/types'
import { getChannelBadgeClasses, getChannelLabel } from '@/lib/channelUtils'
import {
  ShoppingBag, Search, X, ChevronDown, Check, CreditCard, Ban, Calendar, Truck,
  AlarmClock, Copy, ExternalLink, Phone, MapPin, Barcode, ChevronUp, RefreshCw, Info,
} from 'lucide-react'
import clsx from 'clsx'
import { ImageLightbox, ProductThumb, type LightboxItem } from '@/components/crm/ProductImage'
import { PAYMENT_FILTER_OPTIONS, PaymentMethodBadge, sourceLabel } from '@/components/crm/PaymentMethod'

/*
 * Siparişler — Trendyol satıcı paneli (Sipariş Paketleri) düzeni:
 * üstte adetli durum sekmeleri, altında filtre satırı, her sipariş
 * fotoğraflı ürün satırlarıyla bir kart. "Geciken" sekmesi ve kart üstündeki
 * kalan süre/gecikme rozeti OrderDelayService hesabından gelir.
 */

const fmtDate = (d: Date) => {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

const DEFAULT_PAYMENT = '1'
const DEFAULT_SOURCES = ['TicimaxWeb', 'NativeAndroid', 'NativeIOS']
// Varsayılan tarih aralığı: son 30 gün (kullanıcı isteği 5 Eki)
const DEFAULT_DATE_FROM = fmtDate(new Date(Date.now() - 30 * 86_400_000))
const DEFAULT_DATE_TO = fmtDate(new Date())

type Tab = 'all' | 'new' | 'processing' | 'shipped' | 'delivered' | 'undelivered' | 'cancelled' | 'returns' | 'delayed'

const TABS: { key: Tab; label: string }[] = [
  { key: 'all', label: 'Tümü' },
  { key: 'new', label: 'Yeni' },
  { key: 'processing', label: 'İşleme Alınan' },
  { key: 'shipped', label: 'Kargoda' },
  { key: 'delivered', label: 'Teslim Edilen' },
  { key: 'undelivered', label: 'Teslim Edilemeyen' },
  { key: 'cancelled', label: 'İptal Edilen' },
  { key: 'returns', label: 'İade' },
  { key: 'delayed', label: 'Geciken' },
]

interface OrderItemRow {
  id: number
  product_id: number | null
  product_name: string | null
  variant_info: string | null
  sku: string | null
  barcode: string | null
  quantity: number
  unit_price: number | string
  total_price: number | string
  image_url: string | null
  status: number | null
  status_label: string | null
}

interface Timeline {
  stage: 'prep' | 'transit'
  due_date: string
  delay_days: number
  age_days: number
}

/* Toplam açıklaması (OrderPricingBreakdown): indirimler negatif, eklemeler pozitif */
interface PricingLine {
  type: string
  label: string
  amount: number
  code?: string | null
  detail?: string | null
  warn?: boolean
}
interface Pricing {
  products: number
  discount: number
  shipping: number
  surcharge: number
  lines: PricingLine[]
}

type OrderRow = CrmOrder & {
  items?: OrderItemRow[]
  pricing?: Pricing | null
  timeline?: Timeline | null
  phone?: string | null
  ship_city?: string | null
  ship_district?: string | null
  order_source?: string | null
  payment_method?: string | null
}

type Stats = OrderStats & { synced_at?: string | null; product_qty?: number | null }

// Sipariş/kargo takip no gibi görünen arama (örn. TB1567972192, 4239323651) tüm tarihlerde yapılır;
// diğer her durumda tarih filtresi geçerlidir (ürün filtresi + "bugün" birlikte çalışır).
const looksLikeOrderNo = (s: string) => /^[A-Za-z]{0,3}\d{6,}$/.test(s.replace(/\s/g, ''))

// Kalem sayısı bunu aşarsa ilk COLLAPSED_SHOW kalem gösterilir, kalanı "+N ürün" ile açılır
const COLLAPSE_OVER = 10
const COLLAPSED_SHOW = 3
// Yeni sipariş yoklaması (sn) — sync her dakika çalışıyor
const POLL_MS = 45_000

export default function CrmOrdersPage() {
  const { t, i18n } = useTranslation()
  const { hasPermission } = useAuth()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [orders, setOrders] = useState<OrderRow[]>([])
  const [stats, setStats] = useState<Stats | null>(null)
  const [tabCounts, setTabCounts] = useState<Partial<Record<Tab, number>>>({})
  const [sources, setSources] = useState<OrderSource[]>([])
  const [cargoCompanies, setCargoCompanies] = useState<{ cargo_company: string; order_count: number }[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [copied, setCopied] = useState<string | null>(null)
  const [pendingNew, setPendingNew] = useState<OrderRow[]>([])
  // İlk sayfanın tamamı yeniyse arada sayfaya sığmayan siparişler olabilir → ekleme yerine yeniden yükle
  const pendingGapRef = useRef(false)
  const [lightbox, setLightbox] = useState<{ items: LightboxItem[]; index: number } | null>(null)
  const [, setNowTick] = useState(0)

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  // Filtreler — varsayılan: ödenmiş, site+uygulama, bugün
  const [tab, setTab] = useState<Tab>((searchParams.get('tab') as Tab) || 'all')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [productInput, setProductInput] = useState('')
  const [product, setProduct] = useState('')
  const [filterPayment, setFilterPayment] = useState(DEFAULT_PAYMENT)
  const [filterSources, setFilterSources] = useState<string[]>(DEFAULT_SOURCES)
  const [defaultSources, setDefaultSources] = useState<string[]>(DEFAULT_SOURCES)
  const [filterCargo, setFilterCargo] = useState('')
  const [filterMethod, setFilterMethod] = useState('')
  const [filterDateFrom, setFilterDateFrom] = useState(DEFAULT_DATE_FROM)
  const [filterDateTo, setFilterDateTo] = useState(DEFAULT_DATE_TO)
  const [filterCustomerId] = useState(searchParams.get('customer_id') || '')
  const [showSourceDropdown, setShowSourceDropdown] = useState(false)
  const [showDateDropdown, setShowDateDropdown] = useState(false)
  const [sort, setSort] = useState('order_date:desc')

  const sentinelRef = useRef<HTMLDivElement>(null)
  const sourceDropdownRef = useRef<HTMLDivElement>(null)
  const dateDropdownRef = useRef<HTMLDivElement>(null)

  // Metin kutuları: yazmayı bitirince uygula
  useEffect(() => {
    const h = setTimeout(() => setSearch(searchInput.trim()), 400)
    return () => clearTimeout(h)
  }, [searchInput])
  useEffect(() => {
    const h = setTimeout(() => setProduct(productInput.trim()), 400)
    return () => clearTimeout(h)
  }, [productInput])

  function getDatePresets() {
    const now = new Date()
    const today = fmtDate(now)
    const yesterday = fmtDate(new Date(now.getTime() - 86_400_000))
    const d7 = fmtDate(new Date(now.getTime() - 7 * 86_400_000))
    const d30 = fmtDate(new Date(now.getTime() - 30 * 86_400_000))
    const monthStart = fmtDate(new Date(now.getFullYear(), now.getMonth(), 1))
    return [
      { key: 'today' as const, label: t('crm.orders.datePresets.today'), from: today, to: today },
      { key: 'yesterday' as const, label: t('crm.orders.datePresets.yesterday'), from: yesterday, to: yesterday },
      { key: 'last7Days' as const, label: t('crm.orders.datePresets.last7Days'), from: d7, to: today },
      { key: 'last30Days' as const, label: t('crm.orders.datePresets.last30Days'), from: d30, to: today },
      { key: 'thisMonth' as const, label: t('crm.orders.datePresets.thisMonth'), from: monthStart, to: today },
      { key: 'allTime' as const, label: t('crm.orders.datePresets.allTime'), from: '', to: '' },
    ]
  }
  const datePresets = getDatePresets()

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (sourceDropdownRef.current && !sourceDropdownRef.current.contains(e.target as Node)) setShowSourceDropdown(false)
      if (dateDropdownRef.current && !dateDropdownRef.current.contains(e.target as Node)) setShowDateDropdown(false)
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  // Sekme dışındaki filtreler (sekme adetleri bunlarla hesaplanır)
  const buildBaseParams = useCallback(() => {
    const [sortBy, sortDir] = sort.split(':')
    const params: Record<string, string> = { sort_by: sortBy, sort_dir: sortDir }
    if (search) params.search = search
    if (product) params.product = product
    if (filterPayment) params.payment_status = filterPayment
    if (filterSources.length > 0) params.sources = filterSources.join(',')
    if (filterCargo) params.cargo_company = filterCargo
    if (filterMethod) params.payment_methods = filterMethod
    // Tarih filtresi her zaman uygulanır; yalnız sipariş/takip no araması tüm tarihlerde
    if (!looksLikeOrderNo(search)) {
      if (filterDateFrom) params.date_from = filterDateFrom
      if (filterDateTo) params.date_to = filterDateTo
    }
    if (filterCustomerId) params.customer_id = filterCustomerId
    return params
  }, [search, product, filterPayment, filterSources, filterCargo, filterMethod, filterDateFrom, filterDateTo, filterCustomerId, sort])

  const buildParams = useCallback(() => ({
    ...buildBaseParams(), tab, with_items: '1', per_page: '20',
  }), [buildBaseParams, tab])

  const fetchOrders = useCallback(() => {
    setLoading(true)
    setPendingNew([])
    pendingGapRef.current = false
    api.get<PaginatedResponse<OrderRow>>('/crm/orders', { params: buildParams() }).then((res) => {
      setOrders(res.data.data)
      setPage(res.data.current_page)
      setLastPage(res.data.last_page)
      setTotal(res.data.total)
      setLoading(false)
    }).catch(() => setLoading(false))
  }, [buildParams])

  const fetchMeta = useCallback(() => {
    const params = buildBaseParams()
    Promise.all([
      api.get<Stats>('/crm/orders/stats', { params }),
      api.get<Partial<Record<Tab, number>>>('/crm/orders/tab-counts', { params }),
    ]).then(([statsRes, tabsRes]) => {
      setStats(statsRes.data)
      setTabCounts(tabsRes.data)
    }).catch(() => undefined)
  }, [buildBaseParams])

  // Kanal listesi filtrelerden bağımsız — sayfa açılışında bir kez
  useEffect(() => {
    api.get<OrderSource[]>('/crm/orders/sources').then((sourcesRes) => {
      setSources(sourcesRes.data)

      // Varsayılan kanal seçimini tenant'ın gerçek kaynaklarına uydur.
      // DİKKAT: içerik aynıysa MEVCUT referans korunur (sonsuz döngü olmasın).
      const sameSet = (a: string[], b: string[]) => a.length === b.length && a.every((v) => b.includes(v))
      const avail = sourcesRes.data.map((s) => s.order_source)
      setDefaultSources((prevDefault) => {
        const kept = DEFAULT_SOURCES.filter((s) => avail.includes(s))
        const next = kept.length > 0 ? kept : avail
        if (sameSet(next, prevDefault)) return prevDefault
        setFilterSources((cur) => (sameSet(cur, prevDefault) && !sameSet(cur, next) ? next : cur))
        return next
      })
    }).catch(() => undefined)
  }, [])

  useEffect(() => { fetchOrders() }, [fetchOrders])
  useEffect(() => { fetchMeta() }, [fetchMeta])
  useEffect(() => {
    api.get('/crm/orders/cargo-companies').then((res) => setCargoCompanies(res.data)).catch(() => undefined)
  }, [])

  // Sonsuz kaydırma
  const fetchMore = useCallback(() => {
    if (loadingMore || page >= lastPage) return
    setLoadingMore(true)
    api.get<PaginatedResponse<OrderRow>>('/crm/orders', { params: { ...buildParams(), page: String(page + 1) } }).then((res) => {
      // Üste canlı eklenen siparişler offset'i kaydırır → tekrar gelenleri ele
      setOrders((prev) => {
        const seen = new Set(prev.map((o) => o.id))
        return [...prev, ...res.data.data.filter((o) => !seen.has(o.id))]
      })
      setPage(res.data.current_page)
      setLastPage(res.data.last_page)
      setLoadingMore(false)
    }).catch(() => setLoadingMore(false))
  }, [page, lastPage, loadingMore, buildParams])

  useEffect(() => {
    if (loading) return
    const el = sentinelRef.current
    if (!el) return
    const obs = new IntersectionObserver(([entry]) => { if (entry.isIntersecting) fetchMore() }, { rootMargin: '300px' })
    obs.observe(el)
    return () => obs.disconnect()
  }, [loading, fetchMore])

  // Canlı akış: sekme görünürken düzenli aralıkla ilk sayfayı yokla. Liste en üstteyse yeni
  // siparişler sessizce eklenir; kullanıcı aşağıdaysa kaydırmayı bozmamak için şerit çıkar.
  // Mevcut kartların statü/kargo değişiklikleri de yerinde güncellenir.
  const ordersRef = useRef<OrderRow[]>([])
  ordersRef.current = orders
  useEffect(() => {
    if (sort !== 'order_date:desc') return
    const poll = () => {
      if (document.hidden || loading) return
      api.get<PaginatedResponse<OrderRow>>('/crm/orders', { params: buildParams() }).then((res) => {
        const fresh = res.data.data
        const known = new Map(ordersRef.current.map((o) => [o.id, o]))
        const newest = ordersRef.current[0]?.order_date ?? ''
        const incoming = fresh.filter((o) => !known.has(o.id) && (o.order_date ?? '') >= newest)
        // Yerinde güncelleme (statü, kargo, tutar)
        const changed = fresh.filter((o) => {
          const k = known.get(o.id)
          return k && (k.status !== o.status || k.cargo_tracking_no !== o.cargo_tracking_no || k.payment_status !== o.payment_status || k.total_amount !== o.total_amount)
        })
        if (changed.length > 0) {
          const byId = new Map(changed.map((o) => [o.id, o]))
          setOrders((prev) => prev.map((o) => byId.get(o.id) ?? o))
        }
        // Sekme uzun süre gizli kaldıysa (yoklama durur) ilk sayfanın hepsi yeni gelir; aradaki
        // siparişler hiç yüklenmez ve listede saat boşluğu oluşur (17 Eki: 20:23 → 16:47).
        const gap = incoming.length > 0 && incoming.length === fresh.length && fresh.length >= res.data.per_page
        if (gap && window.scrollY < 200) {
          fetchOrders()
          fetchMeta()
          return
        }
        if (incoming.length > 0) {
          setTotal(res.data.total)
          if (gap) pendingGapRef.current = true
          if (window.scrollY < 200) {
            setOrders((prev) => [...incoming, ...prev.filter((o) => !incoming.some((n) => n.id === o.id))])
            setPendingNew([])
          } else {
            setPendingNew(incoming)
          }
          fetchMeta()
        }
      }).catch(() => undefined)
    }
    const h = setInterval(poll, POLL_MS)
    const onVis = () => { if (!document.hidden) poll() }
    document.addEventListener('visibilitychange', onVis)
    return () => { clearInterval(h); document.removeEventListener('visibilitychange', onVis) }
  }, [buildParams, sort, loading, fetchMeta, fetchOrders])

  // "x dk önce güncellendi" yazısı kendiliğinden ilerlesin
  useEffect(() => {
    const h = setInterval(() => setNowTick((n) => n + 1), 30_000)
    return () => clearInterval(h)
  }, [])

  const showPendingNew = () => {
    if (pendingGapRef.current) {
      fetchOrders()
      window.scrollTo({ top: 0, behavior: 'smooth' })
      return
    }
    setOrders((prev) => [...pendingNew, ...prev.filter((o) => !pendingNew.some((n) => n.id === o.id))])
    setPendingNew([])
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const arraysEqual = (a: string[], b: string[]) => a.length === b.length && [...a].sort().every((v, i) => v === [...b].sort()[i])

  const hasActiveFilters = !!(
    searchInput || productInput || filterCargo || filterMethod ||
    filterPayment !== DEFAULT_PAYMENT ||
    !arraysEqual(filterSources, defaultSources) ||
    filterDateFrom !== DEFAULT_DATE_FROM ||
    filterDateTo !== DEFAULT_DATE_TO
  )

  const clearFilters = () => {
    setSearchInput(''); setProductInput(''); setFilterCargo(''); setFilterMethod('')
    setFilterPayment(DEFAULT_PAYMENT)
    setFilterSources(defaultSources)
    setFilterDateFrom(DEFAULT_DATE_FROM)
    setFilterDateTo(DEFAULT_DATE_TO)
  }

  const toggleSource = (source: string) =>
    setFilterSources((prev) => (prev.includes(source) ? prev.filter((s) => s !== source) : [...prev, source]))

  const money = useCallback((val: number | string) => {
    const num = typeof val === 'string' ? parseFloat(val) : val
    return moneyFormatter(locale).format(num || 0)
  }, [locale])

  const formatDate = useCallback((dateStr: string | null) => {
    if (!dateStr) return '-'
    const d = new Date(dateStr.replace(' ', 'T'))
    return d.toLocaleDateString(locale, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  }, [locale])

  const shortMonths = t('crm.orders.shortMonths', { returnObjects: true }) as string[]
  const dateButtonLabel = (() => {
    if (!filterDateFrom && !filterDateTo) return t('crm.orders.datePresets.allTime')
    const preset = datePresets.find((p) => p.from === filterDateFrom && p.to === filterDateTo)
    if (preset) return preset.label
    const fmt = (d: string) => {
      const parts = d.split('-')
      return `${parts[2]} ${shortMonths[parseInt(parts[1], 10) - 1]}`
    }
    if (filterDateFrom && filterDateTo) return `${fmt(filterDateFrom)} — ${fmt(filterDateTo)}`
    if (filterDateFrom) return `${fmt(filterDateFrom)} —`
    return `— ${fmt(filterDateTo)}`
  })()
  const hasDateFilter = !!(filterDateFrom || filterDateTo)
  const dateIgnored = looksLikeOrderNo(search)

  const getStatusLabel = useCallback((status: number, fallbackLabel?: string | null) => {
    const key = `crm.orders.statuses.${status}`
    const translated = t(key)
    if (translated !== key) return translated
    return fallbackLabel || `${t('crm.orders.statusLabel')} ${status}`
  }, [t])

  const copy = useCallback((text: string) => {
    navigator.clipboard?.writeText(text).then(() => {
      setCopied(text)
      setTimeout(() => setCopied(null), 1500)
    }).catch(() => undefined)
  }, [])

  const openDetail = useCallback((id: number) => navigate(`/crm/orders/${id}`), [navigate])
  const openImage = useCallback((items: LightboxItem[], index: number) => setLightbox({ items, index }), [])

  return (
    <div className="space-y-4">
      {/* Başlık */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
            <ShoppingBag className="text-orange-600" size={20} />
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-gray-900">{t('crm.orders.title')}</h1>
            <p className="text-sm text-gray-500">{t('crm.orders.subtitle')}</p>
          </div>
        </div>
        {stats && (
          <div className="flex items-center gap-4 text-sm">
            <MiniStat label="Ciro (ödenen)" value={money(stats.total_revenue)} />
            <MiniStat label="Ödenen sipariş" value={stats.paid_count.toLocaleString(locale)} />
            <MiniStat label="Ort. sepet" value={money(stats.avg_basket)} />
            {hasPermission('crm_delays') && <Link to="/crm/delays" className="flex items-center gap-1.5 px-3 py-2 text-sm rounded-lg border border-red-200 bg-red-50 text-red-700 hover:bg-red-100">
              <AlarmClock size={14} /> Gecikme yönetimi
            </Link>}
          </div>
        )}
      </div>

      {/* Durum sekmeleri */}
      {/* DİKKAT: dış kutuda overflow YOK — tarih/kanal açılır menüleri kırpılıyordu */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100">
        <div className="overflow-x-auto border-b border-gray-100 rounded-t-xl">
        <div className="flex min-w-max">
          {TABS.map((tb) => {
            const count = tabCounts[tb.key]
            const active = tab === tb.key
            const isDelayed = tb.key === 'delayed'
            return (
              <button
                key={tb.key}
                onClick={() => setTab(tb.key)}
                className={clsx(
                  'px-4 py-3 text-sm whitespace-nowrap border-b-2 transition-colors flex items-center gap-1.5',
                  active
                    ? (isDelayed ? 'border-red-500 text-red-600 font-semibold' : 'border-orange-500 text-orange-600 font-semibold')
                    : 'border-transparent text-gray-600 hover:text-gray-900'
                )}
              >
                {isDelayed && <AlarmClock size={14} />}
                {tb.label}
                {count !== undefined && (
                  <span className={clsx(
                    'text-xs px-1.5 py-0.5 rounded-full',
                    isDelayed && count > 0 ? 'bg-red-500 text-white' : active ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-500'
                  )}>
                    {count.toLocaleString(locale)}
                  </span>
                )}
              </button>
            )
          })}
        </div>
        </div>

        {/* Filtre satırı */}
        <div className="p-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2">
          <div className="relative lg:col-span-2">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={15} />
            <input
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Sipariş no, alıcı adı, e-posta, kargo takip no"
              className="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-400"
            />
          </div>
          <div className="relative lg:col-span-2">
            <Barcode className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={15} />
            <input
              value={productInput}
              onChange={(e) => setProductInput(e.target.value)}
              placeholder="Ürün adı, stok kodu, barkod veya marka"
              className="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-400"
            />
          </div>

          {/* Tarih */}
          <div ref={dateDropdownRef} className="relative">
            <button
              type="button"
              onClick={() => { setShowDateDropdown(!showDateDropdown); setShowSourceDropdown(false) }}
              className={clsx(
                'w-full flex items-center justify-between text-sm border rounded-lg px-3 py-2 text-left',
                hasDateFilter && !dateIgnored ? 'border-orange-300 bg-orange-50' : 'border-gray-200 bg-white'
              )}
              title={dateIgnored ? 'Sipariş/takip no araması tüm tarihlerde yapılır' : undefined}
            >
              <span className="flex items-center gap-1.5 truncate">
                <Calendar size={14} className="text-gray-400 shrink-0" />
                <span className={clsx(dateIgnored ? 'text-gray-400 line-through' : 'text-gray-800')}>{dateButtonLabel}</span>
              </span>
              <ChevronDown size={14} className="text-gray-400" />
            </button>
            {showDateDropdown && (
              <div className="absolute top-full right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-20 py-1 w-56">
                {datePresets.map((preset) => {
                  const isActive = filterDateFrom === preset.from && filterDateTo === preset.to
                  return (
                    <button
                      key={preset.key}
                      onClick={() => { setFilterDateFrom(preset.from); setFilterDateTo(preset.to); setShowDateDropdown(false) }}
                      className={clsx('w-full px-3 py-2 text-xs text-left hover:bg-gray-50', isActive ? 'font-semibold text-orange-700 bg-orange-50' : 'text-gray-600')}
                    >
                      {preset.label}
                    </button>
                  )
                })}
                <div className="border-t border-gray-100 my-1" />
                <div className="px-3 py-2 space-y-1.5">
                  <p className="text-[11px] text-gray-400 font-medium">{t('crm.orders.customRange')}</p>
                  <input type="date" value={filterDateFrom} onChange={(e) => setFilterDateFrom(e.target.value)} className="w-full px-2 py-1.5 bg-gray-50 border border-gray-200 rounded text-xs" />
                  <input type="date" value={filterDateTo} onChange={(e) => setFilterDateTo(e.target.value)} className="w-full px-2 py-1.5 bg-gray-50 border border-gray-200 rounded text-xs" />
                </div>
              </div>
            )}
          </div>

          {/* Kanal */}
          <div ref={sourceDropdownRef} className="relative">
            <button
              type="button"
              onClick={() => { setShowSourceDropdown(!showSourceDropdown); setShowDateDropdown(false) }}
              className={clsx('w-full flex items-center justify-between text-sm border rounded-lg px-3 py-2 text-left', filterSources.length > 0 ? 'border-orange-300 bg-orange-50' : 'border-gray-200 bg-white')}
            >
              <span className="truncate">
                {filterSources.length === 0 ? t('crm.orders.allChannels') : t('crm.orders.channelsSelected', { count: filterSources.length })}
              </span>
              <ChevronDown size={14} className="text-gray-400 flex-shrink-0 ml-1" />
            </button>
            {showSourceDropdown && (
              <div className="absolute z-20 mt-1 w-56 right-0 bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto">
                {sources.map((src) => (
                  <label key={src.order_source} onClick={() => toggleSource(src.order_source)} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm">
                    <div className={clsx('w-4 h-4 rounded border flex items-center justify-center flex-shrink-0', filterSources.includes(src.order_source) ? 'bg-orange-500 border-orange-500' : 'border-gray-300')}>
                      {filterSources.includes(src.order_source) && <Check size={12} className="text-white" />}
                    </div>
                    <span className="truncate">{sourceLabel(src.order_source)}</span>
                    <span className="text-gray-400 text-xs ml-auto">({src.order_count.toLocaleString(locale)})</span>
                  </label>
                ))}
                <div className="border-t border-gray-100 flex">
                  <button onClick={() => setFilterSources([])} className="flex-1 px-3 py-2 text-xs text-orange-600 hover:bg-orange-50">Tüm kanallar</button>
                  <button onClick={() => setFilterSources(defaultSources)} className="flex-1 px-3 py-2 text-xs text-gray-500 hover:bg-gray-50">Varsayılan</button>
                </div>
              </div>
            )}
          </div>

          <select value={filterPayment} onChange={(e) => setFilterPayment(e.target.value)} className="text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
            <option value="">{t('crm.orders.allPayments')}</option>
            <option value="1">{t('crm.orders.paid')}</option>
            <option value="0">{t('crm.orders.cartAbandoned')}</option>
          </select>
          <select value={filterMethod} onChange={(e) => setFilterMethod(e.target.value)} className={clsx('text-sm border rounded-lg px-3 py-2', filterMethod ? 'border-orange-300 bg-orange-50' : 'border-gray-200 bg-white')}>
            <option value="">Tüm ödeme yöntemleri</option>
            {PAYMENT_FILTER_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
          <select value={filterCargo} onChange={(e) => setFilterCargo(e.target.value)} className="text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
            <option value="">Tüm kargo firmaları</option>
            {cargoCompanies.map((c) => <option key={c.cargo_company} value={c.cargo_company}>{c.cargo_company}</option>)}
          </select>
          <select value={sort} onChange={(e) => setSort(e.target.value)} className="text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
            <option value="order_date:desc">Yeniden eskiye</option>
            <option value="order_date:asc">Eskiden yeniye</option>
            <option value="total_amount:desc">Tutar (yüksek → düşük)</option>
            <option value="total_amount:asc">Tutar (düşük → yüksek)</option>
          </select>
          <div className="flex items-center gap-2 lg:col-span-3">
            {hasActiveFilters && (
              <button onClick={clearFilters} className="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800">
                <X size={14} /> Filtreleri temizle
              </button>
            )}
            {dateIgnored && <span className="text-xs text-gray-400">Sipariş/takip no araması tüm tarihlerde yapılıyor</span>}
          </div>
        </div>
      </div>

      {/* Sonuç sayısı + canlı akış durumu */}
      <div className="flex items-center justify-between gap-2 text-sm text-gray-500 px-1">
        <div>
          {loading ? t('common.loading') : <><b className="text-gray-900">{total.toLocaleString(locale)}</b> sipariş</>}
          {!loading && product && stats?.product_qty != null && (
            <span className="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-orange-50 text-orange-700 text-xs font-medium">
              <Barcode size={11} /> <b>{stats.product_qty.toLocaleString(locale)}</b> adet · {product}
            </span>
          )}
        </div>
        <SyncInfo syncedAt={stats?.synced_at} onRefresh={() => { fetchOrders(); fetchMeta() }} />
      </div>

      {pendingNew.length > 0 && (
        <button
          onClick={showPendingNew}
          className="sticky top-2 z-10 mx-auto flex items-center gap-2 px-4 py-2 rounded-full bg-orange-600 text-white text-sm font-semibold shadow-lg hover:bg-orange-700"
        >
          <ChevronUp size={15} /> {pendingNew.length} yeni sipariş geldi — göster
        </button>
      )}

      {/* Sipariş kartları */}
      {!loading && orders.length === 0 && (
        <div className="bg-white rounded-xl border border-gray-100 p-12 text-center text-gray-500">
          <ShoppingBag className="mx-auto mb-3 text-gray-300" size={40} />
          <p className="text-lg font-medium text-gray-600">{t('crm.orders.noOrders')}</p>
          <p className="text-sm mt-1">{t('crm.orders.tryChangingFilters')}</p>
        </div>
      )}

      <div className="space-y-3">
        {!loading && orders.map((order) => (
          <OrderCard
            key={order.id}
            order={order}
            copied={copied === order.order_no}
            onCopy={copy}
            onOpen={openDetail}
            onImage={openImage}
            money={money}
            formatDate={formatDate}
            statusLabel={getStatusLabel}
            paidLabel={t('crm.orders.paid')}
            unpaidLabel={t('crm.orders.cartAbandoned')}
          />
        ))}
      </div>

      {lightbox && <ImageLightbox items={lightbox.items} index={lightbox.index} onIndex={(i) => setLightbox((lb) => (lb ? { ...lb, index: i } : lb))} onClose={() => setLightbox(null)} />}

      {loadingMore && <div className="py-4 text-center text-sm text-gray-500">{t('crm.orders.loadingMore')}</div>}
      <div ref={sentinelRef} />
    </div>
  )
}

function statusColor(status: number) {
  switch (status) {
    case 0: return 'bg-gray-100 text-gray-600'
    case 4: return 'bg-yellow-100 text-yellow-700'
    case 5: return 'bg-blue-100 text-blue-700'
    case 6: return 'bg-purple-100 text-purple-700'
    case 7: return 'bg-green-100 text-green-700'
    case 8: return 'bg-red-100 text-red-700'
    case 11: case 12: return 'bg-orange-100 text-orange-700'
    case 13: return 'bg-red-100 text-red-600'
    case 20: return 'bg-indigo-100 text-indigo-700'
    case 21: return 'bg-teal-100 text-teal-700'
    default: return 'bg-gray-100 text-gray-600'
  }
}

/* Trendyol'daki "kargoya teslim için kalan süre" karşılığı */
function TimelineBadge({ tl }: { tl: Timeline }) {
  const due = new Date(tl.due_date + 'T00:00:00').toLocaleDateString('tr-TR', { day: '2-digit', month: 'short', weekday: 'short' })
  if (tl.delay_days > 0) {
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-600 text-white font-semibold" title={`Söz verilen: ${due}`}>
        <AlarmClock size={11} /> {tl.stage === 'prep' ? 'Kargoya verilmedi' : 'Teslim edilmedi'} · {tl.delay_days} gün gecikti
      </span>
    )
  }
  if (tl.delay_days === 0) {
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-medium">
        <AlarmClock size={11} /> {tl.stage === 'prep' ? 'Bugün kargoya verilmeli' : 'Bugün teslim bekleniyor'}
      </span>
    )
  }
  return (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-50 text-green-700">
      <AlarmClock size={11} /> {tl.stage === 'prep' ? 'Kargoya son gün' : 'Beklenen teslim'}: {due}
    </span>
  )
}

function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="text-right hidden md:block">
      <div className="text-[11px] text-gray-400">{label}</div>
      <div className="font-semibold text-gray-900">{value}</div>
    </div>
  )
}

const fmtCache = new Map<string, Intl.NumberFormat>()
function moneyFormatter(locale: string) {
  let f = fmtCache.get(locale)
  if (!f) {
    f = new Intl.NumberFormat(locale, { style: 'currency', currency: 'TRY' })
    fmtCache.set(locale, f)
  }
  return f
}

/* Ticimax'tan son çekim zamanı — sync her dakika; 5 dk'yı geçerse uyarı rengi */
function SyncInfo({ syncedAt, onRefresh }: { syncedAt?: string | null; onRefresh: () => void }) {
  if (!syncedAt) return null
  const sec = Math.max(0, Math.round((Date.now() - new Date(syncedAt).getTime()) / 1000))
  const label = sec < 60 ? 'az önce' : sec < 3600 ? `${Math.floor(sec / 60)} dk önce` : `${Math.floor(sec / 3600)} sa önce`
  const stale = sec > 300
  return (
    <button onClick={onRefresh} className={clsx('flex items-center gap-1.5 text-xs', stale ? 'text-red-600 font-semibold' : 'text-gray-400 hover:text-gray-700')} title="Listeyi yenile">
      <span className={clsx('w-1.5 h-1.5 rounded-full', stale ? 'bg-red-500' : 'bg-green-500 animate-pulse')} />
      Ticimax'tan güncellendi: {label}
      <RefreshCw size={11} />
    </button>
  )
}

interface OrderCardProps {
  order: OrderRow
  copied: boolean
  onCopy: (text: string) => void
  onOpen: (id: number) => void
  onImage: (items: LightboxItem[], index: number) => void
  money: (v: number | string) => string
  formatDate: (d: string | null) => string
  statusLabel: (status: number, fallback?: string | null) => string
  paidLabel: string
  unpaidLabel: string
}

/*
 * Kart memo'lu: kopyala/yoklama gibi durum değişimlerinde yalnız ilgili kart yeniden çizilir.
 * content-visibility: ekran dışındaki kartların çizimi tarayıcıya ertelenir (uzun listede kasma yok).
 */
const OrderCard = memo(function OrderCard({ order, copied, onCopy, onOpen, onImage, money, formatDate, statusLabel, paidLabel, unpaidLabel }: OrderCardProps) {
  const [expanded, setExpanded] = useState(false)
  const items = (order.items || []) as OrderItemRow[]
  const withImage = items.filter((it) => it.image_url)
  const collapsible = items.length > COLLAPSE_OVER
  const visible = collapsible && !expanded ? items.slice(0, COLLAPSED_SHOW) : items
  const hiddenCount = items.length - visible.length
  const totalQty = items.reduce((n, it) => n + (Number(it.quantity) || 0), 0)

  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden" style={{ contentVisibility: 'auto', containIntrinsicSize: 'auto 220px' }}>
      {/* Kart başlığı */}
      <div className="flex items-center gap-x-4 gap-y-1 flex-wrap px-4 py-2.5 bg-gray-50 border-b border-gray-100 text-xs">
        <button onClick={() => onCopy(order.order_no || '')} className="flex items-center gap-1 font-semibold text-gray-900 text-sm hover:text-orange-600" title="Kopyala">
          #{order.order_no || '-'}
          {copied ? <Check size={12} className="text-green-600" /> : <Copy size={12} className="text-gray-400" />}
        </button>
        <span className="text-gray-500">{formatDate(order.order_date)}</span>
        {order.channel && (
          <span className={clsx('px-1.5 py-0.5 text-[10px] font-medium rounded', getChannelBadgeClasses(order.channel.type))}>
            {getChannelLabel(order.channel.type)}
          </span>
        )}
        {order.order_source && <span className="px-1.5 py-0.5 rounded bg-white border border-gray-200 text-gray-600">{sourceLabel(order.order_source)}</span>}
        {order.payment_status === 1 && <PaymentMethodBadge method={order.payment_method} />}
        {order.payment_status === 1
          ? <span className="inline-flex items-center gap-1 text-green-700"><CreditCard size={11} /> {paidLabel}</span>
          : <span className="inline-flex items-center gap-1 text-gray-400"><Ban size={11} /> {unpaidLabel}</span>}
        {items.length > 1 && <span className="text-gray-500">{items.length} kalem · {totalQty} adet</span>}
        <span className="ml-auto flex items-center gap-2">
          {order.timeline && <TimelineBadge tl={order.timeline} />}
          <span className={clsx('px-2 py-0.5 font-medium rounded-full', statusColor(order.status))}>
            {statusLabel(order.status, order.status_label)}
          </span>
        </span>
      </div>

      {/* Kart gövdesi */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 p-4">
        {/* Ürünler */}
        <div className="lg:col-span-7 space-y-2">
          {items.length === 0 && <div className="text-xs text-gray-400">Ürün bilgisi yok ({order.items_count} kalem)</div>}
          {visible.map((it) => (
            <ItemRow key={it.id} it={it} orderStatus={order.status} money={money}
              onImage={() => onImage(withImage, Math.max(0, withImage.indexOf(it)))} />
          ))}
          {collapsible && (
            <button
              onClick={() => setExpanded((v) => !v)}
              className="w-full flex items-center justify-center gap-1.5 py-2 text-sm font-semibold rounded-lg border border-dashed border-orange-300 text-orange-700 bg-orange-50/50 hover:bg-orange-50"
            >
              {expanded
                ? <><ChevronUp size={14} /> Ürünleri gizle</>
                : <><ChevronDown size={14} /> +{hiddenCount} ürün daha göster</>}
            </button>
          )}
        </div>

        {/* Alıcı */}
        <div className="lg:col-span-2 text-sm">
          <div className="text-xs text-gray-400 mb-1">Alıcı</div>
          <div className="text-gray-900 font-medium truncate">{order.customer_name || '-'}</div>
          {order.phone && <div className="text-xs text-gray-500 flex items-center gap-1 mt-0.5"><Phone size={11} /> {order.phone}</div>}
          {order.ship_city && <div className="text-xs text-gray-500 flex items-center gap-1 mt-0.5"><MapPin size={11} /> {order.ship_district ? `${order.ship_district} / ` : ''}{order.ship_city}</div>}
          {order.customer_email && <div className="text-[11px] text-gray-400 truncate mt-0.5">{order.customer_email}</div>}
        </div>

        {/* Kargo + tutar + aksiyon */}
        <div className="lg:col-span-3 text-sm flex flex-col gap-2">
          <div>
            <div className="text-xs text-gray-400 mb-1">Kargo</div>
            <div className="text-gray-800 flex items-center gap-1"><Truck size={13} className="text-gray-400" /> {order.cargo_company || '-'}</div>
            {order.cargo_tracking_no && (
              order.cargo_tracking_url
                ? <a href={order.cargo_tracking_url} target="_blank" rel="noreferrer" className="text-xs text-orange-600 hover:underline flex items-center gap-1">{order.cargo_tracking_no} <ExternalLink size={10} /></a>
                : <div className="text-xs text-gray-500">{order.cargo_tracking_no}</div>
            )}
          </div>
          <div className="flex items-end justify-between mt-auto">
            <PriceSummary pricing={order.pricing} total={order.total_amount} money={money} />
            <button onClick={() => onOpen(order.id)} className="px-3 py-1.5 text-xs font-semibold rounded-lg border border-orange-300 text-orange-700 hover:bg-orange-50">
              Detay
            </button>
          </div>
        </div>
      </div>
    </div>
  )
})

/*
 * Toplam + minimal kırılım: altında "−160 indirim · +100 kargo" gibi kısa çipler; (i) üzerine
 * gelince/tıklayınca hangi kupon/kampanya uygulandığı satır satır açılır. İpucu portal ile body'ye
 * çizilir (kart content-visibility/overflow kırpmasın).
 */
function PriceSummary({ pricing, total, money }: { pricing?: Pricing | null; total: number | string; money: (v: number | string) => string }) {
  const [open, setOpen] = useState(false)
  const anchorRef = useRef<HTMLDivElement>(null)
  const [pos, setPos] = useState<{ top: number; left: number; up: boolean } | null>(null)
  const p = pricing
  const fmt = (v: number) => money(Math.abs(v)).replace(/\s?₺|TRY\s?/g, '').trim()

  useLayoutEffect(() => {
    if (!open || !anchorRef.current) { setPos(null); return }
    const r = anchorRef.current.getBoundingClientRect()
    const up = window.innerHeight - r.bottom < 220
    setPos({ top: up ? r.top - 6 : r.bottom + 6, left: Math.max(8, Math.min(r.left, window.innerWidth - 280)), up })
    const close = () => setOpen(false)
    window.addEventListener('scroll', close, true)
    return () => window.removeEventListener('scroll', close, true)
  }, [open])

  const chips: { key: string; cls: string; text: string }[] = []
  // Ürün kampanyası (1 Alana 1 Bedava vb.) ayrı çip; kalan indirimler (sepet %, kupon, puan) tek çip
  const productCampaigns = p ? p.lines.filter((l) => l.type === 'campaign_product' && l.amount < 0) : []
  productCampaigns.forEach((l, i) => {
    const m = l.label.match(/(\d+)\s*alana\s*(\d+)\s*bedava/i)
    chips.push({ key: `pc${i}`, cls: 'text-purple-700 bg-purple-50', text: `−${fmt(l.amount)} ${m ? `${m[1]}+${m[2]}` : l.label}` })
  })
  const restDiscount = p ? p.discount + productCampaigns.reduce((s, l) => s + l.amount, 0) : 0
  if (p && restDiscount >= 0.01) chips.push({ key: 'd', cls: 'text-green-700 bg-green-50', text: `−${fmt(restDiscount)} indirim` })
  if (p && p.shipping > 0) chips.push({ key: 's', cls: 'text-gray-600 bg-gray-100', text: `+${fmt(p.shipping)} kargo` })
  if (p && p.surcharge > 0) chips.push({ key: 'v', cls: 'text-amber-700 bg-amber-50', text: `+${fmt(p.surcharge)} ${p.lines.some((l) => l.type === 'cod_fee') ? 'kapıda ödeme' : 'vade'}` })

  return (
    <div ref={anchorRef} className="min-w-0" onMouseEnter={() => p && setOpen(true)} onMouseLeave={() => setOpen(false)}>
      <div className="text-xs text-gray-400 flex items-center gap-1">
        Toplam
        {p && (
          <button type="button" onClick={() => setOpen((v) => !v)} className={clsx('rounded-full', open ? 'text-orange-600' : 'text-gray-400 hover:text-orange-600')} title="Fiyat kırılımı">
            <Info size={12} />
          </button>
        )}
      </div>
      <div className="text-lg font-bold text-gray-900 leading-tight">{money(total)}</div>
      {chips.length > 0 && (
        <div className="flex flex-wrap gap-1 mt-0.5">
          {chips.map((c) => <span key={c.key} className={clsx('text-[10px] px-1.5 py-px rounded font-medium whitespace-nowrap', c.cls)}>{c.text}</span>)}
        </div>
      )}
      {open && p && pos && createPortal(
        <div
          className="fixed z-50 w-[270px] bg-gray-900 text-white rounded-lg shadow-xl p-3 text-xs space-y-1.5"
          style={{ top: pos.top, left: pos.left, transform: pos.up ? 'translateY(-100%)' : undefined }}
          onMouseEnter={() => setOpen(true)}
          onMouseLeave={() => setOpen(false)}
        >
          {p.lines.map((l, i) => {
            const sign = l.type === 'products' ? '' : l.amount < 0 ? '−' : l.amount > 0 ? '+' : ''
            return (
              <div key={i} className="flex items-start justify-between gap-3">
                <span className="min-w-0 text-gray-300">
                  <span className="flex items-center gap-1 flex-wrap">
                    {l.label}
                    {l.code && <span className="font-mono text-[10px] bg-white/10 px-1 rounded">{l.code}</span>}
                    {l.detail && l.code && <span className="text-[10px] text-gray-400">{l.detail}</span>}
                  </span>
                  {l.detail && !l.code && <span className={clsx('block text-[10px] leading-tight', l.warn ? 'text-amber-300' : 'text-gray-400')}>{l.detail}</span>}
                </span>
                <span className={clsx('shrink-0 font-medium tabular-nums', l.amount < 0 ? 'text-green-300' : l.type === 'shipping_free' ? 'text-green-300' : sign === '+' ? 'text-amber-300' : 'text-white')}>
                  {l.type === 'shipping_free' ? '0' : `${sign}${fmt(l.amount)}`}
                </span>
              </div>
            )
          })}
          <div className="border-t border-white/15 pt-1.5 flex justify-between font-semibold">
            <span>Toplam</span><span className="tabular-nums">{money(total)}</span>
          </div>
        </div>,
        document.body,
      )}
    </div>
  )
}

function ItemRow({ it, orderStatus, money, onImage }: { it: OrderItemRow; orderStatus: number; money: (v: number | string) => string; onImage: () => void }) {
  return (
    <div className="flex items-center gap-3">
      <ProductThumb url={it.image_url} onClick={onImage} className="w-20 h-[120px]" />
      <div className="min-w-0 flex-1">
        <div className="text-sm text-gray-900 line-clamp-2">{it.product_name}</div>
        <div className="text-xs text-gray-500 mt-0.5 flex flex-wrap gap-x-3">
          {it.variant_info && <span>{it.variant_info}</span>}
          {it.sku && <span>Stok kodu: {it.sku.split('|').slice(1).join(' · ') || it.sku}</span>}
          {it.barcode && <span>Barkod: {it.barcode}</span>}
        </div>
        {it.status_label && it.status !== orderStatus && (
          <span className="inline-block mt-0.5 text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700">{it.status_label}</span>
        )}
      </div>
      <div className="text-right shrink-0 w-28">
        <div className="text-sm font-semibold text-gray-900">{it.quantity} adet</div>
        <div className="text-xs text-gray-500">{money(it.unit_price)} / adet</div>
      </div>
    </div>
  )
}
