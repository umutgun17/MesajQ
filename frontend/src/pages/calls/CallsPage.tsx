import { useEffect, useState, useCallback, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import api from '@/lib/api'
import { formatRelativeTime } from '@/lib/channelUtils'
import {
  Phone, PhoneIncoming, PhoneOutgoing, PhoneMissed, Search,
  Loader2, ChevronLeft, ChevronRight, Clock, Users, TrendingUp,
  BarChart3, Filter, Volume2, Voicemail, Smile, Frown, Meh,
  ChevronDown, ChevronUp,
} from 'lucide-react'
import clsx from 'clsx'

interface Call {
  id: number
  common_id: string
  caller: string
  called: string
  extension: string | null
  agent_name: string | null
  direction: string
  status: string
  started_at: string
  duration: number
  ring_duration: number
  recording_url: string | null
  transcription: string | null
  ai_summary: string | null
  ai_sentiment: string | null
  ai_category: string | null
  transcribed_at: string | null
}

interface Stats {
  total: number
  answered: number
  missed: number
  ivr: number
  outbound: number
  avg_duration: number
  total_duration: number
  answer_rate: number
  transcribed: number
}

interface ExtensionStat {
  extension: string
  agent_name: string | null
  total_calls: number
  answered: number
  missed: number
  avg_duration: number
  total_duration: number
}

interface HourlyData {
  hour: number
  total: number
  answered: number
  missed: number
}

interface DailyData {
  date: string
  total: number
  answered: number
  missed: number
}

interface SentimentStats {
  sentiments: Record<string, number>
  categories: Record<string, number>
}

const statusLabels: Record<string, string> = {
  answered: 'Cevaplandi',
  missed: 'Cevapsiz',
  voicemail: 'IVR/Anons',
  ivr: 'IVR',
  no_answer: 'Yanitlanmadi',
  busy: 'Mesgul',
}

const statusColors: Record<string, string> = {
  answered: 'bg-green-100 text-green-700',
  missed: 'bg-red-100 text-red-700',
  voicemail: 'bg-purple-100 text-purple-700',
  ivr: 'bg-purple-100 text-purple-700',
  no_answer: 'bg-orange-100 text-orange-700',
  busy: 'bg-yellow-100 text-yellow-700',
}

const sentimentLabels: Record<string, string> = {
  positive: 'Olumlu',
  neutral: 'Notr',
  negative: 'Olumsuz',
}

const sentimentColors: Record<string, string> = {
  positive: 'text-green-600',
  neutral: 'text-gray-500',
  negative: 'text-red-600',
}

const sentimentBgColors: Record<string, string> = {
  positive: 'bg-green-500',
  neutral: 'bg-gray-400',
  negative: 'bg-red-500',
}

const sentimentIcons: Record<string, typeof Smile> = {
  positive: Smile,
  neutral: Meh,
  negative: Frown,
}

const categoryLabels: Record<string, string> = {
  satis_oncesi: 'Satis Oncesi',
  siparis_takip: 'Siparis Takip',
  iade_degisim: 'Iade/Degisim',
  sikayet: 'Sikayet',
  bilgi_talebi: 'Bilgi Talebi',
  teknik_destek: 'Teknik Destek',
  diger: 'Diger',
}

const categoryBarColors: Record<string, string> = {
  siparis_takip: 'bg-blue-500',
  iade_degisim: 'bg-orange-500',
  sikayet: 'bg-red-500',
  teknik_destek: 'bg-violet-500',
  bilgi_talebi: 'bg-cyan-500',
  satis_oncesi: 'bg-emerald-500',
  diger: 'bg-gray-400',
}

function getIvrLabel(called: string): string | null {
  if (called.includes('mesaidisi')) return 'Mesai Disi'
  if (called.includes('oglearasi')) return 'Ogle Arasi'
  if (called.includes('karsilama') || called.includes('ivr')) return 'IVR Karsilama'
  if (called.includes('announcement')) return 'Anons'
  if (called.includes('Puanlama')) return 'Puanlama'
  return null
}

function formatDuration(seconds: number): string {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

function formatTotalDuration(seconds: number): string {
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (h > 0) return `${h}s ${m}dk`
  return `${m}dk`
}

// Simple bar chart component
function BarChart({ data, maxVal, bars }: {
  data: { label: string; values: { key: string; value: number; color: string }[] }[]
  maxVal: number
  bars: { key: string; label: string; color: string }[]
}) {
  if (!data.length) return null
  return (
    <div className="space-y-1">
      <div className="flex justify-end gap-3 mb-2">
        {bars.map(b => (
          <div key={b.key} className="flex items-center gap-1.5 text-[10px] text-gray-500">
            <div className={clsx('w-2.5 h-2.5 rounded-sm', b.color)} />
            {b.label}
          </div>
        ))}
      </div>
      <div className="flex items-end gap-px" style={{ height: 120 }}>
        {data.map((d, i) => {
          const total = d.values.reduce((s, v) => s + v.value, 0)
          return (
            <div key={i} className="flex-1 flex flex-col items-center gap-0.5 group relative">
              {/* Tooltip */}
              <div className="absolute bottom-full mb-1 hidden group-hover:block z-10">
                <div className="bg-gray-800 text-white text-[10px] rounded px-2 py-1 whitespace-nowrap">
                  <div className="font-medium">{d.label}</div>
                  {d.values.map(v => (
                    <div key={v.key}>{bars.find(b => b.key === v.key)?.label}: {v.value}</div>
                  ))}
                </div>
              </div>
              {/* Stacked bar */}
              <div className="w-full flex flex-col-reverse rounded-t overflow-hidden" style={{ height: maxVal ? `${(total / maxVal) * 100}%` : 0 }}>
                {d.values.map(v => (
                  <div
                    key={v.key}
                    className={clsx(v.color, 'transition-all')}
                    style={{ height: total ? `${(v.value / total) * 100}%` : 0 }}
                  />
                ))}
              </div>
              {/* Label */}
              <span className="text-[9px] text-gray-400 truncate w-full text-center">{d.label}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

type TabFilter = 'all' | 'answered' | 'missed' | 'ivr' | 'outbound'

export default function CallsPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [calls, setCalls] = useState<Call[]>([])
  const [stats, setStats] = useState<Stats | null>(null)
  const [extensions, setExtensions] = useState<ExtensionStat[]>([])
  const [loading, setLoading] = useState(true)
  const [tabFilter, setTabFilter] = useState<TabFilter>('all')
  const [extensionFilter, setExtensionFilter] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [showFilters, setShowFilters] = useState(false)
  const [transcribedFilter, setTranscribedFilter] = useState('')
  const [hourlyData, setHourlyData] = useState<HourlyData[]>([])
  const [dailyData, setDailyData] = useState<DailyData[]>([])
  const [sentimentStats, setSentimentStats] = useState<SentimentStats | null>(null)
  const [showCharts, setShowCharts] = useState(true)
  const [showExtensions, setShowExtensions] = useState(false)

  const fetchCalls = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = { page: String(page) }
      if (tabFilter === 'answered') {
        params.call_type = 'normal'
        params.status = 'answered'
      } else if (tabFilter === 'missed') {
        params.call_type = 'normal_missed'
      } else if (tabFilter === 'ivr') {
        params.call_type = 'ivr'
      } else if (tabFilter === 'outbound') {
        params.call_type = 'outbound'
      }
      if (extensionFilter) params.extension = extensionFilter
      if (search) params.search = search
      if (dateFrom) params.date_from = dateFrom
      if (dateTo) params.date_to = dateTo
      if (transcribedFilter) params.transcribed = transcribedFilter
      const res = await api.get('/calls', { params })
      setCalls(res.data.data)
      setLastPage(res.data.last_page)
      setTotal(res.data.total)
    } finally {
      setLoading(false)
    }
  }, [tabFilter, extensionFilter, search, page, dateFrom, dateTo, transcribedFilter])

  const dateParams = useMemo(() => {
    const p: Record<string, string> = {}
    if (dateFrom) p.date_from = dateFrom
    if (dateTo) p.date_to = dateTo
    return p
  }, [dateFrom, dateTo])

  const fetchStats = useCallback(async () => {
    const res = await api.get('/calls/stats', { params: dateParams })
    setStats(res.data)
  }, [dateParams])

  const fetchExtensions = useCallback(async () => {
    const res = await api.get('/calls/extensions', { params: dateParams })
    setExtensions(res.data)
  }, [dateParams])

  const fetchCharts = useCallback(async () => {
    const [hourly, daily, sentiment] = await Promise.all([
      api.get('/calls/chart/hourly', { params: dateParams }),
      api.get('/calls/chart/daily', { params: dateParams }),
      api.get('/calls/sentiment', { params: dateParams }),
    ])
    setHourlyData(hourly.data)
    setDailyData(daily.data)
    setSentimentStats(sentiment.data)
  }, [dateParams])

  useEffect(() => { fetchCalls() }, [fetchCalls])
  useEffect(() => { fetchStats(); fetchExtensions(); fetchCharts() }, [fetchStats, fetchExtensions, fetchCharts])

  const statusTabs: { key: TabFilter; label: string; count: number; icon: typeof Phone }[] = [
    { key: 'all', label: 'Tumunu', count: stats?.total ?? 0, icon: Phone },
    { key: 'answered', label: 'Cevaplanan', count: stats?.answered ?? 0, icon: PhoneIncoming },
    { key: 'missed', label: 'Cevapsiz', count: stats?.missed ?? 0, icon: PhoneMissed },
    { key: 'ivr', label: 'IVR', count: stats?.ivr ?? 0, icon: Voicemail },
    { key: 'outbound', label: 'Giden', count: stats?.outbound ?? 0, icon: PhoneOutgoing },
  ]

  // Prepare hourly chart data
  const hourlyChartData = useMemo(() => {
    const allHours = Array.from({ length: 24 }, (_, i) => i)
    const dataMap = new Map(hourlyData.map(d => [Number(d.hour), d]))
    return allHours.map(h => {
      const d = dataMap.get(h)
      return {
        label: `${String(h).padStart(2, '0')}`,
        values: [
          { key: 'answered', value: d?.answered ?? 0, color: 'bg-green-400' },
          { key: 'missed', value: d?.missed ?? 0, color: 'bg-red-400' },
        ],
      }
    })
  }, [hourlyData])

  const hourlyMax = useMemo(() => Math.max(...hourlyChartData.map(d => d.values.reduce((s, v) => s + v.value, 0)), 1), [hourlyChartData])

  // Prepare daily chart data (last 30 days max)
  const dailyChartData = useMemo(() => {
    const recent = dailyData.slice(-30)
    return recent.map(d => ({
      label: new Date(d.date).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit' }),
      values: [
        { key: 'answered', value: d.answered, color: 'bg-green-400' },
        { key: 'missed', value: d.missed, color: 'bg-red-400' },
      ],
    }))
  }, [dailyData])

  const dailyMax = useMemo(() => Math.max(...dailyChartData.map(d => d.values.reduce((s, v) => s + v.value, 0)), 1), [dailyChartData])

  // Sentiment totals
  const sentimentTotal = useMemo(() => {
    if (!sentimentStats?.sentiments) return 0
    return Object.values(sentimentStats.sentiments).reduce((s, v) => s + v, 0)
  }, [sentimentStats])

  // Category sorted
  const sortedCategories = useMemo(() => {
    if (!sentimentStats?.categories) return []
    return Object.entries(sentimentStats.categories)
      .filter(([k]) => !k.startsWith('[')) // skip malformed array categories
      .sort((a, b) => b[1] - a[1])
  }, [sentimentStats])

  const maxCategoryCount = useMemo(() => sortedCategories.length ? sortedCategories[0][1] : 0, [sortedCategories])

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Phone size={24} className="text-blue-600" />
          <h1 className="text-2xl font-extrabold text-gray-900">{t('callsCommon.callAnalysis')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => setShowCharts(!showCharts)}
            className={clsx(
              'flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm border transition-colors',
              showCharts ? 'bg-blue-50 border-blue-200 text-blue-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'
            )}
          >
            <BarChart3 size={14} />
            Grafikler
          </button>
          <button
            onClick={() => setShowFilters(!showFilters)}
            className={clsx(
              'flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm border transition-colors',
              showFilters ? 'bg-blue-50 border-blue-200 text-blue-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'
            )}
          >
            <Filter size={14} />
            {t('callsCommon.filters')}
          </button>
        </div>
      </div>

      {/* Stats Cards */}
      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
          <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
            <div className="flex items-center gap-2 text-gray-500 text-xs mb-1">
              <Phone size={14} />
              Toplam Cagri
            </div>
            <p className="text-2xl font-extrabold text-gray-900">{stats.total.toLocaleString('tr-TR')}</p>
          </div>
          <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
            <div className="flex items-center gap-2 text-green-600 text-xs mb-1">
              <TrendingUp size={14} />
              Cevaplanma Orani
            </div>
            <p className="text-2xl font-bold text-green-600">%{stats.answer_rate}</p>
          </div>
          <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
            <div className="flex items-center gap-2 text-gray-500 text-xs mb-1">
              <Clock size={14} />
              Ort. Sure
            </div>
            <p className="text-2xl font-extrabold text-gray-900">{formatDuration(stats.avg_duration)}</p>
          </div>
          <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
            <div className="flex items-center gap-2 text-gray-500 text-xs mb-1">
              <BarChart3 size={14} />
              Toplam Sure
            </div>
            <p className="text-2xl font-extrabold text-gray-900">{formatTotalDuration(stats.total_duration)}</p>
          </div>
          <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
            <div className="flex items-center gap-2 text-blue-600 text-xs mb-1">
              <Volume2 size={14} />
              Transkript
            </div>
            <p className="text-2xl font-bold text-blue-600">{stats.transcribed.toLocaleString('tr-TR')}</p>
          </div>
        </div>
      )}

      {/* Charts Section */}
      {showCharts && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {/* Hourly Distribution */}
          <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
            <h3 className="text-sm font-semibold text-gray-700 mb-3">Saatlik Dagilim</h3>
            <BarChart
              data={hourlyChartData}
              maxVal={hourlyMax}
              bars={[
                { key: 'answered', label: 'Cevaplanan', color: 'bg-green-400' },
                { key: 'missed', label: 'Cevapsiz', color: 'bg-red-400' },
              ]}
            />
          </div>

          {/* Daily Trend */}
          <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
            <h3 className="text-sm font-semibold text-gray-700 mb-3">Gunluk Trend</h3>
            {dailyChartData.length > 0 ? (
              <BarChart
                data={dailyChartData}
                maxVal={dailyMax}
                  bars={[
                  { key: 'answered', label: 'Cevaplanan', color: 'bg-green-400' },
                  { key: 'missed', label: 'Cevapsiz', color: 'bg-red-400' },
                ]}
              />
            ) : (
              <div className="h-[120px] flex items-center justify-center text-sm text-gray-400">Veri yok</div>
            )}
          </div>

          {/* Sentiment Distribution */}
          <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
            <h3 className="text-sm font-semibold text-gray-700 mb-3">Duygu Analizi</h3>
            {sentimentTotal > 0 ? (
              <div className="space-y-4">
                {/* Horizontal stacked bar */}
                <div className="flex rounded-full overflow-hidden h-6">
                  {(['positive', 'neutral', 'negative'] as const).map(key => {
                    const val = sentimentStats?.sentiments[key] ?? 0
                    const pct = (val / sentimentTotal) * 100
                    if (pct === 0) return null
                    return (
                      <div
                        key={key}
                        className={clsx(sentimentBgColors[key], 'transition-all relative group')}
                        style={{ width: `${pct}%` }}
                      >
                        {pct > 10 && (
                          <span className="absolute inset-0 flex items-center justify-center text-white text-xs font-medium">
                            %{Math.round(pct)}
                          </span>
                        )}
                      </div>
                    )
                  })}
                </div>
                {/* Legend */}
                <div className="flex justify-center gap-6">
                  {(['positive', 'neutral', 'negative'] as const).map(key => {
                    const val = sentimentStats?.sentiments[key] ?? 0
                    const Icon = sentimentIcons[key]
                    return (
                      <div key={key} className="flex items-center gap-2">
                        <Icon size={14} className={sentimentColors[key]} />
                        <span className="text-sm text-gray-700">{sentimentLabels[key]}</span>
                        <span className="text-sm font-semibold text-gray-900">{val.toLocaleString('tr-TR')}</span>
                      </div>
                    )
                  })}
                </div>
              </div>
            ) : (
              <div className="h-[80px] flex items-center justify-center text-sm text-gray-400">Henuz analiz verisi yok</div>
            )}
          </div>

          {/* Category Distribution */}
          <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
            <h3 className="text-sm font-semibold text-gray-700 mb-3">Kategori Dagilimi</h3>
            {sortedCategories.length > 0 ? (
              <div className="space-y-2">
                {sortedCategories.map(([key, count]) => (
                  <div key={key} className="flex items-center gap-3">
                    <span className="text-xs text-gray-600 w-24 shrink-0 truncate">{categoryLabels[key] || key}</span>
                    <div className="flex-1 h-5 bg-gray-100 rounded-full overflow-hidden">
                      <div
                        className={clsx('h-full rounded-full transition-all', categoryBarColors[key] || 'bg-gray-400')}
                        style={{ width: `${(count / maxCategoryCount) * 100}%` }}
                      />
                    </div>
                    <span className="text-xs font-semibold text-gray-700 w-10 text-right">{count}</span>
                  </div>
                ))}
              </div>
            ) : (
              <div className="h-[80px] flex items-center justify-center text-sm text-gray-400">Henuz analiz verisi yok</div>
            )}
          </div>
        </div>
      )}

      {/* Filters Panel */}
      {showFilters && (
        <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
          <div className="grid grid-cols-1 md:grid-cols-5 gap-3">
            <div>
              <label className="text-xs text-gray-500 mb-1 block">Baslangic Tarihi</label>
              <input
                type="date"
                value={dateFrom}
                onChange={e => { setDateFrom(e.target.value); setPage(1) }}
                className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="text-xs text-gray-500 mb-1 block">Bitis Tarihi</label>
              <input
                type="date"
                value={dateTo}
                onChange={e => { setDateTo(e.target.value); setPage(1) }}
                className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="text-xs text-gray-500 mb-1 block">Dahili</label>
              <select
                value={extensionFilter}
                onChange={e => { setExtensionFilter(e.target.value); setPage(1) }}
                className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Tumu</option>
                {extensions.map(ext => (
                  <option key={ext.extension} value={ext.extension}>
                    {ext.agent_name ? `${ext.agent_name} (${ext.extension})` : ext.extension} — {ext.total_calls} cagri
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs text-gray-500 mb-1 block">Transkript</label>
              <select
                value={transcribedFilter}
                onChange={e => { setTranscribedFilter(e.target.value); setPage(1) }}
                className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Tumu</option>
                <option value="yes">Transkriptli</option>
                <option value="no">Transkriptsiz</option>
              </select>
            </div>
            <div className="flex items-end">
              <button
                onClick={() => { setDateFrom(''); setDateTo(''); setExtensionFilter(''); setTranscribedFilter(''); setSearch(''); setTabFilter('all'); setPage(1) }}
                className="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-lg hover:bg-gray-50"
              >
                Temizle
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Status Tabs */}
      <div className="flex gap-1 bg-white shadow-sm border border-gray-100 p-1 rounded-full overflow-x-auto w-fit max-w-full">
        {statusTabs.map(t => (
          <button
            key={t.key}
            onClick={() => { setTabFilter(t.key); setPage(1) }}
            className={clsx(
              'flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium whitespace-nowrap transition-colors',
              tabFilter === t.key ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]' : 'text-gray-500 hover:text-gray-700'
            )}
          >
            <t.icon size={14} />
            {t.label}
            <span className="text-xs text-gray-400">({t.count.toLocaleString('tr-TR')})</span>
          </button>
        ))}
      </div>

      {/* Search */}
      <div className="relative">
        <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
        <input
          type="text"
          placeholder="Numara, transkript veya ozet ile ara..."
          value={search}
          onChange={e => { setSearch(e.target.value); setPage(1) }}
          className="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>

      {/* Extension Stats (collapsible) */}
      {extensions.length > 0 && (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
          <button
            onClick={() => setShowExtensions(!showExtensions)}
            className="w-full px-4 py-3 border-b border-gray-100 flex items-center justify-between hover:bg-gray-50 transition-colors"
          >
            <h3 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
              <Users size={14} />
              Dahili Bazli Performans
            </h3>
            {showExtensions ? <ChevronUp size={16} className="text-gray-400" /> : <ChevronDown size={16} className="text-gray-400" />}
          </button>
          {showExtensions && (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-gray-500 border-b border-gray-100">
                    <th className="px-4 py-2">Dahili</th>
                    <th className="px-4 py-2 text-center">Toplam</th>
                    <th className="px-4 py-2 text-center">Cevaplanan</th>
                    <th className="px-4 py-2 text-center">Cevapsiz</th>
                    <th className="px-4 py-2 text-center">Ort. Sure</th>
                    <th className="px-4 py-2 text-center">Top. Sure</th>
                  </tr>
                </thead>
                <tbody>
                  {extensions.map(ext => (
                    <tr
                      key={ext.extension}
                      onClick={() => { setExtensionFilter(ext.extension); setPage(1) }}
                      className="border-b border-gray-50 hover:bg-gray-50 cursor-pointer transition-colors"
                    >
                      <td className="px-4 py-2 font-medium">
                        {ext.agent_name ? (
                          <span>{ext.agent_name} <span className="text-gray-400 font-normal">({ext.extension})</span></span>
                        ) : ext.extension}
                      </td>
                      <td className="px-4 py-2 text-center">{ext.total_calls}</td>
                      <td className="px-4 py-2 text-center text-green-600">{ext.answered}</td>
                      <td className="px-4 py-2 text-center text-red-600">{ext.missed}</td>
                      <td className="px-4 py-2 text-center">{formatDuration(Math.round(Number(ext.avg_duration)))}</td>
                      <td className="px-4 py-2 text-center">{formatTotalDuration(Number(ext.total_duration))}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* Call List */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        {loading ? (
          <div className="flex justify-center py-16">
            <Loader2 size={24} className="animate-spin text-blue-600" />
          </div>
        ) : calls.length === 0 ? (
          <div className="text-center py-16 text-gray-400">
            <Phone size={40} className="mx-auto mb-3 opacity-30" />
            <p>{t('callsCommon.noCalls')}</p>
          </div>
        ) : (
          <div className="divide-y divide-gray-50">
            {calls.map(call => (
              <button
                key={call.id}
                onClick={() => navigate(`/calls/${call.id}`)}
                className="w-full text-left px-4 py-3 hover:bg-gray-50 transition-colors"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      {call.direction === 'inbound' ? (
                        <PhoneIncoming size={14} className="text-blue-500 shrink-0" />
                      ) : (
                        <PhoneOutgoing size={14} className="text-indigo-500 shrink-0" />
                      )}
                      <span className="text-sm font-medium text-gray-900">{call.caller}</span>
                      {call.extension && (
                        <span className="text-xs text-gray-400">
                          {call.agent_name ? `${call.agent_name} (${call.extension})` : `Dahili: ${call.extension}`}
                        </span>
                      )}
                      <span className={clsx('px-1.5 py-0.5 rounded text-[10px] font-medium', statusColors[call.status] || 'bg-gray-100 text-gray-600')}>
                        {statusLabels[call.status] || call.status}
                      </span>
                      {getIvrLabel(call.called) && (
                        <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-600">
                          {getIvrLabel(call.called)}
                        </span>
                      )}
                      {call.recording_url && (
                        <Volume2 size={12} className="text-blue-400" />
                      )}
                      {call.ai_sentiment && (
                        <span className={clsx('text-[10px] font-medium', sentimentColors[call.ai_sentiment])}>
                          {sentimentLabels[call.ai_sentiment]}
                        </span>
                      )}
                    </div>
                    {call.ai_summary ? (
                      <p className="text-sm text-gray-600 truncate mt-0.5">{call.ai_summary}</p>
                    ) : call.ai_category ? (
                      <p className="text-sm text-gray-500 mt-0.5">{categoryLabels[call.ai_category] || call.ai_category}</p>
                    ) : call.status === 'answered' && !call.transcribed_at ? (
                      <p className="text-xs text-gray-400 mt-0.5 italic">Transkript bekleniyor...</p>
                    ) : null}
                  </div>
                  <div className="text-right shrink-0">
                    <span className="text-xs text-gray-400 whitespace-nowrap">
                      {formatRelativeTime(call.started_at)}
                    </span>
                    {call.duration > 0 && (
                      <p className="text-xs text-gray-500 mt-0.5">{formatDuration(call.duration)}</p>
                    )}
                  </div>
                </div>
              </button>
            ))}
          </div>
        )}

        {/* Pagination */}
        {lastPage > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-gray-100">
            <span className="text-xs text-gray-500">{total.toLocaleString('tr-TR')} sonuc, sayfa {page}/{lastPage}</span>
            <div className="flex gap-1">
              <button
                onClick={() => setPage(p => Math.max(1, p - 1))}
                disabled={page <= 1}
                className="p-1.5 rounded hover:bg-gray-100 disabled:opacity-30"
              >
                <ChevronLeft size={16} />
              </button>
              <button
                onClick={() => setPage(p => Math.min(lastPage, p + 1))}
                disabled={page >= lastPage}
                className="p-1.5 rounded hover:bg-gray-100 disabled:opacity-30"
              >
                <ChevronRight size={16} />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
