import { useEffect, useState, useCallback, useRef, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import type { Conversation, PaginatedResponse, Channel, ViewCounts } from '@/types'
import {
  MessageSquare, Filter, Search, AlertTriangle, Calendar,
  MessageCircle, Instagram, Facebook, Globe, ShoppingBag, Headphones, Mail,
  ChevronDown, Check, X, ArrowLeft, ArrowUpDown, Plus, Smartphone, UserRound,
} from 'lucide-react'
import clsx from 'clsx'
import { useAuth } from '@/contexts/AuthContext'
import ConversationDetail from '@/components/ConversationDetail'
import NewConversationModal from '@/components/NewConversationModal'
import { getChannelBadgeClasses, getChannelLabel, getChannelFilterLabel, formatRelativeTime, customerPhone } from '@/lib/channelUtils'

const channelIcons: Record<string, typeof Facebook> = {
  'facebook-message': Facebook,
  'facebook-comment': Facebook,
  'instagram-message': Instagram,
  'instagram-comment': Instagram,
  'whatsapp': MessageCircle,
  'trendyol-product': ShoppingBag,
  'trendyol-order': ShoppingBag,
  'hepsiburada-product': ShoppingBag,
  'hepsiburada-order': ShoppingBag,
  'ticimax-ticket': Headphones,
  'email': Mail,
  'sms': Smartphone,
}

type ViewTab = 'all' | 'waiting' | 'answered' | 'urgent'

type AgentOption = { id: number; name: string; status?: string }

const fmtDate = (d: Date) => d.toISOString().split('T')[0]

function getDatePresets() {
  const now = new Date()
  const today = fmtDate(now)
  const yesterday = fmtDate(new Date(now.getTime() - 86_400_000))
  const d7 = fmtDate(new Date(now.getTime() - 7 * 86_400_000))
  const d30 = fmtDate(new Date(now.getTime() - 30 * 86_400_000))
  const monthStart = fmtDate(new Date(now.getFullYear(), now.getMonth(), 1))
  return { today, yesterday, d7, d30, monthStart }
}

export default function MessagesPage() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const isAdmin = !!(user?.is_super_admin || user?.role === 'admin')
  const [searchParams, setSearchParams] = useSearchParams()
  const [conversations, setConversations] = useState<Conversation[]>([])
  const [channels, setChannels] = useState<Channel[]>([])
  const [agents, setAgents] = useState<AgentOption[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [total, setTotal] = useState(0)
  const pageRef = useRef(1)
  const lastPageRef = useRef(1)
  const [selectedId, setSelectedId] = useState<number | null>(() => {
    const convParam = searchParams.get('conversation')
    return convParam ? parseInt(convParam, 10) : null
  })
  const [search, setSearch] = useState(searchParams.get('search') || '')
  const [counts, setCounts] = useState<ViewCounts>({ total: 0, unread: 0, waiting: 0, answered: 0, urgent: 0 })
  const [channelDropdownOpen, setChannelDropdownOpen] = useState(false)
  const [showNewConversation, setShowNewConversation] = useState(false)
  const [dateDropdownOpen, setDateDropdownOpen] = useState(false)
  const [sortDropdownOpen, setSortDropdownOpen] = useState(false)
  const [agentDropdownOpen, setAgentDropdownOpen] = useState(false)
  const channelDropdownRef = useRef<HTMLDivElement>(null)
  const dateDropdownRef = useRef<HTMLDivElement>(null)
  const sortDropdownRef = useRef<HTMLDivElement>(null)
  const agentDropdownRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)
  const loadingMoreRef = useRef(false)
  const fetchMoreRef = useRef<() => void>(() => {})

  // Varsayılan sekme "Bekleyen"; başka sayfadan ?conversation= ile gelinmişse
  // seçili konuşma listede görünsün diye "Tümü" açılır.
  const view = (searchParams.get('view') as ViewTab) || (searchParams.get('conversation') ? 'all' : 'waiting')
  const channelIdsParam = searchParams.get('channel_ids') || ''
  const channelIds = channelIdsParam ? channelIdsParam.split(',').filter(Boolean) : []
  const dateFrom = searchParams.get('date_from') || ''
  const dateTo = searchParams.get('date_to') || ''
  const sortParam = searchParams.get('sort') || 'last_message_at'
  const directionParam = searchParams.get('direction') || 'desc'
  // Personel filtresi yalnız yöneticide çalışır (backend de ayrıca denetler)
  const lastAgentId = isAdmin ? (searchParams.get('last_agent_id') || '') : ''

  const tabs: { key: ViewTab; label: string; countKey: keyof ViewCounts }[] = useMemo(() => [
    { key: 'all', label: t('messages.all'), countKey: 'total' },
    { key: 'waiting', label: t('messages.waiting'), countKey: 'waiting' },
    { key: 'answered', label: t('messages.answered'), countKey: 'answered' },
    { key: 'urgent', label: t('messages.urgent'), countKey: 'urgent' },
  ], [t])

  const datePresets = useMemo(() => {
    const { today, yesterday, d7, d30, monthStart } = getDatePresets()
    return [
      { label: t('common.today'), from: today, to: today },
      { label: t('common.yesterday'), from: yesterday, to: yesterday },
      { label: t('common.last7Days'), from: d7, to: today },
      { label: t('common.last30Days'), from: d30, to: today },
      { label: t('common.thisMonth'), from: monthStart, to: today },
    ]
  }, [t])

  useEffect(() => {
    api.get('/channels').then((res) => setChannels(res.data))
  }, [])

  useEffect(() => {
    if (!isAdmin) return
    api.get<AgentOption[]>('/conversations/agents').then((res) => setAgents(res.data)).catch(() => {})
  }, [isAdmin])

  // Close dropdowns on outside click
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (channelDropdownRef.current && !channelDropdownRef.current.contains(e.target as Node)) {
        setChannelDropdownOpen(false)
      }
      if (dateDropdownRef.current && !dateDropdownRef.current.contains(e.target as Node)) {
        setDateDropdownOpen(false)
      }
      if (sortDropdownRef.current && !sortDropdownRef.current.contains(e.target as Node)) {
        setSortDropdownOpen(false)
      }
      if (agentDropdownRef.current && !agentDropdownRef.current.contains(e.target as Node)) {
        setAgentDropdownOpen(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  const fetchCounts = useCallback(() => {
    const params: Record<string, string> = { type: 'message' }
    if (channelIdsParam) params.channel_ids = channelIdsParam
    if (dateFrom) params.date_from = dateFrom
    if (dateTo) params.date_to = dateTo
    if (lastAgentId) params.last_agent_id = lastAgentId
    api.get<ViewCounts>('/conversations/counts', { params }).then((res) => setCounts(res.data))
  }, [channelIdsParam, dateFrom, dateTo, lastAgentId])

  const penaltyAgentId = searchParams.get('penalty_agent_id') || ''

  const buildParams = useCallback(() => {
    const params: Record<string, string> = { type: 'message' }
    if (view && view !== 'all') params.view = view
    if (channelIdsParam) params.channel_ids = channelIdsParam
    if (search) params.search = search
    if (dateFrom) params.date_from = dateFrom
    if (dateTo) params.date_to = dateTo
    if (sortParam) params.sort = sortParam
    if (directionParam) params.direction = directionParam
    if (penaltyAgentId) params.penalty_agent_id = penaltyAgentId
    if (lastAgentId) params.last_agent_id = lastAgentId
    return params
  }, [view, channelIdsParam, search, dateFrom, dateTo, sortParam, directionParam, penaltyAgentId, lastAgentId])

  const fetchConversations = useCallback(() => {
    setLoading(true)
    pageRef.current = 1
    loadingMoreRef.current = false
    const params = buildParams()

    api.get<PaginatedResponse<Conversation>>('/conversations', { params }).then((res) => {
      setConversations(res.data.data)
      setTotal(res.data.total)
      lastPageRef.current = res.data.last_page
      pageRef.current = 1
      setLoading(false)
      // Auto-select first conversation when penalty filter is active
      if (penaltyAgentId && res.data.data.length > 0 && !selectedId) {
        setSelectedId(res.data.data[0].id)
      }
    })
  }, [buildParams])

  const fetchMore = useCallback(() => {
    if (loadingMoreRef.current || pageRef.current >= lastPageRef.current) return
    loadingMoreRef.current = true
    setLoadingMore(true)
    const nextPage = pageRef.current + 1
    const params = { ...buildParams(), page: String(nextPage) }

    api.get<PaginatedResponse<Conversation>>('/conversations', { params }).then((res) => {
      setConversations((prev) => [...prev, ...res.data.data])
      pageRef.current = res.data.current_page
      lastPageRef.current = res.data.last_page
    }).finally(() => {
      loadingMoreRef.current = false
      setLoadingMore(false)
    })
  }, [buildParams])

  // Keep ref in sync so observer always calls latest version
  fetchMoreRef.current = fetchMore

  useEffect(() => {
    fetchConversations()
    fetchCounts()
  }, [fetchConversations, fetchCounts])

  // Sessiz liste tazeleme: spinner göstermez, seçili konuşmayı ve composer'ı
  // etkilemez. Kullanıcı infinite-scroll ile 2+ sayfa yüklediyse listeyi ezmez
  // (birikmiş sayfalar kaybolur), yalnız sayaçları günceller.
  const refreshSilent = useCallback(() => {
    fetchCounts()
    if (pageRef.current !== 1) return
    api.get<PaginatedResponse<Conversation>>('/conversations', { params: buildParams() }).then((res) => {
      if (pageRef.current !== 1) return
      setConversations(res.data.data)
      setTotal(res.data.total)
      lastPageRef.current = res.data.last_page
    }).catch(() => { /* geçici ağ hatasında sessiz kal */ })
  }, [buildParams, fetchCounts])

  // Yeni konuşma/mesaj sayfa yenilemeden listeye düşsün
  useEffect(() => {
    const timer = setInterval(refreshSilent, 30000)
    return () => clearInterval(timer)
  }, [refreshSilent])

  // Infinite scroll: observe sentinel element
  // Re-attach observer whenever loading finishes (sentinel may re-enter DOM)
  useEffect(() => {
    if (loading) return
    const el = sentinelRef.current
    if (!el) return
    const observer = new IntersectionObserver(
      (entries) => { if (entries[0].isIntersecting) fetchMoreRef.current() },
      { threshold: 0.1 }
    )
    observer.observe(el)
    return () => observer.disconnect()
  }, [loading])

  const setFilter = (key: string, value: string) => {
    const params = new URLSearchParams(searchParams)
    if (value) params.set(key, value)
    else params.delete(key)
    if (key === 'view') params.delete('status')
    setSearchParams(params)
  }

  const setDateRange = (from: string, to: string) => {
    const params = new URLSearchParams(searchParams)
    if (from) params.set('date_from', from)
    else params.delete('date_from')
    if (to) params.set('date_to', to)
    else params.delete('date_to')
    setSearchParams(params)
  }

  const clearAllFilters = () => {
    const params = new URLSearchParams(searchParams)
    params.delete('channel_ids')
    params.delete('date_from')
    params.delete('date_to')
    params.delete('penalty_agent_id')
    params.delete('last_agent_id')
    setSearchParams(params)
  }

  const toggleChannel = (id: string) => {
    const current = new Set(channelIds)
    if (current.has(id)) current.delete(id)
    else current.add(id)
    setFilter('channel_ids', [...current].join(','))
  }

  // Mesaj gönderimi/durum değişimi sonrası: spinner'lı tam yenileme yerine
  // sessiz tazeleme — liste "hop yenilendi" hissi vermez
  const handleUpdate = () => {
    refreshSilent()
  }

  const channelOrder: Record<string, number> = {
    'instagram-message': 1,
    'facebook-message': 2,
    'ticimax-ticket': 3,
    'trendyol-product': 4,
    'trendyol-order': 5,
    'hepsiburada-product': 6,
    'hepsiburada-order': 7,
    'whatsapp': 8,
  }
  const messageChannels = channels
    .filter((ch) => !ch.type.includes('comment') && !ch.type.includes('ecommerce') && ch.is_active)
    .sort((a, b) => (channelOrder[a.type] ?? 99) - (channelOrder[b.type] ?? 99))

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  const channelButtonLabel = channelIds.length === 0
    ? t('common.allChannels')
    : channelIds.length === 1
      ? getChannelFilterLabel(messageChannels.find((ch) => String(ch.id) === channelIds[0])?.type ?? '')
      : t('common.channelsSelected', { count: channelIds.length })

  const selectedAgent = lastAgentId ? agents.find((a) => String(a.id) === lastAgentId) : undefined
  const agentLabel = selectedAgent?.name ?? (lastAgentId ? `#${lastAgentId}` : '')

  const activePreset = datePresets.find((p) => p.from === dateFrom && p.to === dateTo)
  const hasDateFilter = !!(dateFrom || dateTo)

  const dateButtonLabel = !hasDateFilter
    ? t('common.selectDate')
    : activePreset
      ? activePreset.label
      : dateFrom && dateTo
        ? `${new Date(dateFrom).toLocaleDateString(locale, { day: '2-digit', month: 'short' })} — ${new Date(dateTo).toLocaleDateString(locale, { day: '2-digit', month: 'short' })}`
        : dateFrom
          ? `${new Date(dateFrom).toLocaleDateString(locale, { day: '2-digit', month: 'short' })} —`
          : `— ${new Date(dateTo).toLocaleDateString(locale, { day: '2-digit', month: 'short' })}`

  return (
    <div className="flex flex-col md:flex-row gap-0 md:gap-5 h-[calc(100vh-5rem)] md:h-[calc(100vh-6.5rem)]">
      {/* List */}
      <div className={clsx(
        'w-full md:w-96 flex flex-col bg-white rounded-xl shadow-sm border border-gray-100 shrink-0',
        selectedId !== null && 'hidden md:flex'
      )}>
        {/* Header */}
        <div className="p-4 border-b border-gray-100">
          <div className="flex items-center justify-between mb-3">
            <h1 className="text-lg font-extrabold text-gray-900">{t('messages.title')}</h1>
            <div className="flex items-center gap-2">
              <span className="text-xs font-semibold text-gray-400 bg-[var(--app-bg)] px-2.5 py-1 rounded-full">{total} {t('common.conversations')}</span>
              <button
                onClick={() => setShowNewConversation(true)}
                title={i18n.language === 'tr' ? 'Yeni konuşma başlat' : 'Start new conversation'}
                className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-full transition-colors"
              >
                <Plus size={13} />
                {i18n.language === 'tr' ? 'Yeni' : 'New'}
              </button>
            </div>
          </div>

          {/* Penalty filter banner */}
          {penaltyAgentId && (
            <div className="flex items-center justify-between mb-3 px-3 py-2 bg-red-50 border border-red-200 rounded-lg">
              <div className="flex items-center gap-2 text-sm text-red-700">
                <AlertTriangle size={14} />
                <span className="font-medium">{t('messages.penaltyFilterActive')}</span>
              </div>
              <button
                onClick={() => {
                  const params = new URLSearchParams(searchParams)
                  params.delete('penalty_agent_id')
                  setSearchParams(params)
                }}
                className="text-red-500 hover:text-red-700 p-0.5"
              >
                <X size={16} />
              </button>
            </div>
          )}

          {/* Search */}
          <div className="relative mb-3">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('messages.searchCustomer')}
              className="w-full pl-9 pr-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white transition-colors"
            />
          </div>

          {/* Tabs - compact metric cards */}
          <div className="grid grid-cols-4 gap-1.5 mb-3">
            {tabs.map((tab) => {
              const count = counts[tab.countKey]
              const isActive = view === tab.key
              return (
                <button
                  key={tab.key}
                  onClick={() => setFilter('view', tab.key)}
                  className={clsx(
                    'flex flex-col items-center py-2 rounded-xl transition-colors',
                    isActive
                      ? 'bg-[var(--primary-soft)]'
                      : 'hover:bg-gray-50'
                  )}
                >
                  <span className={clsx('text-base font-extrabold leading-none', isActive ? 'text-[var(--primary-strong)]' : 'text-gray-800')}>
                    {count > 9999 ? `${Math.round(count / 1000)}k` : count.toLocaleString(locale)}
                  </span>
                  <span className={clsx('text-[11px] mt-1', isActive ? 'text-[var(--primary-strong)] font-semibold' : 'text-gray-500')}>
                    {tab.label}
                  </span>
                </button>
              )
            })}
          </div>

          {/* Channel + Date + Sort dropdowns row */}
          <div className="flex gap-2 mb-2">
            {/* Channel multi-select */}
            <div className="relative flex-1" ref={channelDropdownRef}>
              <button
                onClick={() => { setChannelDropdownOpen(!channelDropdownOpen); setDateDropdownOpen(false); setSortDropdownOpen(false); setAgentDropdownOpen(false) }}
                className="w-full flex items-center justify-between px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-full text-xs hover:bg-gray-100 transition-colors"
              >
                <span className={channelIds.length === 0 ? 'text-gray-500' : 'text-gray-800 font-semibold'}>
                  {channelButtonLabel}
                </span>
                <ChevronDown size={14} className={clsx('text-gray-400 transition-transform', channelDropdownOpen && 'rotate-180')} />
              </button>
              {channelDropdownOpen && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-20 py-1 max-h-60 overflow-y-auto">
                  <button
                    onClick={() => { setFilter('channel_ids', ''); setChannelDropdownOpen(false) }}
                    className="w-full flex items-center gap-2.5 px-3 py-2 text-xs hover:bg-gray-50 text-left"
                  >
                    <div className={clsx(
                      'w-4 h-4 rounded border flex items-center justify-center shrink-0 transition-colors',
                      channelIds.length === 0 ? 'bg-indigo-600 border-indigo-600' : 'border-gray-300'
                    )}>
                      {channelIds.length === 0 && <Check size={10} className="text-white" />}
                    </div>
                    <Globe size={14} className={clsx('shrink-0', channelIds.length === 0 ? 'text-gray-700' : 'text-gray-400')} />
                    <span className={channelIds.length === 0 ? 'font-medium text-gray-800' : 'text-gray-600'}>
                      {t('common.allChannels')}
                    </span>
                  </button>
                  <div className="border-t border-gray-100 my-1" />
                  {messageChannels.map((ch) => {
                    const isSelected = channelIds.includes(String(ch.id))
                    const ChIcon = channelIcons[ch.type] ?? Globe
                    return (
                      <button
                        key={ch.id}
                        onClick={() => toggleChannel(String(ch.id))}
                        className="w-full flex items-center gap-2.5 px-3 py-2 text-xs hover:bg-gray-50 text-left"
                      >
                        <div className={clsx(
                          'w-4 h-4 rounded border flex items-center justify-center shrink-0 transition-colors',
                          isSelected ? 'bg-indigo-600 border-indigo-600' : 'border-gray-300'
                        )}>
                          {isSelected && <Check size={10} className="text-white" />}
                        </div>
                        <ChIcon size={14} className={clsx('shrink-0', isSelected ? 'text-gray-700' : 'text-gray-400')} />
                        <span className={isSelected ? 'font-medium text-gray-800' : 'text-gray-600'}>
                          {getChannelFilterLabel(ch.type)}
                        </span>
                      </button>
                    )
                  })}
                </div>
              )}
            </div>

            {/* Date dropdown */}
            <div className="relative flex-1" ref={dateDropdownRef}>
              <button
                onClick={() => { setDateDropdownOpen(!dateDropdownOpen); setChannelDropdownOpen(false); setSortDropdownOpen(false); setAgentDropdownOpen(false) }}
                className="w-full flex items-center justify-between px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-full text-xs hover:bg-gray-100 transition-colors"
              >
                <span className="flex items-center gap-1.5">
                  <Calendar size={12} className="text-gray-400 shrink-0" />
                  <span className={hasDateFilter ? 'text-gray-800 font-medium' : 'text-gray-500'}>
                    {dateButtonLabel}
                  </span>
                </span>
                <ChevronDown size={14} className={clsx('text-gray-400 transition-transform', dateDropdownOpen && 'rotate-180')} />
              </button>
              {dateDropdownOpen && (
                <div className="absolute top-full right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-20 py-1 w-56">
                  {/* Presets */}
                  {datePresets.map((preset) => {
                    const isActive = dateFrom === preset.from && dateTo === preset.to
                    return (
                      <button
                        key={preset.label}
                        onClick={() => { setDateRange(preset.from, preset.to); setDateDropdownOpen(false) }}
                        className={clsx(
                          'w-full px-3 py-2 text-xs text-left hover:bg-gray-50',
                          isActive ? 'font-medium text-indigo-700 bg-indigo-50' : 'text-gray-600'
                        )}
                      >
                        {preset.label}
                      </button>
                    )
                  })}
                  <div className="border-t border-gray-100 my-1" />
                  {/* Custom range */}
                  <div className="px-3 py-2 space-y-1.5">
                    <p className="text-[11px] text-gray-400 font-medium">{t('common.customRange')}</p>
                    <input
                      type="date"
                      value={dateFrom}
                      onChange={(e) => setDateRange(e.target.value, dateTo)}
                      className="w-full px-2 py-1.5 bg-gray-50 border border-gray-200 rounded text-xs"
                    />
                    <input
                      type="date"
                      value={dateTo}
                      onChange={(e) => setDateRange(dateFrom, e.target.value)}
                      className="w-full px-2 py-1.5 bg-gray-50 border border-gray-200 rounded text-xs"
                    />
                  </div>
                  {hasDateFilter && (
                    <>
                      <div className="border-t border-gray-100 my-1" />
                      <button
                        onClick={() => { setDateRange('', ''); setDateDropdownOpen(false) }}
                        className="w-full px-3 py-1.5 text-xs text-red-500 hover:text-red-700 hover:bg-red-50 text-left font-medium"
                      >
                        {t('common.clearDate')}
                      </button>
                    </>
                  )}
                </div>
              )}
            </div>

            {/* Sort dropdown */}
            <div className="relative" ref={sortDropdownRef}>
              <button
                onClick={() => { setSortDropdownOpen(!sortDropdownOpen); setChannelDropdownOpen(false); setDateDropdownOpen(false); setAgentDropdownOpen(false) }}
                className={clsx(
                  'flex items-center justify-center w-9 h-9 rounded-full border border-transparent transition-colors shrink-0',
                  directionParam === 'asc'
                    ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]'
                    : 'bg-[var(--app-bg)] text-gray-400 hover:text-gray-600 hover:bg-gray-100'
                )}
                title={directionParam === 'asc' ? t('common.oldestFirst') : t('common.newestFirst')}
              >
                <ArrowUpDown size={14} />
              </button>
              {sortDropdownOpen && (
                <div className="absolute top-full right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-20 py-1 w-44">
                  {[
                    { label: t('common.newestFirst'), dir: 'desc' },
                    { label: t('common.oldestFirst'), dir: 'asc' },
                  ].map((opt) => {
                    const isActive = directionParam === opt.dir
                    return (
                      <button
                        key={opt.dir}
                        onClick={() => {
                          const params = new URLSearchParams(searchParams)
                          params.set('direction', opt.dir)
                          if (!params.has('sort')) params.set('sort', 'last_message_at')
                          setSearchParams(params)
                          setSortDropdownOpen(false)
                        }}
                        className={clsx(
                          'w-full px-3 py-2 text-xs text-left hover:bg-gray-50 flex items-center gap-2',
                          isActive ? 'font-medium text-indigo-700 bg-indigo-50' : 'text-gray-600'
                        )}
                      >
                        {isActive && <Check size={12} />}
                        <span className={isActive ? '' : 'ml-5'}>{opt.label}</span>
                      </button>
                    )
                  })}
                </div>
              )}
            </div>
          </div>

          {/* Personel filtresi (yalnız yönetici): konuşmaya en son yazan personele göre */}
          {isAdmin && (
            <div className="relative mb-2" ref={agentDropdownRef}>
              <button
                onClick={() => { setAgentDropdownOpen(!agentDropdownOpen); setChannelDropdownOpen(false); setDateDropdownOpen(false); setSortDropdownOpen(false) }}
                title={t('messages.agentFilterHint')}
                className="w-full flex items-center justify-between px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-full text-xs hover:bg-gray-100 transition-colors"
              >
                <span className="flex items-center gap-1.5 min-w-0">
                  <UserRound size={12} className="text-gray-400 shrink-0" />
                  <span className={clsx('truncate', lastAgentId ? 'text-gray-800 font-semibold' : 'text-gray-500')}>
                    {lastAgentId ? agentLabel : t('messages.allAgents')}
                  </span>
                </span>
                <ChevronDown size={14} className={clsx('text-gray-400 transition-transform', agentDropdownOpen && 'rotate-180')} />
              </button>
              {agentDropdownOpen && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-20 py-1 max-h-60 overflow-y-auto">
                  <p className="px-3 py-1.5 text-[11px] text-gray-400">{t('messages.agentFilterHint')}</p>
                  {[{ id: '', name: t('messages.allAgents') }, ...agents.map((a) => ({ id: String(a.id), name: a.name }))].map((opt) => {
                    const isActive = lastAgentId === opt.id
                    return (
                      <button
                        key={opt.id || 'all'}
                        onClick={() => { setFilter('last_agent_id', opt.id); setAgentDropdownOpen(false) }}
                        className={clsx(
                          'w-full px-3 py-2 text-xs text-left hover:bg-gray-50 flex items-center gap-2',
                          isActive ? 'font-medium text-indigo-700 bg-indigo-50' : 'text-gray-600'
                        )}
                      >
                        {isActive && <Check size={12} />}
                        <span className={clsx('truncate', !isActive && 'ml-5')}>{opt.name}</span>
                      </button>
                    )
                  })}
                </div>
              )}
            </div>
          )}

          {/* Active filter tags + clear all */}
          {(channelIds.length > 0 || hasDateFilter || !!lastAgentId) && (
            <div className="flex items-center gap-1.5 flex-wrap">
              {channelIds.map((id) => {
                const ch = messageChannels.find((c) => String(c.id) === id)
                if (!ch) return null
                return (
                  <span
                    key={id}
                    className="inline-flex items-center gap-1 px-2.5 py-1 bg-[var(--primary-soft)] text-[var(--primary-strong)] text-[11px] font-semibold rounded-full"
                  >
                    {getChannelFilterLabel(ch.type)}
                    <button onClick={() => toggleChannel(id)} className="hover:text-indigo-900">
                      <X size={11} />
                    </button>
                  </span>
                )
              })}
              {hasDateFilter && (
                <span className="inline-flex items-center gap-1 px-2.5 py-1 bg-[var(--primary-soft)] text-[var(--primary-strong)] text-[11px] font-semibold rounded-full">
                  {activePreset ? activePreset.label : dateButtonLabel}
                  <button onClick={() => setDateRange('', '')} className="hover:text-indigo-900">
                    <X size={11} />
                  </button>
                </span>
              )}
              {lastAgentId && (
                <span className="inline-flex items-center gap-1 px-2.5 py-1 bg-[var(--primary-soft)] text-[var(--primary-strong)] text-[11px] font-semibold rounded-full">
                  <UserRound size={11} />
                  {agentLabel}
                  <button onClick={() => setFilter('last_agent_id', '')} className="hover:text-indigo-900">
                    <X size={11} />
                  </button>
                </span>
              )}
              <button
                onClick={clearAllFilters}
                className="text-[11px] text-gray-400 hover:text-red-500 font-medium px-1"
              >
                {t('common.clear')}
              </button>
            </div>
          )}
        </div>

        {/* List */}
        <div className="flex-1 overflow-y-auto divide-y divide-gray-50">
          {loading ? (
            <div className="flex justify-center py-10">
              <div className="w-6 h-6 border-3 border-indigo-600 border-t-transparent rounded-full animate-spin" />
            </div>
          ) : conversations.length === 0 ? (
            <div className="text-center py-10 text-gray-400">
              <Filter className="mx-auto mb-2" size={24} />
              <p className="text-sm">{t('common.noResults')}</p>
            </div>
          ) : (
            conversations.map((conv) => {
              const chType = conv.channel?.type ?? ''
              const ChIcon = channelIcons[chType] ?? Globe
              const meta = conv.metadata as Record<string, string> | null
              const productName = meta?.product_name
              const preview = conv.latest_message?.content
              const previewText = preview
                ? preview.replace(/^\[.*?\]\n\n/, '').slice(0, 60)
                : productName
                  ? productName.slice(0, 60)
                  : null

              // Status badge for list items
              const statusLabel = conv.status === 'open'
                ? t('messages.waitingForReply')
                : t('messages.replied')
              const statusColor = conv.status === 'open'
                ? 'text-yellow-600'
                : 'text-green-600'

              return (
                <button
                  key={conv.id}
                  onClick={() => {
                    setSelectedId(conv.id)
                    if (!conv.is_read) {
                      setConversations((prev) =>
                        prev.map((c) =>
                          c.id === conv.id ? { ...c, is_read: true, unread_count: 0 } : c
                        )
                      )
                      setCounts((prev) => ({ ...prev, unread: Math.max(0, prev.unread - 1) }))
                    }
                  }}
                  className={clsx(
                    'w-full p-4 text-left hover:bg-gray-50 transition-colors',
                    selectedId === conv.id && 'bg-[var(--primary-soft)] border-l-2 border-[var(--primary)]',
                    !conv.is_read && 'bg-sky-50/60'
                  )}
                >
                  <div className="flex items-start justify-between mb-1">
                    <div className="flex items-center gap-2 min-w-0">
                      <span className={clsx('text-sm truncate', !conv.is_read ? 'font-semibold' : 'font-medium')}>
                        {conv.customer?.name ?? t('common.unknown')}
                        {(() => {
                          const igUser = (chType === 'instagram-message' || chType === 'instagram-comment')
                            ? String((conv.customer?.metadata as Record<string, string> | null)?.instagram_username ?? '')
                            : ''
                          if (igUser) return <span className="ml-1 font-normal text-xs text-pink-400">@{igUser}</span>
                          const phone = (chType === 'whatsapp' || chType === 'sms') ? customerPhone(conv.customer) : ''
                          return phone ? <span className="ml-1 font-normal text-xs text-gray-400">{phone}</span> : null
                        })()}
                      </span>
                      {conv.is_urgent && <AlertTriangle size={13} className="text-red-500 shrink-0" />}
                    </div>
                    <span className="text-xs text-gray-400 shrink-0 ml-2">
                      {formatRelativeTime(conv.last_message_at)}
                    </span>
                  </div>
                  {previewText && (
                    <p className="text-xs text-gray-500 truncate mb-1.5">{previewText}</p>
                  )}
                  <div className="flex items-center gap-2">
                    <span className={clsx('inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-semibold', getChannelBadgeClasses(chType))}>
                      <ChIcon size={11} />
                      {getChannelLabel(chType)}
                    </span>
                    <span className={clsx('text-xs font-medium', statusColor)}>
                      {statusLabel}
                    </span>
                    {conv.unread_count > 0 && (
                      <span className="ml-auto bg-indigo-600 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center shrink-0">
                        {conv.unread_count}
                      </span>
                    )}
                  </div>
                </button>
              )
            })
          )}
          {/* Infinite scroll sentinel — always in DOM so observer stays attached */}
          <div ref={sentinelRef} className="flex justify-center py-4">
            {loadingMore && (
              <div className="w-5 h-5 border-2 border-indigo-600 border-t-transparent rounded-full animate-spin" />
            )}
          </div>
        </div>
      </div>

      {/* Detail */}
      <div className={clsx(
        'flex-1 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden',
        selectedId === null && 'hidden md:block'
      )}>
        {selectedId ? (
          <div className="flex flex-col h-full">
            {/* Mobile back button */}
            <button
              onClick={() => setSelectedId(null)}
              className="md:hidden flex items-center gap-2 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50 border-b border-gray-100"
            >
              <ArrowLeft size={16} />
              {t('common.back')}
            </button>
            <div className="flex-1 min-h-0">
              <ConversationDetail
                conversationId={selectedId}
                onUpdate={handleUpdate}
              />
            </div>
          </div>
        ) : (
          <div className="flex flex-col items-center justify-center h-full">
            <div className="w-16 h-16 rounded-2xl bg-indigo-100 text-indigo-500 flex items-center justify-center mb-4">
              <MessageSquare size={28} />
            </div>
            <p className="text-sm font-medium text-gray-400">{t('messages.selectConversation')}</p>
          </div>
        )}
      </div>

      {showNewConversation && (
        <NewConversationModal
          onClose={() => setShowNewConversation(false)}
          onCreated={(conversationId) => {
            setShowNewConversation(false)
            setSelectedId(conversationId)
            fetchConversations()
            fetchCounts()
          }}
        />
      )}
    </div>
  )
}
