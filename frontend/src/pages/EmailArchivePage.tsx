import { useEffect, useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { Mail, Search, Tag, ArrowRight, Loader2, ChevronLeft, ChevronRight } from 'lucide-react'
import clsx from 'clsx'
import { formatRelativeTime } from '@/lib/channelUtils'

interface EmailMessage {
  id: number
  from_email: string
  from_name: string | null
  to_email: string
  subject: string | null
  body_text: string | null
  ai_category: string
  conversation_id: number | null
  created_at: string
}

interface Counts {
  total: number
  customer_request: number
  outgoing_reply: number
  filtered: number
  other: number
  notification: number
  spam: number
  auto_reply: number
}

const categoryColors: Record<string, string> = {
  customer_request: 'bg-green-100 text-green-700',
  outgoing_reply: 'bg-indigo-100 text-indigo-700',
  other: 'bg-gray-100 text-gray-700',
  notification: 'bg-blue-100 text-blue-700',
  spam: 'bg-red-100 text-red-700',
  auto_reply: 'bg-yellow-100 text-yellow-700',
}

type Tab = 'all' | 'customer_request' | 'outgoing_reply' | 'other' | 'notification' | 'spam' | 'auto_reply'

export default function EmailArchivePage() {
  const { t, i18n } = useTranslation()
  const [emails, setEmails] = useState<EmailMessage[]>([])
  const [counts, setCounts] = useState<Counts | null>(null)
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<Tab>('all')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [selected, setSelected] = useState<EmailMessage | null>(null)
  const [reclassifying, setReclassifying] = useState(false)

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  const categoryLabels: Record<string, string> = {
    all: t('email.all'),
    customer_request: t('email.customerRequest'),
    outgoing_reply: t('email.outgoingReply'),
    other: t('email.other'),
    notification: t('email.notification'),
    spam: t('email.spam'),
    auto_reply: t('email.autoReply'),
  }

  const fetchEmails = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = { page: String(page) }
      if (tab !== 'all') params.category = tab
      else params.category = 'all'
      if (search) params.search = search
      const res = await api.get('/email-messages', { params })
      setEmails(res.data.data)
      setLastPage(res.data.last_page)
      setTotal(res.data.total)
    } finally {
      setLoading(false)
    }
  }, [tab, search, page])

  const fetchCounts = useCallback(async () => {
    const res = await api.get('/email-messages/counts')
    setCounts(res.data)
  }, [])

  useEffect(() => { fetchCounts() }, [fetchCounts])
  useEffect(() => { fetchEmails() }, [fetchEmails])

  const reclassify = async (emailId: number, newCategory: string) => {
    setReclassifying(true)
    try {
      await api.patch(`/email-messages/${emailId}/reclassify`, { ai_category: newCategory })
      await Promise.all([fetchEmails(), fetchCounts()])
      if (selected?.id === emailId) {
        setSelected(prev => prev ? { ...prev, ai_category: newCategory } : null)
      }
    } finally {
      setReclassifying(false)
    }
  }

  const tabs: { key: Tab; label: string; count: number }[] = [
    { key: 'all', label: t('email.all'), count: counts?.total ?? 0 },
    { key: 'customer_request', label: t('email.customerRequest'), count: counts?.customer_request ?? 0 },
    { key: 'outgoing_reply', label: t('email.outgoingReply'), count: counts?.outgoing_reply ?? 0 },
    { key: 'other', label: t('email.other'), count: counts?.other ?? 0 },
    { key: 'notification', label: t('email.notification'), count: counts?.notification ?? 0 },
    { key: 'spam', label: t('email.spam'), count: counts?.spam ?? 0 },
    { key: 'auto_reply', label: t('email.autoReplyShort'), count: counts?.auto_reply ?? 0 },
  ]

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Mail size={24} className="text-blue-600" />
          <h1 className="text-2xl font-extrabold text-gray-900">{t('email.title')}</h1>
        </div>
        <span className="text-sm text-gray-500">{counts?.total ?? 0} {t('email.emails')}</span>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 bg-white shadow-sm border border-gray-100 p-1 rounded-full overflow-x-auto w-fit max-w-full">
        {tabs.map(tb => (
          <button
            key={tb.key}
            onClick={() => { setTab(tb.key); setPage(1) }}
            className={clsx(
              'px-3.5 py-1.5 rounded-full text-sm font-semibold whitespace-nowrap transition-colors',
              tab === tb.key ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]' : 'text-gray-500 hover:text-gray-700'
            )}
          >
            {tb.label}
            <span className="ml-1.5 text-xs text-gray-400">({tb.count})</span>
          </button>
        ))}
      </div>

      {/* Search */}
      <div className="relative">
        <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
        <input
          type="text"
          placeholder={t('email.searchPlaceholder')}
          value={search}
          onChange={e => { setSearch(e.target.value); setPage(1) }}
          className="w-full pl-9 pr-4 py-2 bg-white border border-transparent shadow-sm rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] transition-colors"
        />
      </div>

      <div className="flex gap-4">
        {/* Email list */}
        <div className="flex-1 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
          {loading ? (
            <div className="flex justify-center py-16">
              <Loader2 size={24} className="animate-spin text-blue-600" />
            </div>
          ) : emails.length === 0 ? (
            <div className="text-center py-16 text-gray-400">
              <Mail size={40} className="mx-auto mb-3 opacity-30" />
              <p>{t('email.noEmails')}</p>
            </div>
          ) : (
            <div className="divide-y divide-gray-50">
              {emails.map(em => (
                <button
                  key={em.id}
                  onClick={() => setSelected(em)}
                  className={clsx(
                    'w-full text-left px-4 py-3 hover:bg-gray-50 transition-colors',
                    selected?.id === em.id && 'bg-blue-50'
                  )}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium text-gray-900 truncate">
                          {em.from_name || em.from_email}
                        </span>
                        <span className={clsx('px-2 py-0.5 rounded-full text-[10px] font-semibold', categoryColors[em.ai_category] || 'bg-gray-100 text-gray-600')}>
                          {categoryLabels[em.ai_category] || em.ai_category}
                        </span>
                      </div>
                      <p className="text-sm text-gray-600 truncate mt-0.5">
                        {em.subject || t('email.noSubject')}
                      </p>
                      {em.from_name && (
                        <p className="text-xs text-gray-400 truncate">{em.from_email}</p>
                      )}
                    </div>
                    <span className="text-xs text-gray-400 whitespace-nowrap shrink-0">
                      {formatRelativeTime(em.created_at)}
                    </span>
                  </div>
                </button>
              ))}
            </div>
          )}

          {/* Pagination */}
          {lastPage > 1 && (
            <div className="flex items-center justify-between px-4 py-3 border-t border-gray-100">
              <span className="text-xs text-gray-500">{total} {t('email.results', { page, lastPage })}</span>
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

        {/* Detail panel */}
        {selected && (
          <div className="w-[420px] bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col max-h-[calc(100vh-220px)] shrink-0">
            <div className="px-4 py-3 border-b border-gray-100">
              <h3 className="font-semibold text-gray-900 text-sm truncate">
                {selected.subject || t('email.noSubject')}
              </h3>
              <p className="text-xs text-gray-500 mt-1">
                {selected.from_name && <span className="font-medium">{selected.from_name}</span>}
                {' '}&lt;{selected.from_email}&gt;
              </p>
              <p className="text-xs text-gray-400 mt-0.5">
                {new Date(selected.created_at).toLocaleString(locale)}
              </p>

              {/* Category + reclassify */}
              <div className="flex items-center gap-2 mt-3">
                <Tag size={12} className="text-gray-400" />
                <span className="text-xs text-gray-500">{t('email.category')}</span>
                <select
                  value={selected.ai_category}
                  onChange={e => reclassify(selected.id, e.target.value)}
                  disabled={reclassifying}
                  className="text-xs border border-gray-200 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="customer_request">{t('email.customerRequest')}</option>
                  <option value="outgoing_reply">{t('email.outgoingReply')}</option>
                  <option value="other">{t('email.other')}</option>
                  <option value="notification">{t('email.notification')}</option>
                  <option value="spam">{t('email.spam')}</option>
                  <option value="auto_reply">{t('email.autoReplyShort')}</option>
                </select>
                {reclassifying && <Loader2 size={12} className="animate-spin text-blue-500" />}
                {selected.ai_category !== 'customer_request' && (
                  <button
                    onClick={() => reclassify(selected.id, 'customer_request')}
                    disabled={reclassifying}
                    className="flex items-center gap-1 px-2 py-1 bg-green-50 text-green-700 hover:bg-green-100 rounded text-xs font-medium transition-colors disabled:opacity-50"
                  >
                    <ArrowRight size={10} />
                    {t('email.transferToMessages')}
                  </button>
                )}
              </div>
            </div>
            <div className="flex-1 overflow-y-auto px-4 py-3">
              <pre className="text-sm text-gray-700 whitespace-pre-wrap font-sans leading-relaxed">
                {selected.body_text || t('email.noContent')}
              </pre>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
