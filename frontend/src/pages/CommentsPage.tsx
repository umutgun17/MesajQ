import { useEffect, useState, useCallback, useRef, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import type { Conversation, PaginatedResponse, Channel, ViewCounts } from '@/types'
import {
  MessagesSquare, Filter, Search, Calendar,
  Instagram, Facebook, Globe,
  ChevronDown, Check, X, ArrowLeft, ArrowUpDown,
} from 'lucide-react'
import clsx from 'clsx'
import CommentDetailView from '@/components/CommentDetailView'
import { getChannelBadgeClasses, getChannelLabel, getChannelFilterLabel, formatRelativeTime } from '@/lib/channelUtils'

const channelIcons: Record<string, typeof Instagram> = {
  'instagram-comment': Instagram,
  'instagram-message': Instagram,
  'facebook-comment': Facebook,
  'facebook-message': Facebook,
}

type ViewTab = 'all' | 'waiting' | 'answered'

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

export default function CommentsPage() {
  const { t, i18n } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const [conversations, setConversations] = useState<Conversation[]>([])
  const [channels, setChannels] = useState<Channel[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [search, setSearch] = useState(searchParams.get('search') || '')
  const [counts, setCounts] = useState<ViewCounts>({ total: 0, unread: 0, waiting: 0, answered: 0, urgent: 0 })
  const [channelDropdownOpen, setChannelDropdownOpen] = useState(false)
  const [dateDropdownOpen, setDateDropdownOpen] = useState(false)
  const [sortDropdownOpen, setSortDropdownOpen] = useState(false)
  const channelDropdownRef = useRef<HTMLDivElement>(null)
  const dateDropdownRef = useRef<HTMLDivElement>(null)
  const sortDropdownRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)

  const view = (searchParams.get('view') as ViewTab) || 'all'
  const channelIdsParam = searchParams.get('channel_ids') || ''
  const channelIds = channelIdsParam ? channelIdsParam.split(',').filter(Boolean) : []
  const dateFrom = searchParams.get('date_from') || ''
  const dateTo = searchParams.get('date_to') || ''
  const sortParam = searchParams.get('sort') || 'last_message_at'
  const directionParam = searchParams.get('direction') || 'desc'

  const tabs: { key: ViewTab; label: string; countKey: keyof ViewCounts }[] = useMemo(() => [
    { key: 'all', label: t('comments.all'), countKey: 'total' },
    { key: 'waiting', label: t('comments.waiting'), countKey: 'waiting' },
    { key: 'answered', label: t('comments.replied'), countKey: 'answered' },
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
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  const fetchCounts = useCallback(() => {
    const params: Record<string, string> = { type: 'comment' }
    if (channelIdsParam) params.channel_ids = channelIdsParam
    if (dateFrom) params.date_from = dateFrom
    if (dateTo) params.date_to = dateTo
    api.get<ViewCounts>('/conversations/counts', { params }).then((res) => setCounts(res.data))
  }, [channelIdsParam, dateFrom, dateTo])

  const buildParams = useCallback(() => {
    const params: Record<string, string> = { type: 'comment' }
    if (view && view !== 'all') params.view = view
    if (channelIdsParam) params.channel_ids = channelIdsParam
    if (search) params.search = search
    if (dateFrom) params.date_from = dateFrom
    if (dateTo) params.date_to = dateTo
    if (sortParam) params.sort = sortParam
    if (directionParam) params.direction = directionParam
    return params
  }, [view, channelIdsParam, search, dateFrom, dateTo, sortParam, directionParam])

  const fetchConversations = useCallback(() => {
    setLoading(true)
    setPage(1)
    const params = buildParams()

    api.get<PaginatedResponse<Conversation>>('/conversations', { params }).then((res) => {
      setConversations(res.data.data)
      setTotal(res.data.total)
      setLastPage(res.data.last_page)
      setPage(1)
      setLoading(false)
    })
  }, [buildParams])

  const fetchMore = useCallback(() => {
    if (loadingMore || page >= lastPage) return
    setLoadingMore(true)
    const params = { ...buildParams(), page: String(page + 1) }

    api.get<PaginatedResponse<Conversation>>('/conversations', { params }).then((res) => {
      setConversations((prev) => [...prev, ...res.data.data])
      setPage(res.data.current_page)
      setLastPage(res.data.last_page)
      setLoadingMore(false)
    })
  }, [buildParams, page, lastPage, loadingMore])

  useEffect(() => {
    fetchConversations()
    fetchCounts()
  }, [fetchConversations, fetchCounts])

  // Infinite scroll: observe sentinel element
  useEffect(() => {
    const el = sentinelRef.current
    if (!el) return
    const observer = new IntersectionObserver(
      (entries) => { if (entries[0].isIntersecting) fetchMore() },
      { threshold: 0.1 }
    )
    observer.observe(el)
    return () => observer.disconnect()
  }, [fetchMore])

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
    setSearchParams(params)
  }

  const toggleChannel = (id: string) => {
    const current = new Set(channelIds)
    if (current.has(id)) current.delete(id)
    else current.add(id)
    setFilter('channel_ids', [...current].join(','))
  }

  const handleUpdate = () => {
    fetchConversations()
    fetchCounts()
  }

  const commentChannels = channels.filter((ch) => ch.type.includes('comment'))

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  const channelButtonLabel = channelIds.length === 0
    ? t('common.allChannels')
    : channelIds.length === 1
      ? getChannelFilterLabel(commentChannels.find((ch) => String(ch.id) === channelIds[0])?.type ?? '')
      : t('common.channelsSelected', { count: channelIds.length })

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
    <div className="flex flex-col md:flex-row gap-0 md:gap-6 h-[calc(100vh-5rem)] md:h-[calc(100vh-6.5rem)]">
      {/* List */}
      <div className={clsx(
        'w-full md:w-96 flex flex-col bg-white rounded-xl shadow-sm border border-gray-100 shrink-0',
        selectedId !== null && 'hidden md:flex'
      )}>
        {/* Header */}
        <div className="p-4 border-b border-gray-100">
          <div className="flex items-center justify-between mb-3">
            <h1 className="text-lg font-extrabold text-gray-900">{t('comments.title')}</h1>
            <span className="text-sm text-gray-500">{total} {t('comments.title').toLowerCase()}</span>
          </div>

          {/* Search */}
          <div className="relative mb-3">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('comments.searchUser')}
              className="w-full pl-9 pr-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white transition-colors"
            />
          </div>

          {/* Tabs - compact metric cards */}
          <div className="grid grid-cols-3 gap-1.5 mb-3">
            {tabs.map((tab) => {
              const count = counts[tab.countKey]
              const isActive = view === tab.key
              return (
                <button
                  key={tab.key}
                  onClick={() => setFilter('view', tab.key === 'all' ? '' : tab.key)}
                  className={clsx(
                    'flex flex-col items-center py-2 rounded-lg transition-colors',
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
                onClick={() => { setChannelDropdownOpen(!channelDropdownOpen); setDateDropdownOpen(false); setSortDropdownOpen(false) }}
                className="w-full flex items-center justify-between px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-full text-xs hover:bg-gray-100 transition-colors"
              >
                <span className={channelIds.length === 0 ? 'text-gray-500' : 'text-gray-800 font-medium'}>
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
                  {commentChannels.map((ch) => {
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
                onClick={() => { setDateDropdownOpen(!dateDropdownOpen); setChannelDropdownOpen(false); setSortDropdownOpen(false) }}
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
                onClick={() => { setSortDropdownOpen(!sortDropdownOpen); setChannelDropdownOpen(false); setDateDropdownOpen(false) }}
                className={clsx(
                  'flex items-center justify-center w-9 h-9 rounded-lg border transition-colors shrink-0',
                  directionParam === 'asc'
                    ? 'bg-[var(--primary-soft)] border-transparent text-[var(--primary-strong)]'
                    : 'bg-gray-50 border-gray-200 text-gray-400 hover:text-gray-600'
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

          {/* Active filter tags + clear all */}
          {(channelIds.length > 0 || hasDateFilter) && (
            <div className="flex items-center gap-1.5 flex-wrap">
              {channelIds.map((id) => {
                const ch = commentChannels.find((c) => String(c.id) === id)
                if (!ch) return null
                return (
                  <span
                    key={id}
                    className="inline-flex items-center gap-1 px-2 py-1 bg-[var(--primary-soft)] text-[var(--primary-strong)] text-[11px] font-semibold rounded-full"
                  >
                    {getChannelFilterLabel(ch.type)}
                    <button onClick={() => toggleChannel(id)} className="hover:text-indigo-900">
                      <X size={11} />
                    </button>
                  </span>
                )
              })}
              {hasDateFilter && (
                <span className="inline-flex items-center gap-1 px-2 py-1 bg-[var(--primary-soft)] text-[var(--primary-strong)] text-[11px] font-semibold rounded-full">
                  {activePreset ? activePreset.label : dateButtonLabel}
                  <button onClick={() => setDateRange('', '')} className="hover:text-indigo-900">
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
              const firstComment = conv.comments?.[0]
              const hasUnreplied = conv.comments?.some((c) => c.status === 'pending')

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
                    }
                  }}
                  className={clsx(
                    'w-full p-4 text-left hover:bg-gray-50 transition-colors',
                    selectedId === conv.id && 'bg-[var(--primary-soft)] border-l-2 border-[var(--primary)]',
                    !conv.is_read && 'bg-blue-50/50'
                  )}
                >
                  <div className="flex items-start justify-between mb-1.5">
                    <span className={clsx('text-sm truncate', !conv.is_read ? 'font-semibold' : 'font-medium')}>
                      {conv.customer?.name ?? t('common.unknown')}
                    </span>
                    <span className="text-xs text-gray-400 shrink-0 ml-2">
                      {formatRelativeTime(conv.last_message_at)}
                    </span>
                  </div>

                  {firstComment && (
                    <p className="text-xs text-gray-500 truncate mb-1.5">{firstComment.content}</p>
                  )}

                  <div className="flex items-center gap-2">
                    <span className={clsx('inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-semibold', getChannelBadgeClasses(chType))}>
                      <ChIcon size={11} />
                      {getChannelLabel(chType)}
                    </span>
                    {hasUnreplied ? (
                      <span className="text-xs font-medium text-yellow-600">{t('comments.pendingComment')}</span>
                    ) : (
                      <span className="text-xs font-medium text-green-600">{t('comments.repliedComment')}</span>
                    )}
                    {conv.comments && conv.comments.length > 1 && (
                      <span className="text-xs text-gray-400">{conv.comments.length} {t('comments.title').toLowerCase()}</span>
                    )}
                  </div>
                </button>
              )
            })
          )}
          {/* Infinite scroll sentinel */}
          {!loading && page < lastPage && (
            <div ref={sentinelRef} className="flex justify-center py-4">
              {loadingMore && (
                <div className="w-5 h-5 border-2 border-indigo-600 border-t-transparent rounded-full animate-spin" />
              )}
            </div>
          )}
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
              <CommentDetailView
                conversationId={selectedId}
                onUpdate={handleUpdate}
              />
            </div>
          </div>
        ) : (
          <div className="flex flex-col items-center justify-center h-full text-gray-400">
            <MessagesSquare size={48} className="mb-3" />
            <p>{t('comments.selectComment')}</p>
          </div>
        )}
      </div>
    </div>
  )
}
