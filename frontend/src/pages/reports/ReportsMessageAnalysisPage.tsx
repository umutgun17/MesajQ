import { useEffect, useState, useCallback, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import type { MessageReportStats, MessageCategory, MessageCategoriesResponse, MessageChannelBreakdown, MessageTrendPoint, MessageReportItem } from '@/types'
import { MessageCircle, TrendingUp, SmilePlus, AlertTriangle, BarChart3, Info, X, ChevronDown, User, Clock } from 'lucide-react'
import clsx from 'clsx'
import { getChannelLabel, getChannelBadgeClasses } from '@/lib/channelUtils'

type PeriodFilter = '' | '7d' | '30d' | '90d' | '180d' | '365d'

// periodLabels moved inside component to use t()

function StatCard({ label, value, sub, icon: Icon, color }: { label: string; value: string; sub?: string; icon: React.ElementType; color: string }) {
  const colors: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-600',
    indigo: 'bg-indigo-50 text-indigo-600',
    green: 'bg-green-50 text-green-600',
    red: 'bg-red-50 text-red-600',
  }
  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <div className="flex items-center gap-3 mb-3">
        <div className={clsx('w-11 h-11 rounded-2xl flex items-center justify-center', colors[color])}>
          <Icon size={20} />
        </div>
        <span className="text-sm text-gray-500">{label}</span>
      </div>
      <p className="text-2xl font-extrabold text-gray-900">{value}</p>
      {sub && <p className="text-xs text-gray-400 mt-1">{sub}</p>}
    </div>
  )
}

export default function ReportsMessageAnalysisPage() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  const periodLabels: Record<string, string> = {
    '': t('reportsCommon.allTime'), '7d': t('reportsCommon.last7Days'), '30d': t('reportsCommon.last30Days'),
    '90d': t('reportsCommon.last90Days'), '180d': t('reportsCommon.last6Months'), '365d': t('reportsCommon.last1Year'),
  }

  const [stats, setStats] = useState<MessageReportStats | null>(null)
  const [categories, setCategories] = useState<MessageCategory[]>([])
  const [channels, setChannels] = useState<MessageChannelBreakdown[]>([])
  const [trend, setTrend] = useState<MessageTrendPoint[]>([])
  const [loading, setLoading] = useState(true)
  const [period, setPeriod] = useState<PeriodFilter>('30d')
  const [channelId, setChannelId] = useState('')
  const [availableChannels, setAvailableChannels] = useState<{ id: number; name: string }[]>([])

  // Category messages panel
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null)
  const [categoryMessages, setCategoryMessages] = useState<MessageReportItem[]>([])
  const [categoryMeta, setCategoryMeta] = useState<{ label: string; color: string; total: number; lastPage: number; page: number }>({ label: '', color: '', total: 0, lastPage: 1, page: 1 })
  const [categoryLoading, setCategoryLoading] = useState(false)
  const panelRef = useRef<HTMLDivElement>(null)

  const fetchCategoryMessages = useCallback((catKey: string, page = 1, append = false) => {
    const cat = categories.find(c => c.key === catKey)
    if (!cat) return
    setCategoryLoading(true)
    const params: Record<string, string> = { category: catKey, page: String(page), per_page: '30' }
    if (period) params.period = period
    if (channelId) params.channel_id = channelId

    api.get<{ data: MessageReportItem[]; current_page: number; last_page: number; total: number }>('/reports/messages/by-category', { params })
      .then(res => {
        setCategoryMessages(prev => append ? [...prev, ...res.data.data] : res.data.data)
        setCategoryMeta({ label: cat.label, color: cat.color, total: res.data.total, lastPage: res.data.last_page, page: res.data.current_page })
      })
      .finally(() => setCategoryLoading(false))
  }, [period, channelId, categories])

  const handleCategoryClick = useCallback((catKey: string) => {
    if (selectedCategory === catKey) {
      setSelectedCategory(null)
      setCategoryMessages([])
      return
    }
    setSelectedCategory(catKey)
    setCategoryMessages([])
    fetchCategoryMessages(catKey, 1)
    setTimeout(() => panelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100)
  }, [selectedCategory, fetchCategoryMessages])

  // Re-fetch when filters change
  useEffect(() => {
    if (selectedCategory && categories.length > 0) {
      fetchCategoryMessages(selectedCategory, 1)
    }
  }, [period, channelId])

  const fetchAll = useCallback(() => {
    setLoading(true)
    const params: Record<string, string> = {}
    if (period) params.period = period
    if (channelId) params.channel_id = channelId

    Promise.all([
      api.get<MessageReportStats>('/reports/messages/stats', { params }),
      api.get<MessageCategoriesResponse>('/reports/messages/categories', { params }),
      api.get<{ channels: MessageChannelBreakdown[]; total: number }>('/reports/messages/channels', { params }),
      api.get<MessageTrendPoint[]>('/reports/messages/trend', { params }),
    ]).then(([statsRes, catRes, chRes, trendRes]) => {
      setStats(statsRes.data)
      setCategories(catRes.data.categories)
      setChannels(chRes.data.channels)
      setTrend(trendRes.data)
      if (availableChannels.length === 0 && chRes.data.channels.length > 0) {
        setAvailableChannels(chRes.data.channels.map(c => ({ id: c.channel_id, name: c.channel_name })))
      }
    }).finally(() => setLoading(false))
  }, [period, channelId])

  useEffect(() => { fetchAll() }, [fetchAll])

  const maxCatCount = Math.max(...categories.map(c => c.count), 1)
  const maxTrend = Math.max(...trend.map(t => t.count), 1)

  // Top 3 insights
  const topCategories = categories.filter(c => c.key !== 'diger').slice(0, 3)

  return (
    <div className="p-6 space-y-6 max-w-7xl mx-auto">
      {/* Header */}
      <div>
        <div className="flex items-center gap-3 mb-1">
          <MessageCircle className="text-indigo-600" size={24} />
          <h1 className="text-xl font-extrabold text-gray-900">{t('reportsCommon.messageAnalysisTitle')}</h1>
        </div>
        <p className="text-sm text-gray-500">{t('reportsCommon.messageAnalysisDesc')}</p>
      </div>

      {/* Stat Cards */}
      {stats && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard label={t('reportsCommon.totalMessages')} value={stats.total_customer_messages.toLocaleString(locale)} icon={MessageCircle} color="blue" />
          <StatCard label={t('reportsCommon.periodMessages')} value={stats.period_customer_messages.toLocaleString(locale)} sub={t('reportsCommon.dailyAvg', { count: stats.avg_daily })} icon={TrendingUp} color="indigo" />
          <StatCard label={t('reportsCommon.satisfactionRate')} value={`%${stats.satisfaction_rate}`} icon={SmilePlus} color="green" />
          <StatCard label={t('reportsCommon.complaintRate')} value={`%${stats.complaint_rate}`} icon={AlertTriangle} color="red" />
        </div>
      )}

      {/* Filters */}
      <div className="flex flex-wrap gap-3">
        <select
          value={period}
          onChange={e => setPeriod(e.target.value as PeriodFilter)}
          className="text-sm border border-transparent shadow-sm rounded-full px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-[var(--primary)]"
        >
          {Object.entries(periodLabels).map(([k, v]) => (
            <option key={k} value={k}>{v}</option>
          ))}
        </select>
        <select
          value={channelId}
          onChange={e => setChannelId(e.target.value)}
          className="text-sm border border-transparent shadow-sm rounded-full px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-[var(--primary)]"
        >
          <option value="">{t('reportsCommon.allChannels')}</option>
          {availableChannels.map(ch => (
            <option key={ch.id} value={ch.id}>{ch.name}</option>
          ))}
        </select>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-20">
          <div className="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin" />
        </div>
      ) : (
        <>
          {/* 2-column: Categories + Channels */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Category Distribution */}
            <div className="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <h2 className="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <BarChart3 size={18} className="text-indigo-600" />
                {t('reportsCommon.categoryDistribution')}
              </h2>
              <div className="space-y-3">
                {categories.map(cat => (
                  <div
                    key={cat.key}
                    className={clsx(
                      'flex items-center gap-3 cursor-pointer rounded-lg px-2 py-1 -mx-2 transition-colors',
                      selectedCategory === cat.key ? 'bg-gray-100 ring-2 ring-indigo-300' : 'hover:bg-gray-50'
                    )}
                    onClick={() => handleCategoryClick(cat.key)}
                  >
                    <div className="w-32 text-sm text-gray-700 truncate shrink-0">{cat.label}</div>
                    <div className="flex-1 bg-gray-100 rounded-full h-6 overflow-hidden">
                      <div
                        className="h-full rounded-full transition-all duration-500"
                        style={{
                          width: `${Math.max((cat.count / maxCatCount) * 100, 1)}%`,
                          backgroundColor: cat.color,
                        }}
                      />
                    </div>
                    <div className="w-16 text-right text-sm font-medium text-gray-900">{cat.count.toLocaleString('tr-TR')}</div>
                    <div className="w-14 text-right text-xs text-gray-500">%{cat.percentage}</div>
                  </div>
                ))}
              </div>
            </div>

            {/* Channel Distribution */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <h2 className="text-base font-semibold text-gray-900 mb-4">{t('reportsCommon.channelDistribution')}</h2>
              <div className="space-y-3">
                {channels.map(ch => (
                  <div key={ch.channel_id} className="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                    <div>
                      <p className="text-sm font-medium text-gray-900">{ch.channel_name}</p>
                      <p className="text-xs text-gray-400">{ch.channel_type}</p>
                    </div>
                    <div className="text-right">
                      <p className="text-sm font-semibold text-gray-900">{ch.message_count.toLocaleString('tr-TR')}</p>
                      <p className="text-xs text-gray-400">%{ch.percentage}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Category Messages Panel */}
          {selectedCategory && (
            <div ref={panelRef} className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
              <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100" style={{ borderLeftWidth: 4, borderLeftColor: categoryMeta.color }}>
                <div className="flex items-center gap-3">
                  <h3 className="text-base font-semibold text-gray-900">{categoryMeta.label}</h3>
                  <span className="text-sm text-gray-500">{categoryMeta.total.toLocaleString(locale)} {t('reportsCommon.message')}</span>
                </div>
                <button
                  onClick={() => { setSelectedCategory(null); setCategoryMessages([]) }}
                  className="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-colors"
                >
                  <X size={18} />
                </button>
              </div>

              {categoryLoading && categoryMessages.length === 0 ? (
                <div className="flex items-center justify-center py-12">
                  <div className="w-6 h-6 border-3 border-indigo-200 border-t-indigo-600 rounded-full animate-spin" />
                </div>
              ) : categoryMessages.length === 0 ? (
                <div className="text-center py-12 text-gray-400 text-sm">{t('reportsCommon.noCategoryMessages')}</div>
              ) : (
                <div className="divide-y divide-gray-50">
                  {categoryMessages.map(msg => (
                    <div key={msg.id} className="px-5 py-3 hover:bg-gray-50 transition-colors">
                      <div className="flex items-start justify-between gap-3">
                        <p className="text-sm text-gray-800 flex-1">{msg.content.length > 200 ? msg.content.slice(0, 200) + '…' : msg.content}</p>
                        {msg.conversation?.channel && (
                          <span className={clsx('text-xs px-2 py-0.5 rounded-full whitespace-nowrap shrink-0', getChannelBadgeClasses(msg.conversation.channel.type))}>
                            {getChannelLabel(msg.conversation.channel.type)}
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-4 mt-1.5 text-xs text-gray-400">
                        {msg.customer_name && (
                          <span className="flex items-center gap-1">
                            <User size={12} />
                            {msg.customer_name}
                          </span>
                        )}
                        <span className="flex items-center gap-1">
                          <Clock size={12} />
                          {new Date(msg.created_at).toLocaleDateString(locale)} {new Date(msg.created_at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })}
                        </span>
                      </div>
                    </div>
                  ))}

                  {/* Load More */}
                  {categoryMeta.page < categoryMeta.lastPage && (
                    <div className="px-5 py-3 text-center">
                      <button
                        onClick={() => fetchCategoryMessages(selectedCategory, categoryMeta.page + 1, true)}
                        disabled={categoryLoading}
                        className="inline-flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-700 font-medium disabled:opacity-50"
                      >
                        {categoryLoading ? (
                          <div className="w-4 h-4 border-2 border-indigo-200 border-t-indigo-600 rounded-full animate-spin" />
                        ) : (
                          <ChevronDown size={16} />
                        )}
                        {t('reportsCommon.loadMore')}
                      </button>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}

          {/* Trend Chart */}
          {trend.length > 0 && (
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <h2 className="text-base font-semibold text-gray-900 mb-4">{t('reportsCommon.messageTrend')}</h2>
              <div className="flex items-end gap-[2px] h-40">
                {trend.map((t) => (
                  <div
                    key={t.date}
                    className="flex-1 bg-indigo-500 hover:bg-indigo-600 rounded-t transition-colors cursor-default group relative"
                    style={{ height: `${Math.max((t.count / maxTrend) * 100, 2)}%` }}
                    title={`${t.date}: ${t.count}`}
                  >
                    <div className="absolute -top-8 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-xs px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none">
                      {t.date}: {t.count}
                    </div>
                  </div>
                ))}
              </div>
              <div className="flex justify-between mt-2 text-xs text-gray-400">
                <span>{trend[0]?.date}</span>
                <span>{trend[trend.length - 1]?.date}</span>
              </div>
            </div>
          )}

          {/* Insights */}
          {topCategories.length > 0 && (
            <div className="bg-blue-50 border border-blue-200 rounded-xl p-5">
              <div className="flex items-center gap-2 mb-3">
                <Info size={18} className="text-blue-600" />
                <h3 className="text-sm font-semibold text-blue-900">{t('reportsCommon.keyFindings')}</h3>
              </div>
              <ul className="space-y-2">
                {topCategories.map((cat, i) => (
                  <li key={cat.key} className="text-sm text-blue-800">
                    <span className="font-medium">{i + 1}.</span>{' '}
                    <span className="font-semibold">{cat.label}</span> kategorisi{' '}
                    <span className="font-semibold">{cat.count.toLocaleString('tr-TR')}</span> mesajla{' '}
                    en yoğun {i === 0 ? 'konu' : `${i + 1}. konu`} (%{cat.percentage})
                  </li>
                ))}
              </ul>
            </div>
          )}
        </>
      )}
    </div>
  )
}
