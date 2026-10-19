import { useEffect, useState, useMemo, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import type { DashboardData, ChannelStat, AgentPerformance } from '@/types'
import {
  MessageSquare,
  Clock,
  AlertTriangle,
  Mail,
  Bot,
  CheckCircle,
  Edit3,
  TrendingUp,
  Timer,
  Zap,
  User as UserIcon,
  Send,
  PenLine,
  Info,
  Gauge,
} from 'lucide-react'
import clsx from 'clsx'
import type { TFunction } from 'i18next'

type Period = 'today' | 'yesterday' | '7d' | '30d'

function formatDuration(totalSeconds: number | null, t: TFunction): string {
  if (totalSeconds == null) return '-'
  const h = Math.floor(totalSeconds / 3600)
  const m = Math.floor((totalSeconds % 3600) / 60)
  if (h > 0) return `${h}${t('time.hoursShort')} ${m}${t('time.minutesShort')}`
  if (m > 0) return `${m}${t('time.minutesShort')}`
  return t('time.lessThan1Min')
}

function formatChannelResponseTime(seconds: number | null, t: TFunction): string {
  if (seconds == null) return '-'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (h > 0) return `${h}${t('time.hoursShort')} ${m}${t('time.minutesShort')}`
  if (m > 0) return `${m}${t('time.minutesShort')}`
  return t('time.lessThan1Min')
}

function formatDateLabel(dateStr: string, t: TFunction): string {
  // "2026-03-01" → "1 Mar"
  const d = new Date(dateStr + 'T00:00:00')
  const months = t('dashboard.months', { returnObjects: true }) as string[]
  return `${d.getDate()} ${months[d.getMonth()]}`
}

function PeriodPills({ value, onChange }: { value: Period; onChange: (p: Period) => void }) {
  const { t } = useTranslation()

  const periodOptions: { value: Period; label: string }[] = [
    { value: 'today', label: t('dashboard.periods.today') },
    { value: 'yesterday', label: t('dashboard.periods.yesterday') },
    { value: '7d', label: t('dashboard.periods.7d') },
    { value: '30d', label: t('dashboard.periods.30d') },
  ]

  return (
    <div className="flex gap-1">
      {periodOptions.map((opt) => (
        <button
          key={opt.value}
          onClick={() => onChange(opt.value)}
          className={clsx(
            'px-3 py-1 rounded-full text-xs font-semibold transition-colors',
            value === opt.value
              ? 'bg-indigo-600 text-white'
              : 'text-gray-500 hover:bg-gray-100'
          )}
        >
          {opt.label}
        </button>
      ))}
    </div>
  )
}

export default function DashboardPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [data, setData] = useState<DashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const [channelsPeriod, setChannelsPeriod] = useState<Period>('today')
  const [aiPeriod, setAiPeriod] = useState<Period>('today')
  const [agentPeriod, setAgentPeriod] = useState<Period>('today')

  const fetchData = useCallback((cp: Period, ap: Period, agp: Period) => {
    setLoading(true)
    api.get('/dashboard', { params: { channels_period: cp, ai_period: ap, agent_period: agp } })
      .then((res) => {
        setData(res.data)
      })
      .catch(() => {
        // Error handled by interceptor
      })
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { fetchData(channelsPeriod, aiPeriod, agentPeriod) }, [channelsPeriod, aiPeriod, agentPeriod, fetchData])

  if (loading || !data) {
    return <div className="flex justify-center py-20"><div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" /></div>
  }

  const overviewCards = [
    { label: t('dashboard.open'), value: data.overview.total_open, icon: MessageSquare, color: 'bg-sky-100 text-sky-600' },
    { label: t('dashboard.pending'), value: data.overview.total_pending, icon: Clock, color: 'bg-amber-100 text-amber-600' },
    { label: t('dashboard.urgent'), value: data.overview.total_urgent, icon: AlertTriangle, color: 'bg-rose-100 text-rose-500' },
    { label: t('dashboard.unread'), value: data.overview.total_unread, icon: Mail, color: 'bg-violet-100 text-violet-600' },
  ]

  const ai = data.ai_performance

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-gray-900">{t('dashboard.title')}</h1>

      {/* Agent Performance */}
      <AgentPerformanceSection
        agents={data.agent_performance}
        period={agentPeriod}
        onPeriodChange={setAgentPeriod}
        isAdmin={user?.is_super_admin || user?.role === 'admin'}
      />

      {/* Overview Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {overviewCards.map((card) => (
          <div key={card.label} className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <div className="flex items-center gap-4">
              <div className={clsx('w-12 h-12 rounded-2xl flex items-center justify-center shrink-0', card.color)}>
                <card.icon size={22} />
              </div>
              <div className="min-w-0">
                <p className="text-2xl font-extrabold text-gray-900 leading-tight">{card.value}</p>
                <p className="text-[13px] font-medium text-gray-500 truncate">{card.label}</p>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Charts side by side */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <MessageTrendChart data={data.message_trend} />
        <HourlyDistributionChart data={data.hourly_distribution} />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Channel Stats */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-100">
          <div className="p-5 border-b border-gray-100 flex items-center justify-between">
            <h2 className="font-semibold text-gray-900">{t('dashboard.channelStats')}</h2>
            <PeriodPills value={channelsPeriod} onChange={setChannelsPeriod} />
          </div>
          <div className="divide-y divide-gray-50">
            {data.channels.length === 0 && <p className="text-sm text-gray-400 p-5">{t('common.noData')}</p>}
            {data.channels.map((stat, i) => (
              <ChannelStatRow key={i} stat={stat} />
            ))}
          </div>
        </div>

        {/* AI Performance */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-100">
          <div className="p-5 border-b border-gray-100 flex items-center justify-between">
            <h2 className="font-semibold text-gray-900">{t('dashboard.aiPerformance')}</h2>
            <PeriodPills value={aiPeriod} onChange={setAiPeriod} />
          </div>
          <div className="p-5 space-y-3">
            {ai.total_agent_messages === 0 ? (
              <p className="text-sm text-gray-400 text-center py-4">{t('dashboard.noMessagesThisPeriod')}</p>
            ) : (
              <>
                <div className="grid grid-cols-3 gap-3">
                  <AiCard icon={MessageSquare} color="text-gray-600" value={ai.total_agent_messages} label={t('dashboard.totalReply')} />
                  <AiCard icon={Bot} color="text-indigo-600" value={ai.total_ai_messages} label={t('dashboard.aiPowered')} />
                  <AiCard icon={TrendingUp} color="text-blue-600" value={`%${ai.ai_usage_rate}`} label={t('dashboard.aiUsage')} />
                </div>
                <div className="grid grid-cols-4 gap-3">
                  <AiCard icon={Zap} color="text-emerald-600" value={ai.auto_sent} label={t('dashboard.automatic')} />
                  <AiCard icon={CheckCircle} color="text-green-600" value={ai.directly_approved} label={t('dashboard.unchanged')} />
                  <AiCard icon={Edit3} color="text-amber-600" value={ai.edited_and_sent} label={t('dashboard.edited')} />
                  <AiCard icon={Timer} color="text-orange-600" value={formatDuration(ai.avg_reply_time_seconds, t)} label={t('dashboard.avgReplyTime')} />
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Recent Conversations */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100">
        <div className="p-5 border-b border-gray-100">
          <h2 className="font-semibold text-gray-900">{t('dashboard.recentConversations')}</h2>
        </div>
        <div className="divide-y divide-gray-100">
          {data.recent_conversations.map((conv) => (
            <div key={conv.id} className="p-4 flex items-center justify-between hover:bg-gray-50">
              <div className="flex items-center gap-3 min-w-0">
                <div className={clsx(
                  'w-2 h-2 rounded-full shrink-0',
                  conv.is_urgent ? 'bg-red-500' : conv.status === 'open' ? 'bg-green-500' : 'bg-yellow-500'
                )} />
                <div className="min-w-0">
                  <p className="text-sm font-medium truncate">{conv.customer?.name ?? t('common.unknown')}</p>
                  <p className="text-xs text-gray-500">{conv.channel?.name} - {conv.channel?.type}</p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                {conv.unread_count > 0 && (
                  <span className="bg-indigo-600 text-white text-xs px-2 py-0.5 rounded-full">{conv.unread_count}</span>
                )}
                <span className={clsx(
                  'text-xs px-2 py-1 rounded-full',
                  conv.status === 'open' && 'bg-green-100 text-green-700',
                  conv.status === 'pending' && 'bg-yellow-100 text-yellow-700',
                  conv.status === 'resolved' && 'bg-blue-100 text-blue-700',
                )}>
                  {conv.status}
                </span>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

function ChannelStatRow({ stat }: { stat: ChannelStat }) {
  const { t } = useTranslation()

  return (
    <div className="flex items-center justify-between px-5 py-3">
      <div className="min-w-0">
        <p className="text-sm font-medium text-gray-900 truncate">{stat.channel.name}</p>
        <p className="text-xs text-gray-500">
          {stat.answered} {t('dashboard.answered')} · {stat.unanswered} {t('dashboard.waitingLabel')}
          {stat.avg_response_time_seconds != null && (
            <> · {formatChannelResponseTime(stat.avg_response_time_seconds, t)}</>
          )}
        </p>
      </div>
      <p className="text-lg font-bold text-gray-900 ml-4 shrink-0">{stat.total_conversations}</p>
    </div>
  )
}

function AiCard({ icon: Icon, color, value, label }: { icon: React.ElementType; color: string; value: string | number; label: string }) {
  return (
    <div className="text-center p-3 bg-gray-50 rounded-lg">
      <Icon className={clsx('mx-auto mb-1.5', color)} size={20} />
      <p className="text-xl font-bold">{value}</p>
      <p className="text-xs text-gray-500">{label}</p>
    </div>
  )
}

function MessageTrendChart({ data }: { data: { date: string; count: number }[] }) {
  const { t } = useTranslation()
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)

  const maxCount = useMemo(() => Math.max(...data.map(d => d.count), 1), [data])

  if (data.length === 0) return null

  const padding = { top: 20, right: 16, bottom: 28, left: 38 }
  const chartWidth = 900
  const chartHeight = 180
  const innerW = chartWidth - padding.left - padding.right
  const innerH = chartHeight - padding.top - padding.bottom
  const gap = 3
  const barWidth = Math.max(6, (innerW - gap * (data.length - 1)) / data.length)

  // Y-axis ticks
  const yTicks = useMemo(() => {
    const step = Math.ceil(maxCount / 4 / 10) * 10 || 1
    const ticks: number[] = [0]
    let v = step
    while (v <= maxCount) { ticks.push(v); v += step }
    if (ticks[ticks.length - 1] < maxCount) ticks.push(Math.ceil(maxCount / step) * step)
    return ticks
  }, [maxCount])

  const yMax = yTicks[yTicks.length - 1] || 1

  // Date labels: show every ~5 days
  const labelInterval = Math.max(1, Math.ceil(data.length / 6))

  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <h2 className="font-semibold text-gray-900 mb-4">{t('dashboard.dailyMessageVolume')}</h2>
      <svg
        viewBox={`0 0 ${chartWidth} ${chartHeight}`}
        className="w-full h-auto"
        style={{ minHeight: 160 }}
      >
        {/* Y-axis grid lines & labels */}
        {yTicks.map((tick) => {
          const y = padding.top + innerH - (tick / yMax) * innerH
          return (
            <g key={tick}>
              <line
                x1={padding.left}
                x2={chartWidth - padding.right}
                y1={y}
                y2={y}
                stroke="#f3f4f6"
                strokeWidth={1}
              />
              <text
                x={padding.left - 8}
                y={y + 3}
                textAnchor="end"
                fill="#9ca3af"
                fontSize={9}
                fontFamily="system-ui, sans-serif"
              >
                {tick}
              </text>
            </g>
          )
        })}

        {/* Bars & X-axis labels */}
        {data.map((point, i) => {
          const barH = Math.max(1, (point.count / yMax) * innerH)
          const x = padding.left + i * (barWidth + gap)
          const y = padding.top + innerH - barH
          const isHovered = hoveredIndex === i

          return (
            <g
              key={point.date}
              onMouseEnter={() => setHoveredIndex(i)}
              onMouseLeave={() => setHoveredIndex(null)}
              style={{ cursor: 'default' }}
            >
              {/* Invisible wider hit area */}
              <rect
                x={x - gap / 2}
                y={padding.top}
                width={barWidth + gap}
                height={innerH + padding.bottom}
                fill="transparent"
              />
              <rect
                x={x}
                y={y}
                width={barWidth}
                height={barH}
                rx={Math.min(3, barWidth / 2)}
                fill={isHovered ? '#4f46e5' : '#818cf8'}
              />

              {/* X-axis date labels */}
              {(i % labelInterval === 0 || i === data.length - 1) && (
                <text
                  x={x + barWidth / 2}
                  y={chartHeight - 8}
                  textAnchor="middle"
                  fill="#9ca3af"
                  fontSize={9}
                  fontFamily="system-ui, sans-serif"
                >
                  {formatDateLabel(point.date, t)}
                </text>
              )}
            </g>
          )
        })}

        {/* Tooltip — rendered last so it's always on top */}
        {hoveredIndex !== null && (() => {
          const point = data[hoveredIndex]
          const barH = Math.max(1, (point.count / yMax) * innerH)
          const x = padding.left + hoveredIndex * (barWidth + gap)
          const y = padding.top + innerH - barH
          const tooltipW = 86
          const tooltipH = 26
          const tx = Math.min(Math.max(x + barWidth / 2 - tooltipW / 2, 4), chartWidth - tooltipW - 4)
          const ty = Math.max(y - tooltipH - 6, 2)

          return (
            <g pointerEvents="none">
              <rect
                x={tx}
                y={ty}
                width={tooltipW}
                height={tooltipH}
                rx={6}
                fill="#1f2937"
                opacity={0.95}
              />
              <text
                x={tx + tooltipW / 2}
                y={ty + tooltipH / 2 + 4}
                textAnchor="middle"
                fill="white"
                fontSize={10}
                fontFamily="system-ui, sans-serif"
                fontWeight={500}
              >
                {formatDateLabel(point.date, t)} — {point.count}
              </text>
            </g>
          )
        })()}
      </svg>
    </div>
  )
}

function HourlyDistributionChart({ data }: { data: { hour: number; count: number }[] }) {
  const { t } = useTranslation()
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const maxCount = useMemo(() => Math.max(...data.map(d => d.count), 1), [data])
  const totalToday = useMemo(() => data.reduce((s, d) => s + d.count, 0), [data])

  if (totalToday === 0) return null

  const padding = { top: 20, right: 16, bottom: 28, left: 38 }
  const chartWidth = 500
  const chartHeight = 180
  const innerW = chartWidth - padding.left - padding.right
  const innerH = chartHeight - padding.top - padding.bottom

  const yTicks = useMemo(() => {
    const step = Math.ceil(maxCount / 4 / 5) * 5 || 1
    const ticks: number[] = [0]
    let v = step
    while (v <= maxCount) { ticks.push(v); v += step }
    if (ticks[ticks.length - 1] < maxCount) ticks.push(Math.ceil(maxCount / step) * step)
    return ticks
  }, [maxCount])

  const yMax = yTicks[yTicks.length - 1] || 1
  const currentHour = new Date().getHours()

  // Calculate point positions
  const points = data.map((point, i) => ({
    x: padding.left + (i / 23) * innerW,
    y: padding.top + innerH - (point.count / yMax) * innerH,
    ...point,
  }))

  // Build line & area paths
  const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ')
  const areaPath = `${linePath} L${points[points.length - 1].x},${padding.top + innerH} L${points[0].x},${padding.top + innerH} Z`

  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <div className="flex items-center justify-between mb-4">
        <h2 className="font-semibold text-gray-900">{t('dashboard.hourlyDistribution')}</h2>
        <span className="text-sm text-gray-500">{t('dashboard.periods.today')}: {totalToday}</span>
      </div>
      <svg
        viewBox={`0 0 ${chartWidth} ${chartHeight}`}
        className="w-full h-auto"
        style={{ minHeight: 160 }}
      >
        <defs>
          <linearGradient id="hourlyGradient" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="#818cf8" stopOpacity={0.3} />
            <stop offset="100%" stopColor="#818cf8" stopOpacity={0.02} />
          </linearGradient>
        </defs>

        {/* Y grid & labels */}
        {yTicks.map((tick) => {
          const y = padding.top + innerH - (tick / yMax) * innerH
          return (
            <g key={tick}>
              <line x1={padding.left} x2={chartWidth - padding.right} y1={y} y2={y} stroke="#f3f4f6" strokeWidth={1} />
              <text x={padding.left - 8} y={y + 3} textAnchor="end" fill="#9ca3af" fontSize={9} fontFamily="system-ui, sans-serif">{tick}</text>
            </g>
          )
        })}

        {/* Area fill */}
        <path d={areaPath} fill="url(#hourlyGradient)" />

        {/* Line */}
        <path d={linePath} fill="none" stroke="#818cf8" strokeWidth={2} strokeLinejoin="round" />

        {/* Interactive hit areas, dots, and X labels */}
        {points.map((p, i) => {
          const isHovered = hoveredIndex === i
          const isCurrent = p.hour === currentHour
          const stepW = innerW / 23

          return (
            <g key={p.hour} onMouseEnter={() => setHoveredIndex(i)} onMouseLeave={() => setHoveredIndex(null)} style={{ cursor: 'default' }}>
              <rect x={p.x - stepW / 2} y={padding.top} width={stepW} height={innerH + padding.bottom} fill="transparent" />
              {/* Vertical indicator on hover */}
              {isHovered && <line x1={p.x} x2={p.x} y1={p.y} y2={padding.top + innerH} stroke="#818cf8" strokeWidth={1} strokeDasharray="3,3" opacity={0.5} />}
              {/* Dot */}
              <circle cx={p.x} cy={p.y} r={isHovered ? 5 : isCurrent ? 4 : p.count > 0 ? 2.5 : 0} fill={isHovered ? '#4f46e5' : isCurrent ? '#6366f1' : '#818cf8'} stroke={isHovered || isCurrent ? 'white' : 'none'} strokeWidth={2} />
              {/* X labels: every 3h */}
              {p.hour % 3 === 0 && (
                <text x={p.x} y={chartHeight - 8} textAnchor="middle" fill={isCurrent ? '#4f46e5' : '#9ca3af'} fontSize={9} fontWeight={isCurrent ? 600 : 400} fontFamily="system-ui, sans-serif">
                  {String(p.hour).padStart(2, '0')}
                </text>
              )}
            </g>
          )
        })}

        {/* Tooltip */}
        {hoveredIndex !== null && (() => {
          const p = points[hoveredIndex]
          const tooltipW = 80
          const tooltipH = 26
          const tx = Math.min(Math.max(p.x - tooltipW / 2, 4), chartWidth - tooltipW - 4)
          const ty = Math.max(p.y - tooltipH - 10, 2)
          return (
            <g pointerEvents="none">
              <rect x={tx} y={ty} width={tooltipW} height={tooltipH} rx={6} fill="#1f2937" opacity={0.95} />
              <text x={tx + tooltipW / 2} y={ty + tooltipH / 2 + 4} textAnchor="middle" fill="white" fontSize={10} fontFamily="system-ui, sans-serif" fontWeight={500}>
                {String(p.hour).padStart(2, '0')}:00 — {p.count}
              </text>
            </g>
          )
        })()}
      </svg>
    </div>
  )
}

function AgentPerformanceSection({ agents, period, onPeriodChange, isAdmin }: {
  agents: AgentPerformance[]
  period: Period
  onPeriodChange: (p: Period) => void
  isAdmin?: boolean
}) {
  const { t } = useTranslation()
  const navigate = useNavigate()

  if (agents.length === 0) return null

  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-100">
      <div className="p-5 border-b border-gray-100 flex items-center justify-between">
        <h2 className="font-semibold text-gray-900">
          {isAdmin ? t('dashboard.agentPerformance') : t('dashboard.myPerformance')}
        </h2>
        <PeriodPills value={period} onChange={onPeriodChange} />
      </div>
      <div className="p-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {agents.map((agent) => (
          <AgentCard
            key={agent.user.id}
            agent={agent}
            onPenaltyClick={(agentId) => navigate(`/messages?penalty_agent_id=${agentId}`)}
          />
        ))}
      </div>
    </div>
  )
}

function StatWithTooltip({ value, label, tooltip, bgClass, textClass, labelClass }: {
  value: string; label: string; tooltip: string; bgClass: string; textClass: string; labelClass: string
}) {
  return (
    <div className={clsx(bgClass, 'rounded-lg py-2 relative group cursor-help flex flex-col items-center justify-center')}>
      <p className={clsx('text-sm font-bold h-5 flex items-center', textClass)}>{value}</p>
      <div className={clsx('flex items-center justify-center gap-0.5', labelClass)}>
        <p className="text-[10px]">{label}</p>
        <Info size={9} className="opacity-50" />
      </div>
      <span className="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block bg-gray-800 text-white text-[10px] rounded-md px-2.5 py-1.5 w-48 text-center z-10 shadow-lg leading-relaxed pointer-events-none">
        {tooltip}
      </span>
    </div>
  )
}

function getScoreColor(score: number): { bg: string; text: string; ring: string } {
  if (score >= 80) return { bg: 'bg-emerald-50', text: 'text-emerald-700', ring: 'ring-emerald-200' }
  if (score >= 60) return { bg: 'bg-blue-50', text: 'text-blue-700', ring: 'ring-blue-200' }
  if (score >= 40) return { bg: 'bg-amber-50', text: 'text-amber-700', ring: 'ring-amber-200' }
  return { bg: 'bg-red-50', text: 'text-red-700', ring: 'ring-red-200' }
}

function getScoreLabel(score: number, t: TFunction): string {
  if (score >= 80) return t('dashboard.excellent')
  if (score >= 60) return t('dashboard.good')
  if (score >= 40) return t('dashboard.average')
  return t('dashboard.needsImprovement')
}

function AgentCard({ agent, onPenaltyClick }: { agent: AgentPerformance; onPenaltyClick?: (agentId: number) => void }) {
  const { t } = useTranslation()

  const aiRate = agent.total_messages > 0
    ? Math.round((agent.ai_used / agent.total_messages) * 100)
    : 0

  const score = agent.performance_score
  const scoreColors = score != null ? getScoreColor(score) : null
  const hasPenalty = agent.penalty_points > 0

  return (
    <div className="border border-gray-100 rounded-xl p-4 space-y-3">
      {/* Header */}
      <div className="flex items-center gap-3">
        <div className="w-9 h-9 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 shrink-0">
          {agent.user.avatar ? (
            <img src={agent.user.avatar} alt="" className="w-9 h-9 rounded-full object-cover" />
          ) : (
            <UserIcon size={18} />
          )}
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-gray-900 truncate">{agent.user.name}</p>
          <p className="text-xs text-gray-400">
            {agent.user.role === 'admin' ? t('dashboard.admin') : t('dashboard.agent')}
            {agent.user.tenant_name && <span className="text-gray-300"> · {agent.user.tenant_name}</span>}
          </p>
        </div>
        {/* Performance score — always visible */}
        <div className={clsx(
          'relative group shrink-0 flex flex-col items-center rounded-lg px-2.5 py-1 ring-1',
          scoreColors ? [scoreColors.bg, scoreColors.ring] : ['bg-gray-50', 'ring-gray-200']
        )}>
          <span className={clsx('text-lg font-bold leading-tight', scoreColors ? scoreColors.text : 'text-gray-400')}>
            {score != null ? score : '-'}
          </span>
          <span className={clsx('text-[9px] font-medium leading-tight', scoreColors ? scoreColors.text : 'text-gray-400')}>
            {score != null ? getScoreLabel(score, t) : t('dashboard.score')}
          </span>
          <span className="absolute bottom-full right-0 mb-1.5 hidden group-hover:block bg-gray-800 text-white text-[10px] rounded-lg px-3 py-2 w-56 z-20 shadow-lg leading-relaxed text-left pointer-events-none">
            <span className="font-semibold block mb-1">{t('dashboard.performanceScore')}</span>
            {t('dashboard.performanceScoreDesc')}
          </span>
        </div>
      </div>

      {/* Stats grid — 4 main + 1 narrow penalty */}
      <div className="flex gap-1.5 text-center">
        <div className="flex-1 bg-blue-50 rounded-lg py-2 flex flex-col items-center justify-center">
          <p className="text-sm font-bold h-5 flex items-center text-blue-700">{agent.total_messages}</p>
          <p className="text-[10px] text-blue-600">{t('dashboard.totalReplyShort')}</p>
        </div>
        <div className="flex-1 bg-emerald-50 rounded-lg py-2 flex flex-col items-center justify-center">
          <p className="text-sm font-bold h-5 flex items-center text-emerald-700">{agent.conversations_answered}</p>
          <p className="text-[10px] text-emerald-600">{t('dashboard.conversation')}</p>
        </div>
        <StatWithTooltip
          value={agent.total_handling_time_seconds != null ? formatDuration(agent.total_handling_time_seconds, t) : '-'}
          label={t('dashboard.workTime')}
          tooltip={t('dashboard.workTimeTooltip')}
          bgClass="bg-orange-50 flex-1"
          textClass="text-orange-700"
          labelClass="text-orange-600"
        />
        <StatWithTooltip
          value={agent.avg_reply_time_seconds != null ? formatDuration(agent.avg_reply_time_seconds, t) : '-'}
          label={t('dashboard.avgReply')}
          tooltip={t('dashboard.avgReplyTooltip')}
          bgClass="bg-violet-50 flex-1"
          textClass="text-violet-700"
          labelClass="text-violet-600"
        />
        {/* Hatalı Mesaj — narrow column */}
        {hasPenalty ? (
          <button
            onClick={() => onPenaltyClick?.(agent.user.id)}
            className="w-12 shrink-0 bg-red-50 rounded-lg py-2 relative group cursor-pointer hover:bg-red-100 transition-colors flex flex-col items-center justify-center"
          >
            <p className="text-sm font-bold h-5 flex items-center text-red-700">{agent.penalty_points}</p>
            <p className="text-[9px] text-red-600">{t('dashboard.error')}</p>
            <span className="absolute bottom-full right-0 mb-1 hidden group-hover:block bg-gray-800 text-white text-[10px] rounded-md px-2.5 py-1.5 w-40 text-center z-10 shadow-lg leading-relaxed pointer-events-none">
              {t('dashboard.errorTooltip')}
            </span>
          </button>
        ) : (
          <div className="w-12 shrink-0 bg-gray-50 rounded-lg py-2 relative group flex flex-col items-center justify-center">
            <p className="text-sm font-bold h-5 flex items-center text-gray-300">-</p>
            <p className="text-[9px] text-gray-400">{t('dashboard.error')}</p>
            <span className="absolute bottom-full right-0 mb-1 hidden group-hover:block bg-gray-800 text-white text-[10px] rounded-md px-2.5 py-1.5 w-40 text-center z-10 shadow-lg leading-relaxed pointer-events-none">
              {t('dashboard.errorCountTooltip')}
            </span>
          </div>
        )}
      </div>

      {/* Speed */}
      <div className="flex items-center justify-between px-1">
        <div className="flex items-center gap-1.5 text-xs text-gray-500 group relative">
          <Gauge size={13} className="text-indigo-500" />
          <span className="font-medium text-gray-700">{agent.speed_per_hour} {t('dashboard.messagesPerHour')}</span>
          <Info size={11} className="text-gray-400 cursor-help" />
          <span className="absolute bottom-full left-0 mb-1 hidden group-hover:block bg-gray-800 text-white text-[10px] rounded-md px-2.5 py-1.5 whitespace-nowrap z-10 shadow-lg">
            {t('dashboard.speedTooltip')}
          </span>
        </div>
      </div>

      {/* AI breakdown */}
      <div className="flex items-center gap-2 text-xs">
        <div className="flex items-center gap-1 text-gray-500">
          <Bot size={12} />
          <span>AI: %{aiRate}</span>
        </div>
        <span className="text-gray-300">|</span>
        <div className="flex items-center gap-1 text-green-600" title={t('dashboard.sentUnchanged')}>
          <Send size={11} />
          <span>{agent.directly_approved}</span>
        </div>
        <div className="flex items-center gap-1 text-amber-600" title={t('dashboard.sentEdited')}>
          <PenLine size={11} />
          <span>{agent.edited_and_sent}</span>
        </div>
        <div className="flex items-center gap-1 text-gray-600" title={t('dashboard.manuallyWritten')}>
          <Edit3 size={11} />
          <span>{agent.manual}</span>
        </div>
      </div>

      {/* AI usage bar */}
      {agent.total_messages > 0 && (
        <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden flex">
          {agent.directly_approved > 0 && (
            <div className="bg-green-500 h-full" style={{ width: `${(agent.directly_approved / agent.total_messages) * 100}%` }} />
          )}
          {agent.edited_and_sent > 0 && (
            <div className="bg-amber-400 h-full" style={{ width: `${(agent.edited_and_sent / agent.total_messages) * 100}%` }} />
          )}
          {agent.manual > 0 && (
            <div className="bg-gray-300 h-full" style={{ width: `${(agent.manual / agent.total_messages) * 100}%` }} />
          )}
        </div>
      )}
    </div>
  )
}
