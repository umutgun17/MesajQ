import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import api from '@/lib/api'
import { Activity, ChevronDown, ChevronRight, Clock, LogIn, MonitorSmartphone, RefreshCw } from 'lucide-react'
import clsx from 'clsx'

interface SummaryRow {
  id: number; name: string; email: string; role: string; status: string; is_super_admin: boolean
  created_at: string | null; first_login_at: string | null; last_login_at: string | null; login_count: number
  last_seen_at: string | null; sessions: number; page_views: number; active_minutes: number
}
interface Login { at: string; kind: string; ip: string | null; device: string }
interface PageHit { page: string; at: string; seconds: number | null }
interface Session { start: string; end: string; minutes: number; ip: string | null; pages: PageHit[] }
interface Detail {
  days: number; user: { id: number; name: string; email: string }
  logins: Login[]; sessions: Session[]; top_pages: { page: string; views: number }[]; truncated: boolean
}

// SPA yolu → okunur ad (nav çevirileri); bulunamazsa yol gösterilir
const PAGE_LABELS: Record<string, string> = {
  '/': 'nav.dashboard', '/messages/dashboard': 'nav.dashboard', '/messages': 'nav.messages', '/comments': 'nav.comments',
  '/email-archive': 'nav.emailArchive', '/calls': 'nav.callAnalysis', '/returns': 'nav.returnsManage',
  '/reports/messages': 'nav.messageAnalysis', '/settings/channels': 'nav.channels', '/settings/general': 'nav.general',
  '/settings/users': 'nav.users', '/settings/users/activity': 'userActivity.title', '/settings/knowledge-base': 'nav.knowledgeBase',
  '/settings/ai': 'nav.ai', '/settings/ai-reply': 'nav.aiReply', '/profile': 'nav.myProfile', '/tenants': 'nav.tenants',
  '/crm/products': 'nav.products', '/crm/customers': 'nav.customers', '/crm/orders': 'nav.orders', '/crm/reviews': 'nav.reviews',
  '/crm/favorites': 'nav.favorites', '/cro': 'nav.cro', '/seo': 'nav.seo', '/social': 'nav.social', '/wheel': 'nav.wheel',
  '/chatbot': 'nav.chatbot', '/ads/meta': 'nav.metaAds', '/analytics/overview': 'nav.analytics',
}

function fmt(iso: string | null | undefined, locale: string, withTime = true) {
  if (!iso) return '—'
  const d = new Date(iso)
  return withTime
    ? d.toLocaleString(locale, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
    : d.toLocaleDateString(locale, { day: '2-digit', month: 'short' })
}
function fmtDur(min: number, t: (k: string, o?: Record<string, unknown>) => string) {
  if (min < 60) return t('userActivity.min', { n: min })
  return t('userActivity.hourMin', { h: Math.floor(min / 60), m: min % 60 })
}
function fmtSec(sec: number | null, t: (k: string, o?: Record<string, unknown>) => string) {
  if (sec === null) return ''
  if (sec < 60) return t('userActivity.sec', { n: sec })
  return t('userActivity.min', { n: Math.round(sec / 60) })
}
function ago(iso: string | null, t: (k: string, o?: Record<string, unknown>) => string) {
  if (!iso) return ''
  const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (m < 60) return t('userActivity.agoMin', { n: m })
  if (m < 60 * 48) return t('userActivity.agoHour', { n: Math.round(m / 60) })
  return t('userActivity.agoDay', { n: Math.round(m / 1440) })
}

export default function UserActivityPage() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'en' ? 'en-GB' : 'tr-TR'
  const [params, setParams] = useSearchParams()
  const [days, setDays] = useState(30)
  const [rows, setRows] = useState<SummaryRow[]>([])
  const [loading, setLoading] = useState(true)
  const selectedId = Number(params.get('user')) || null
  const [detail, setDetail] = useState<Detail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [openSessions, setOpenSessions] = useState<Record<number, boolean>>({ 0: true })

  const pageLabel = (p: string) => {
    const key = PAGE_LABELS[p]
    if (key && i18n.exists(key)) return t(key)
    // /crm/orders/{id} → üst yolun adı + /{id}
    const parent = p.replace(/\/\{id\}$/, '')
    const pk = PAGE_LABELS[parent]
    if (parent !== p && pk && i18n.exists(pk)) return `${t(pk)} › ${t('userActivity.detail')}`
    return p
  }

  const load = async () => {
    setLoading(true)
    try {
      const { data } = await api.get('/users/activity/summary', { params: { days } })
      setRows(data.users)
    } finally { setLoading(false) }
  }
  useEffect(() => { load() }, [days]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!selectedId) { setDetail(null); return }
    setDetailLoading(true); setOpenSessions({ 0: true })
    api.get(`/users/${selectedId}/activity`, { params: { days } })
      .then(({ data }) => setDetail(data))
      .finally(() => setDetailLoading(false))
  }, [selectedId, days])

  const selectedRow = useMemo(() => rows.find((r) => r.id === selectedId), [rows, selectedId])

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <div className="flex items-start justify-between gap-4 mb-6 flex-wrap">
        <div>
          <h1 className="text-2xl font-semibold flex items-center gap-2"><Activity size={22} /> {t('userActivity.title')}</h1>
          <p className="text-sm text-gray-500 mt-1">{t('userActivity.subtitle')}</p>
        </div>
        <div className="flex items-center gap-2">
          <select value={days} onChange={(e) => setDays(Number(e.target.value))} className="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
            {[7, 30, 90].map((d) => <option key={d} value={d}>{t('userActivity.lastDays', { n: d })}</option>)}
          </select>
          <button onClick={load} className="p-2 text-gray-500 hover:text-indigo-600 rounded-lg hover:bg-gray-100" title={t('userActivity.refresh')}>
            <RefreshCw size={16} className={clsx(loading && 'animate-spin')} />
          </button>
        </div>
      </div>

      <div className={clsx('grid gap-6', selectedId ? 'lg:grid-cols-5' : 'grid-cols-1')}>
        {/* Özet tablo */}
        <div className={clsx('bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden', selectedId && 'lg:col-span-2')}>
          <table className="w-full">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">{t('users.user')}</th>
                {!selectedId && <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">{t('userActivity.firstLogin')}</th>}
                <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">{t('userActivity.lastSeen')}</th>
                {!selectedId && <th className="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">{t('userActivity.logins')}</th>}
                <th className="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">{t('userActivity.activeTime')}</th>
                {!selectedId && <th className="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">{t('userActivity.pageViews')}</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {rows.map((r) => (
                <tr key={r.id} onClick={() => setParams(r.id === selectedId ? {} : { user: String(r.id) })}
                  className={clsx('cursor-pointer hover:bg-gray-50', r.id === selectedId && 'bg-indigo-50/60')}>
                  <td className="px-4 py-3">
                    <p className="text-sm font-medium">{r.name}{r.is_super_admin && <span className="ml-1 text-[10px] text-violet-600">SA</span>}</p>
                    <p className="text-xs text-gray-500">{r.email}</p>
                  </td>
                  {!selectedId && <td className="px-4 py-3 text-sm text-gray-600">{fmt(r.first_login_at, locale)}</td>}
                  <td className="px-4 py-3">
                    <p className="text-sm text-gray-700">{fmt(r.last_seen_at ?? r.last_login_at, locale)}</p>
                    <p className="text-xs text-gray-400">{ago(r.last_seen_at ?? r.last_login_at, t)}</p>
                  </td>
                  {!selectedId && <td className="px-4 py-3 text-sm text-right text-gray-700">{r.login_count}</td>}
                  <td className="px-4 py-3 text-sm text-right text-gray-700">
                    {r.active_minutes ? fmtDur(r.active_minutes, t) : '—'}
                    {r.sessions > 0 && <p className="text-xs text-gray-400">{t('userActivity.sessionsN', { n: r.sessions })}</p>}
                  </td>
                  {!selectedId && <td className="px-4 py-3 text-sm text-right text-gray-700">{r.page_views || '—'}</td>}
                </tr>
              ))}
              {!loading && rows.length === 0 && (
                <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400">{t('users.noUsers')}</td></tr>
              )}
            </tbody>
          </table>
          <p className="px-4 py-2 text-[11px] text-gray-400 border-t border-gray-100">{t('userActivity.footnote')}</p>
        </div>

        {/* Detay */}
        {selectedId && (
          <div className="lg:col-span-3 space-y-4">
            {detailLoading || !detail ? (
              <div className="bg-white rounded-xl border border-gray-100 p-10 text-center text-gray-400">{t('common.loading')}</div>
            ) : (
              <>
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                  <Stat label={t('userActivity.accountCreated')} value={fmt(selectedRow?.created_at, locale, false)} />
                  <Stat label={t('userActivity.firstLogin')} value={fmt(selectedRow?.first_login_at, locale)} />
                  <Stat label={t('userActivity.lastLogin')} value={fmt(selectedRow?.last_login_at, locale)} />
                  <Stat label={t('userActivity.activeTime')} value={selectedRow?.active_minutes ? fmtDur(selectedRow.active_minutes, t) : '—'} />
                </div>

                {detail.top_pages.length > 0 && (
                  <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                    <h3 className="text-sm font-semibold text-gray-700 mb-2">{t('userActivity.topPages')}</h3>
                    <div className="flex flex-wrap gap-1.5">
                      {detail.top_pages.map((p) => (
                        <span key={p.page} className="text-xs bg-gray-50 border border-gray-100 rounded-md px-2 py-1" title={p.page}>
                          {pageLabel(p.page)} <span className="text-gray-400">×{p.views}</span>
                        </span>
                      ))}
                    </div>
                  </div>
                )}

                <div className="bg-white rounded-xl shadow-sm border border-gray-100">
                  <h3 className="text-sm font-semibold text-gray-700 px-4 py-3 border-b border-gray-100 flex items-center gap-2">
                    <Clock size={14} /> {t('userActivity.sessions')} <span className="text-gray-400 font-normal">({detail.sessions.length})</span>
                  </h3>
                  {detail.sessions.length === 0 && <p className="px-4 py-8 text-center text-gray-400 text-sm">{t('userActivity.noActivity')}</p>}
                  <div className="divide-y divide-gray-100">
                    {detail.sessions.map((s, i) => {
                      const open = !!openSessions[i]
                      return (
                        <div key={s.start}>
                          <button onClick={() => setOpenSessions((o) => ({ ...o, [i]: !open }))}
                            className="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-gray-50">
                            {open ? <ChevronDown size={14} className="text-gray-400" /> : <ChevronRight size={14} className="text-gray-400" />}
                            <span className="text-sm font-medium text-gray-800">{fmt(s.start, locale)}</span>
                            <span className="text-xs text-gray-400">→ {new Date(s.end).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })}</span>
                            <span className="ml-auto text-xs bg-indigo-50 text-indigo-700 rounded-full px-2 py-0.5">{fmtDur(s.minutes, t)}</span>
                            <span className="text-xs text-gray-400">{t('userActivity.pagesN', { n: s.pages.length })}</span>
                            {s.ip && <span className="text-[11px] text-gray-400 font-mono hidden sm:inline">{s.ip}</span>}
                          </button>
                          {open && (
                            <ol className="px-4 pb-3 pl-11 space-y-1">
                              {s.pages.map((p, j) => (
                                <li key={j} className="flex items-baseline gap-3 text-sm">
                                  <span className="text-xs text-gray-400 font-mono w-12 shrink-0">{new Date(p.at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })}</span>
                                  <span className="text-gray-700" title={p.page}>{pageLabel(p.page)}</span>
                                  <span className="text-xs text-gray-400">{fmtSec(p.seconds, t)}</span>
                                </li>
                              ))}
                            </ol>
                          )}
                        </div>
                      )
                    })}
                  </div>
                  {detail.truncated && <p className="px-4 py-2 text-[11px] text-amber-600">{t('userActivity.truncated')}</p>}
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100">
                  <h3 className="text-sm font-semibold text-gray-700 px-4 py-3 border-b border-gray-100 flex items-center gap-2">
                    <LogIn size={14} /> {t('userActivity.logins')} <span className="text-gray-400 font-normal">({detail.logins.length})</span>
                  </h3>
                  {detail.logins.length === 0 && <p className="px-4 py-6 text-center text-gray-400 text-sm">{t('userActivity.noLogins')}</p>}
                  <ul className="divide-y divide-gray-100">
                    {detail.logins.map((l, i) => (
                      <li key={i} className="px-4 py-2 flex items-center gap-3 text-sm">
                        <span className="text-gray-800">{fmt(l.at, locale)}</span>
                        <span className="text-xs text-gray-500 flex items-center gap-1"><MonitorSmartphone size={12} /> {l.device}</span>
                        {l.ip && <span className="text-[11px] text-gray-400 font-mono">{l.ip}</span>}
                        {l.kind === 'impersonation' && <span className="ml-auto text-[11px] bg-violet-50 text-violet-700 rounded-full px-2 py-0.5">{t('userActivity.impersonation')}</span>}
                      </li>
                    ))}
                  </ul>
                </div>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-[11px] uppercase text-gray-400">{label}</p>
      <p className="text-sm font-medium text-gray-800 mt-0.5">{value}</p>
    </div>
  )
}
