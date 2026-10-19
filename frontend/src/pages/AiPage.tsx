import { useEffect, useState, useCallback, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import type { PaginatedResponse } from '@/types'
import { CheckCircle, XCircle, Pencil, Clock, BarChart3, Database, Search, Bot, Edit3, ExternalLink, MessageSquare } from 'lucide-react'
import clsx from 'clsx'
import { getChannelFilterLabel } from '@/lib/channelUtils'

interface TrainingData {
  id: number
  source_type: string
  channel_type: string | null
  question: string
  answer: string
  quality_score: number | null
  is_active: boolean
  used_count: number
  created_at: string
}

interface ResponseLog {
  id: number
  prompt: string
  ai_response: string
  final_response: string | null
  was_approved: boolean
  was_edited: boolean
  response_time_ms: number | null
  model_used: string | null
  user?: { name: string }
  created_at: string
}

interface TrainingCounts {
  total: number
  active: number
  inactive: number
  trendyol: number
  hepsiburada: number
  ticimax: number
  conversation: number
  manual: number
}

interface EditHistoryItem {
  id: number
  content: string
  ai_original_content: string
  sender_id: number | null
  created_at: string
  customer_question: string | null
  conversation: {
    id: number
    channel_id: number
    customer_id: number
    channel: { id: number; name: string; type: string } | null
    customer: { id: number; name: string } | null
  } | null
  sender: { id: number; name: string } | null
}

interface EditHistoryStats {
  total: number
  edited: number
  approved: number
  edit_rate: number
}

type Tab = 'training' | 'logs' | 'edits'
type EditFilter = 'all' | 'edited' | 'approved'

const sourceLabels: Record<string, string> = {
  trendyol: 'Trendyol',
  hepsiburada: 'Hepsiburada',
  ticimax: 'Ticimax',
  conversation: 'Otomatik',
  manual: 'Manuel',
}

const sourceBadge: Record<string, string> = {
  trendyol: 'bg-orange-100 text-orange-700',
  hepsiburada: 'bg-purple-100 text-purple-700',
  ticimax: 'bg-red-100 text-red-700',
  conversation: 'bg-blue-100 text-blue-700',
  manual: 'bg-indigo-100 text-indigo-700',
}

function getSenderLabel(channelType?: string | null): string {
  if (!channelType) return 'Sistem'
  if (channelType.startsWith('trendyol')) return 'Trendyol Paneli'
  if (channelType.startsWith('hepsiburada')) return 'HB Paneli'
  if (channelType.startsWith('ticimax')) return 'Ticimax Paneli'
  if (channelType.startsWith('instagram') || channelType.startsWith('facebook')) return 'Meta Paneli'
  return 'Sistem'
}

export default function AiPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>('training')

  // Training data state
  const [trainingData, setTrainingData] = useState<TrainingData[]>([])
  const [trainingPage, setTrainingPage] = useState(1)
  const [trainingLastPage, setTrainingLastPage] = useState(1)
  const [trainingTotal, setTrainingTotal] = useState(0)
  const [trainingLoading, setTrainingLoading] = useState(true)
  const [trainingLoadingMore, setTrainingLoadingMore] = useState(false)
  const [trainingSearch, setTrainingSearch] = useState('')
  const [sourceFilter, setSourceFilter] = useState('')
  const [activeFilter, setActiveFilter] = useState('')
  const [counts, setCounts] = useState<TrainingCounts | null>(null)

  // Response logs state
  const [logs, setLogs] = useState<ResponseLog[]>([])
  const [logsPage, setLogsPage] = useState(1)
  const [logsLastPage, setLogsLastPage] = useState(1)
  const [logsTotal, setLogsTotal] = useState(0)
  const [logsLoading, setLogsLoading] = useState(true)
  const [logsLoadingMore, setLogsLoadingMore] = useState(false)
  const [logsSearch, setLogsSearch] = useState('')

  // Edit history state
  const [editItems, setEditItems] = useState<EditHistoryItem[]>([])
  const [editPage, setEditPage] = useState(1)
  const [editLastPage, setEditLastPage] = useState(1)
  const [editTotal, setEditTotal] = useState(0)
  const [editLoading, setEditLoading] = useState(true)
  const [editLoadingMore, setEditLoadingMore] = useState(false)
  const [editSearch, setEditSearch] = useState('')
  const [editFilter, setEditFilter] = useState<EditFilter>('all')
  const [editStats, setEditStats] = useState<EditHistoryStats | null>(null)

  const trainingSentinelRef = useRef<HTMLDivElement>(null)
  const logsSentinelRef = useRef<HTMLDivElement>(null)
  const editSentinelRef = useRef<HTMLDivElement>(null)

  // Fetch training data counts
  const fetchCounts = useCallback(() => {
    const params: Record<string, string> = {}
    if (trainingSearch) params.search = trainingSearch
    api.get<TrainingCounts>('/ai/training-data/counts', { params }).then((res) => setCounts(res.data))
  }, [trainingSearch])

  useEffect(() => { fetchCounts() }, [fetchCounts])

  // Fetch training data
  const fetchTrainingData = useCallback(() => {
    setTrainingLoading(true)
    const params: Record<string, string> = { per_page: '30' }
    if (trainingSearch) params.search = trainingSearch
    if (sourceFilter) params.source_type = sourceFilter
    if (activeFilter) params.is_active = activeFilter

    api.get<PaginatedResponse<TrainingData>>('/ai/training-data', { params }).then((res) => {
      setTrainingData(res.data.data)
      setTrainingPage(res.data.current_page)
      setTrainingLastPage(res.data.last_page)
      setTrainingTotal(res.data.total)
      setTrainingLoading(false)
    })
  }, [trainingSearch, sourceFilter, activeFilter])

  useEffect(() => {
    if (tab === 'training') fetchTrainingData()
  }, [tab, fetchTrainingData])

  // Fetch more training data (infinite scroll)
  const fetchMoreTraining = useCallback(() => {
    if (trainingLoadingMore || trainingPage >= trainingLastPage) return
    setTrainingLoadingMore(true)
    const nextPage = trainingPage + 1
    const params: Record<string, string> = { per_page: '30', page: String(nextPage) }
    if (trainingSearch) params.search = trainingSearch
    if (sourceFilter) params.source_type = sourceFilter
    if (activeFilter) params.is_active = activeFilter

    api.get<PaginatedResponse<TrainingData>>('/ai/training-data', { params }).then((res) => {
      setTrainingData((prev) => [...prev, ...res.data.data])
      setTrainingPage(res.data.current_page)
      setTrainingLastPage(res.data.last_page)
      setTrainingLoadingMore(false)
    })
  }, [trainingPage, trainingLastPage, trainingLoadingMore, trainingSearch, sourceFilter, activeFilter])

  // Fetch response logs
  const fetchLogs = useCallback(() => {
    setLogsLoading(true)
    const params: Record<string, string> = { per_page: '30' }
    if (logsSearch) params.search = logsSearch

    api.get<PaginatedResponse<ResponseLog>>('/ai/response-logs', { params }).then((res) => {
      setLogs(res.data.data)
      setLogsPage(res.data.current_page)
      setLogsLastPage(res.data.last_page)
      setLogsTotal(res.data.total)
      setLogsLoading(false)
    })
  }, [logsSearch])

  useEffect(() => {
    if (tab === 'logs') fetchLogs()
  }, [tab, fetchLogs])

  // Fetch more logs (infinite scroll)
  const fetchMoreLogs = useCallback(() => {
    if (logsLoadingMore || logsPage >= logsLastPage) return
    setLogsLoadingMore(true)
    const nextPage = logsPage + 1
    const params: Record<string, string> = { per_page: '30', page: String(nextPage) }
    if (logsSearch) params.search = logsSearch

    api.get<PaginatedResponse<ResponseLog>>('/ai/response-logs', { params }).then((res) => {
      setLogs((prev) => [...prev, ...res.data.data])
      setLogsPage(res.data.current_page)
      setLogsLastPage(res.data.last_page)
      setLogsLoadingMore(false)
    })
  }, [logsPage, logsLastPage, logsLoadingMore, logsSearch])

  // Fetch edit history stats
  const fetchEditStats = useCallback(() => {
    api.get<EditHistoryStats>('/ai/edit-history/stats').then((res) => setEditStats(res.data))
  }, [])

  // Fetch edit history
  const fetchEditHistory = useCallback(() => {
    setEditLoading(true)
    const params: Record<string, string> = { per_page: '30', filter: editFilter }
    if (editSearch) params.search = editSearch

    api.get<PaginatedResponse<EditHistoryItem>>('/ai/edit-history', { params }).then((res) => {
      setEditItems(res.data.data)
      setEditPage(res.data.current_page)
      setEditLastPage(res.data.last_page)
      setEditTotal(res.data.total)
      setEditLoading(false)
    })
  }, [editSearch, editFilter])

  useEffect(() => {
    if (tab === 'edits') {
      fetchEditStats()
      fetchEditHistory()
    }
  }, [tab, fetchEditStats, fetchEditHistory])

  // Fetch more edit history (infinite scroll)
  const fetchMoreEdits = useCallback(() => {
    if (editLoadingMore || editPage >= editLastPage) return
    setEditLoadingMore(true)
    const nextPage = editPage + 1
    const params: Record<string, string> = { per_page: '30', page: String(nextPage), filter: editFilter }
    if (editSearch) params.search = editSearch

    api.get<PaginatedResponse<EditHistoryItem>>('/ai/edit-history', { params }).then((res) => {
      setEditItems((prev) => [...prev, ...res.data.data])
      setEditPage(res.data.current_page)
      setEditLastPage(res.data.last_page)
      setEditLoadingMore(false)
    })
  }, [editPage, editLastPage, editLoadingMore, editSearch, editFilter])

  // IntersectionObserver for training data
  useEffect(() => {
    if (tab !== 'training' || trainingLoading) return
    const el = trainingSentinelRef.current
    if (!el) return
    const obs = new IntersectionObserver(
      ([entry]) => { if (entry.isIntersecting) fetchMoreTraining() },
      { rootMargin: '200px' }
    )
    obs.observe(el)
    return () => obs.disconnect()
  }, [tab, trainingLoading, fetchMoreTraining])

  // IntersectionObserver for logs
  useEffect(() => {
    if (tab !== 'logs' || logsLoading) return
    const el = logsSentinelRef.current
    if (!el) return
    const obs = new IntersectionObserver(
      ([entry]) => { if (entry.isIntersecting) fetchMoreLogs() },
      { rootMargin: '200px' }
    )
    obs.observe(el)
    return () => obs.disconnect()
  }, [tab, logsLoading, fetchMoreLogs])

  // IntersectionObserver for edit history
  useEffect(() => {
    if (tab !== 'edits' || editLoading) return
    const el = editSentinelRef.current
    if (!el) return
    const obs = new IntersectionObserver(
      ([entry]) => { if (entry.isIntersecting) fetchMoreEdits() },
      { rootMargin: '200px' }
    )
    obs.observe(el)
    return () => obs.disconnect()
  }, [tab, editLoading, fetchMoreEdits])

  const toggleActive = async (item: TrainingData) => {
    const res = await api.patch(`/ai/training-data/${item.id}`, { is_active: !item.is_active })
    setTrainingData((prev) => prev.map((td) => (td.id === item.id ? res.data : td)))
    fetchCounts()
  }

  const formatScore = (score: number | null) => {
    if (score === null) return '-'
    return (score * 100).toFixed(0) + '%'
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-extrabold text-gray-900">{t('aiCommon.title')}</h1>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 bg-white shadow-sm border border-gray-100 p-1 rounded-full w-fit max-w-full overflow-x-auto">
        <button
          onClick={() => setTab('training')}
          className={clsx(
            'flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-full transition-colors',
            tab === 'training' ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]' : 'text-gray-500 hover:text-gray-700'
          )}
        >
          <Database size={16} /> {t('aiCommon.trainingData')}
          {counts && <span className="text-xs text-gray-400 ml-1">({counts.total.toLocaleString('tr-TR')})</span>}
        </button>
        <button
          onClick={() => setTab('logs')}
          className={clsx(
            'flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-full transition-colors',
            tab === 'logs' ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]' : 'text-gray-500 hover:text-gray-700'
          )}
        >
          <BarChart3 size={16} /> {t('aiCommon.responseLogs')}
          {logsTotal > 0 && <span className="text-xs text-gray-400 ml-1">({logsTotal.toLocaleString('tr-TR')})</span>}
        </button>
        <button
          onClick={() => setTab('edits')}
          className={clsx(
            'flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-full transition-colors',
            tab === 'edits' ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]' : 'text-gray-500 hover:text-gray-700'
          )}
        >
          <Edit3 size={16} /> {t('aiCommon.editHistory')}
          {editStats && <span className="text-xs text-gray-400 ml-1">({editStats.total})</span>}
        </button>
      </div>

      {tab === 'training' ? (
        <>
          {/* Training data filters */}
          <div className="flex gap-3 flex-wrap">
            <div className="relative flex-1 min-w-[200px]">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
              <input
                type="text"
                value={trainingSearch}
                onChange={(e) => setTrainingSearch(e.target.value)}
                placeholder="Soru veya cevapta ara..."
                className="w-full pl-9 pr-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <select
              value={sourceFilter}
              onChange={(e) => setSourceFilter(e.target.value)}
              className="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm"
            >
              <option value="">Tum Kaynaklar</option>
              <option value="trendyol">Trendyol ({counts?.trendyol?.toLocaleString('tr-TR') ?? 0})</option>
              <option value="hepsiburada">Hepsiburada ({counts?.hepsiburada?.toLocaleString('tr-TR') ?? 0})</option>
              <option value="ticimax">Ticimax ({counts?.ticimax?.toLocaleString('tr-TR') ?? 0})</option>
              <option value="conversation">Otomatik ({counts?.conversation?.toLocaleString('tr-TR') ?? 0})</option>
              <option value="manual">Manuel ({counts?.manual?.toLocaleString('tr-TR') ?? 0})</option>
            </select>
            <select
              value={activeFilter}
              onChange={(e) => setActiveFilter(e.target.value)}
              className="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm"
            >
              <option value="">Tumu ({counts?.total?.toLocaleString('tr-TR') ?? 0})</option>
              <option value="true">Aktif ({counts?.active?.toLocaleString('tr-TR') ?? 0})</option>
              <option value="false">Pasif ({counts?.inactive?.toLocaleString('tr-TR') ?? 0})</option>
            </select>
          </div>

          {/* Result count */}
          <div className="text-sm text-gray-500">
            {trainingTotal.toLocaleString('tr-TR')} kayit bulundu
          </div>

          {/* Training data list */}
          {trainingLoading ? (
            <div className="flex justify-center py-20">
              <div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
            </div>
          ) : trainingData.length === 0 ? (
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-10 text-center text-gray-400">
              <Database className="mx-auto mb-2" size={32} />
              <p>Egitim verisi bulunamadi</p>
            </div>
          ) : (
            <div className="space-y-3">
              {trainingData.map((item) => (
                <div key={item.id} className={clsx(
                  'bg-white rounded-xl shadow-sm border p-5',
                  item.is_active ? 'border-gray-100' : 'border-gray-200 opacity-60'
                )}>
                  <div className="flex items-start justify-between mb-3">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className={clsx(
                        'text-xs px-2 py-0.5 rounded-full font-medium',
                        sourceBadge[item.source_type] ?? 'bg-gray-100 text-gray-600'
                      )}>
                        {sourceLabels[item.source_type] ?? item.source_type}
                      </span>
                      {item.channel_type && (
                        <span className="text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">
                          {getChannelFilterLabel(item.channel_type)}
                        </span>
                      )}
                      {item.quality_score !== null && (
                        <span className={clsx(
                          'text-xs px-2 py-0.5 rounded-full',
                          item.quality_score >= 0.9 ? 'bg-green-100 text-green-700' :
                          item.quality_score >= 0.8 ? 'bg-yellow-100 text-yellow-700' :
                          'bg-gray-100 text-gray-600'
                        )}>
                          Skor: {formatScore(item.quality_score)}
                        </span>
                      )}
                      {item.used_count > 0 && (
                        <span className="text-xs text-gray-400">{item.used_count}x kullanildi</span>
                      )}
                    </div>
                    <button
                      onClick={() => toggleActive(item)}
                      className={clsx(
                        'text-xs px-2.5 py-1 rounded-full font-medium transition-colors shrink-0',
                        item.is_active ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'
                      )}
                    >
                      {item.is_active ? 'Aktif' : 'Pasif'}
                    </button>
                  </div>
                  <div className="space-y-2">
                    <div className="bg-gray-50 rounded-lg p-3">
                      <p className="text-xs text-gray-500 mb-1 font-medium">Soru</p>
                      <p className="text-sm whitespace-pre-line">{item.question}</p>
                    </div>
                    <div className="bg-indigo-50 rounded-lg p-3">
                      <p className="text-xs text-indigo-500 mb-1 font-medium">Cevap</p>
                      <p className="text-sm whitespace-pre-line">{item.answer}</p>
                    </div>
                  </div>
                </div>
              ))}
              <div ref={trainingSentinelRef} />
              {trainingLoadingMore && (
                <div className="flex justify-center py-4">
                  <div className="w-6 h-6 border-3 border-indigo-600 border-t-transparent rounded-full animate-spin" />
                </div>
              )}
            </div>
          )}
        </>
      ) : tab === 'logs' ? (
        <>
          {/* Response logs search */}
          <div className="flex gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
              <input
                type="text"
                value={logsSearch}
                onChange={(e) => setLogsSearch(e.target.value)}
                placeholder="Prompt veya yanıtta ara..."
                className="w-full pl-9 pr-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
          </div>

          <div className="text-sm text-gray-500">
            {logsTotal.toLocaleString('tr-TR')} kayit bulundu
          </div>

          {/* Response logs list */}
          {logsLoading ? (
            <div className="flex justify-center py-20">
              <div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
            </div>
          ) : logs.length === 0 ? (
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-10 text-center text-gray-400">
              <BarChart3 className="mx-auto mb-2" size={32} />
              <p>Yanit logu bulunamadi</p>
            </div>
          ) : (
            <div className="space-y-3">
              {logs.map((log) => (
                <div key={log.id} className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                  <div className="flex items-center justify-between mb-3">
                    <div className="flex items-center gap-2">
                      {log.was_approved ? (
                        <CheckCircle size={16} className="text-green-500" />
                      ) : (
                        <XCircle size={16} className="text-red-400" />
                      )}
                      <span className="text-xs font-medium">
                        {log.was_approved ? 'Onaylandi' : 'Reddedildi'}
                        {log.was_edited && ' (Duzenlendi)'}
                      </span>
                      {log.model_used && <span className="text-xs text-gray-400">{log.model_used}</span>}
                    </div>
                    <div className="flex items-center gap-2 text-xs text-gray-500">
                      {log.response_time_ms && (
                        <span className="flex items-center gap-1">
                          <Clock size={12} /> {(log.response_time_ms / 1000).toFixed(1)}s
                        </span>
                      )}
                      <span>{log.user?.name}</span>
                      <span>{new Date(log.created_at).toLocaleString('tr-TR')}</span>
                    </div>
                  </div>
                  <div className="space-y-2">
                    <div className="bg-gray-50 rounded-lg p-3">
                      <p className="text-xs text-gray-500 mb-1 font-medium">Prompt</p>
                      <p className="text-sm line-clamp-2">{log.prompt}</p>
                    </div>
                    <div className="bg-indigo-50 rounded-lg p-3">
                      <p className="text-xs text-indigo-500 mb-1 font-medium">AI Yaniti</p>
                      <p className="text-sm line-clamp-3">{log.ai_response}</p>
                    </div>
                    {log.final_response && log.was_edited && (
                      <div className="bg-green-50 rounded-lg p-3">
                        <p className="text-xs text-green-600 mb-1 font-medium flex items-center gap-1"><Pencil size={10} /> Duzenlenmis Hali</p>
                        <p className="text-sm line-clamp-3">{log.final_response}</p>
                      </div>
                    )}
                  </div>
                </div>
              ))}
              <div ref={logsSentinelRef} />
              {logsLoadingMore && (
                <div className="flex justify-center py-4">
                  <div className="w-6 h-6 border-3 border-indigo-600 border-t-transparent rounded-full animate-spin" />
                </div>
              )}
            </div>
          )}
        </>
      ) : (
        <>
          {/* Edit History Stats */}
          {editStats && (
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
              <div className="bg-white rounded-xl p-4 shadow-sm border border-gray-100 flex items-center gap-3">
                <div className="w-2 h-10 rounded-full bg-indigo-500" />
                <div><p className="text-xl font-bold">{editStats.total}</p><p className="text-xs text-gray-500">Toplam AI Mesaj</p></div>
              </div>
              <div className="bg-white rounded-xl p-4 shadow-sm border border-gray-100 flex items-center gap-3">
                <div className="w-2 h-10 rounded-full bg-green-500" />
                <div><p className="text-xl font-bold">{editStats.approved}</p><p className="text-xs text-gray-500">Degistirilmeden</p></div>
              </div>
              <div className="bg-white rounded-xl p-4 shadow-sm border border-gray-100 flex items-center gap-3">
                <div className="w-2 h-10 rounded-full bg-amber-500" />
                <div><p className="text-xl font-bold">{editStats.edited}</p><p className="text-xs text-gray-500">Duzenlenen</p></div>
              </div>
              <div className="bg-white rounded-xl p-4 shadow-sm border border-gray-100 flex items-center gap-3">
                <div className="w-2 h-10 rounded-full bg-blue-500" />
                <div><p className="text-xl font-bold">%{editStats.edit_rate}</p><p className="text-xs text-gray-500">Duzenleme Orani</p></div>
              </div>
            </div>
          )}

          {/* Edit History Filters */}
          <div className="flex gap-3 flex-wrap items-center">
            <div className="flex gap-1 bg-white shadow-sm border border-gray-100 p-1 rounded-full w-fit max-w-full overflow-x-auto">
              {([
                { value: 'all' as EditFilter, label: 'Tumu', count: editStats?.total },
                { value: 'edited' as EditFilter, label: 'Duzenlenen', count: editStats?.edited },
                { value: 'approved' as EditFilter, label: 'Degistirilmeden', count: editStats?.approved },
              ]).map((opt) => (
                <button
                  key={opt.value}
                  onClick={() => { setEditFilter(opt.value); setEditItems([]); }}
                  className={clsx(
                    'px-3 py-1.5 text-sm font-medium rounded-md transition-colors',
                    editFilter === opt.value ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]' : 'text-gray-500 hover:text-gray-700'
                  )}
                >
                  {opt.label} {opt.count != null && <span className="text-xs text-gray-400">({opt.count})</span>}
                </button>
              ))}
            </div>
            <div className="relative flex-1 min-w-[200px]">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
              <input
                type="text"
                value={editSearch}
                onChange={(e) => { setEditSearch(e.target.value); setEditItems([]) }}
                placeholder="Mesaj iceriginde ara..."
                className="w-full pl-9 pr-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
          </div>

          <div className="text-sm text-gray-500">{editTotal.toLocaleString('tr-TR')} kayit bulundu</div>

          {/* Edit History List */}
          {editLoading ? (
            <div className="flex justify-center py-20">
              <div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
            </div>
          ) : editItems.length === 0 ? (
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-10 text-center text-gray-400">
              <Edit3 className="mx-auto mb-2" size={32} />
              <p>Sonuc bulunamadi</p>
            </div>
          ) : (
            <div className="space-y-4">
              {editItems.map((item) => {
                const edited = item.content !== item.ai_original_content
                return (
                  <div key={item.id} className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    {/* Header */}
                    <div className="flex items-center justify-between mb-3">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className={clsx(
                          'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium',
                          edited ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'
                        )}>
                          {edited ? <><Edit3 size={10} /> Duzenlendi</> : <><CheckCircle size={10} /> Oldugu gibi</>}
                        </span>
                        <span className="text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">
                          {item.conversation?.channel?.name}
                        </span>
                        <span className="text-sm font-medium text-gray-900">
                          {item.conversation?.customer?.name ?? 'Bilinmeyen'}
                        </span>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="text-xs text-gray-500">
                          {item.sender?.name ?? getSenderLabel(item.conversation?.channel?.type)}
                        </span>
                        <span className="text-xs text-gray-400">
                          {new Date(item.created_at).toLocaleString('tr-TR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                        </span>
                        <a
                          href={`/messages?conversation=${item.conversation?.id}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                        >
                          <ExternalLink size={12} /> Mesaj Gecmisi
                        </a>
                      </div>
                    </div>

                    {/* Content */}
                    <div className="space-y-2">
                      {/* Customer Question */}
                      {item.customer_question && (
                        <div className="bg-gray-50 rounded-lg p-3">
                          <p className="text-xs text-gray-500 mb-1 font-medium flex items-center gap-1">
                            <MessageSquare size={10} /> Musteri Sorusu
                          </p>
                          <p className="text-sm whitespace-pre-line">{item.customer_question}</p>
                        </div>
                      )}

                      {/* AI Generated */}
                      <div className={clsx('rounded-lg p-3', edited ? 'bg-red-50' : 'bg-green-50')}>
                        <p className={clsx('text-xs mb-1 font-medium flex items-center gap-1', edited ? 'text-red-500' : 'text-green-600')}>
                          <Bot size={10} /> AI Urettigi Cevap
                        </p>
                        <p className="text-sm whitespace-pre-line">{item.ai_original_content}</p>
                      </div>

                      {/* Sent Version (only if edited) */}
                      {edited && (
                        <div className="bg-green-50 rounded-lg p-3">
                          <p className="text-xs text-green-600 mb-1 font-medium flex items-center gap-1">
                            <Pencil size={10} /> Gonderilen Cevap
                            <span className="text-gray-400 font-normal">({item.sender?.name ?? getSenderLabel(item.conversation?.channel?.type)})</span>
                          </p>
                          <p className="text-sm whitespace-pre-line">{item.content}</p>
                        </div>
                      )}
                    </div>
                  </div>
                )
              })}
              <div ref={editSentinelRef} />
              {editLoadingMore && (
                <div className="flex justify-center py-4">
                  <div className="w-6 h-6 border-3 border-indigo-600 border-t-transparent rounded-full animate-spin" />
                </div>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}
