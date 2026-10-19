import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useNavigate } from 'react-router-dom'
import api from '@/lib/api'
import {
  Phone, PhoneIncoming, PhoneOutgoing, ArrowLeft, Clock,
  Loader2, FileText, Brain, Smile, Frown, Meh, Tag, AlertCircle,
  User, UserCheck, MessageSquare, Target, CheckCircle2, XCircle,
  ShieldCheck, Package, ShoppingCart, Star, TrendingUp, Lightbulb,
  ArrowRight,
} from 'lucide-react'
import clsx from 'clsx'

interface CustomerProfile {
  mood_start?: string
  mood_end?: string
  is_repeat_caller?: boolean
  loyalty_indicator?: string
}

interface AgentPerformance {
  empathy_score?: number
  solution_quality?: number
  communication_clarity?: number
  notes?: string
}

interface DialogStep {
  role: string
  intent?: string
  action?: string
}

interface AiTraining {
  ideal_response_strategy?: string
  key_info_needed?: string[]
  sample_phrases?: string[]
}

interface AiMetadata {
  keywords?: string[]
  customer_intent?: string
  resolution_status?: string
  resolution_note?: string
  product_references?: string[]
  order_references?: string[]
  customer_profile?: CustomerProfile
  agent_performance?: AgentPerformance
  dialog_flow?: DialogStep[]
  ai_training?: AiTraining
  customer_satisfied?: boolean
  action_required?: boolean
  action_note?: string
}

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
  recording_path: string | null
  transcription: string | null
  ai_summary: string | null
  ai_sentiment: string | null
  ai_category: string | null
  ai_metadata: AiMetadata | null
  transcribed_at: string | null
  customer?: {
    id: number
    name: string
    email: string | null
    phone: string | null
  } | null
}

const statusLabels: Record<string, string> = {
  answered: 'Cevaplandı',
  missed: 'Cevapsız',
  voicemail: 'Mesai Dışı',
  ivr: 'IVR',
  no_answer: 'Yanıtlanmadı',
  busy: 'Meşgul',
}

const statusColors: Record<string, string> = {
  answered: 'bg-green-100 text-green-700',
  missed: 'bg-red-100 text-red-700',
  voicemail: 'bg-purple-100 text-purple-700',
  ivr: 'bg-purple-100 text-purple-700',
  no_answer: 'bg-orange-100 text-orange-700',
  busy: 'bg-yellow-100 text-yellow-700',
}

const sentimentConfig: Record<string, { label: string; icon: typeof Smile; color: string; bg: string }> = {
  positive: { label: 'Olumlu', icon: Smile, color: 'text-green-600', bg: 'bg-green-50' },
  neutral: { label: 'Nötr', icon: Meh, color: 'text-gray-600', bg: 'bg-gray-50' },
  negative: { label: 'Olumsuz', icon: Frown, color: 'text-red-600', bg: 'bg-red-50' },
}

const categoryLabels: Record<string, string> = {
  satis_oncesi: 'Satış Öncesi',
  siparis_takip: 'Sipariş Takip',
  iade_degisim: 'İade/Değişim',
  sikayet: 'Şikayet',
  bilgi_talebi: 'Bilgi Talebi',
  teknik_destek: 'Teknik Destek',
  diger: 'Diğer',
}

const categoryColors: Record<string, string> = {
  satis_oncesi: 'bg-emerald-50 text-emerald-700',
  siparis_takip: 'bg-blue-50 text-blue-700',
  iade_degisim: 'bg-orange-50 text-orange-700',
  sikayet: 'bg-red-50 text-red-700',
  bilgi_talebi: 'bg-cyan-50 text-cyan-700',
  teknik_destek: 'bg-violet-50 text-violet-700',
  diger: 'bg-gray-50 text-gray-700',
}

const resolutionLabels: Record<string, { label: string; color: string; icon: typeof CheckCircle2 }> = {
  resolved: { label: 'Çözüldü', color: 'text-green-600 bg-green-50', icon: CheckCircle2 },
  pending: { label: 'Beklemede', color: 'text-yellow-600 bg-yellow-50', icon: Clock },
  escalated: { label: 'Eskalasyon', color: 'text-orange-600 bg-orange-50', icon: TrendingUp },
  unresolved: { label: 'Çözülmedi', color: 'text-red-600 bg-red-50', icon: XCircle },
}

const moodLabels: Record<string, { label: string; emoji: string }> = {
  angry: { label: 'Kızgın', emoji: '😠' },
  frustrated: { label: 'Sinirli', emoji: '😤' },
  neutral: { label: 'Nötr', emoji: '😐' },
  polite: { label: 'Kibar', emoji: '🙂' },
  friendly: { label: 'Samimi', emoji: '😊' },
  satisfied: { label: 'Memnun', emoji: '😄' },
}

const loyaltyLabels: Record<string, { label: string; color: string }> = {
  new: { label: 'Yeni Müşteri', color: 'bg-blue-100 text-blue-700' },
  regular: { label: 'Düzenli Müşteri', color: 'bg-green-100 text-green-700' },
  vip: { label: 'VIP Müşteri', color: 'bg-purple-100 text-purple-700' },
  unknown: { label: 'Bilinmiyor', color: 'bg-gray-100 text-gray-600' },
}

function formatDuration(seconds: number): string {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

function ScoreBar({ label, score, max = 5 }: { label: string; score: number; max?: number }) {
  const pct = (score / max) * 100
  const color = score >= 4 ? 'bg-green-500' : score >= 3 ? 'bg-yellow-500' : 'bg-red-500'
  return (
    <div className="space-y-1">
      <div className="flex justify-between text-xs">
        <span className="text-gray-600">{label}</span>
        <span className="font-semibold text-gray-800">{score}/{max}</span>
      </div>
      <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
        <div className={clsx('h-full rounded-full transition-all', color)} style={{ width: `${pct}%` }} />
      </div>
    </div>
  )
}

export default function CallDetailPage() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [call, setCall] = useState<Call | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const fetchCall = async () => {
      setLoading(true)
      try {
        const res = await api.get(`/calls/${id}`)
        setCall(res.data)
      } finally {
        setLoading(false)
      }
    }
    fetchCall()
  }, [id])

  if (loading) {
    return (
      <div className="flex justify-center py-20">
        <Loader2 size={24} className="animate-spin text-blue-600" />
      </div>
    )
  }

  if (!call) {
    return (
      <div className="text-center py-20 text-gray-400">
        <Phone size={40} className="mx-auto mb-3 opacity-30" />
        <p>{t('callsCommon.callNotFound')}</p>
      </div>
    )
  }

  const sentiment = call.ai_sentiment ? sentimentConfig[call.ai_sentiment] : null
  const meta = call.ai_metadata
  const resolution = meta?.resolution_status ? resolutionLabels[meta.resolution_status] : null
  const profile = meta?.customer_profile
  const perf = meta?.agent_performance
  const training = meta?.ai_training

  return (
    <div className="space-y-4 max-w-5xl">
      {/* Header */}
      <div className="flex items-center gap-3">
        <button
          onClick={() => navigate('/calls')}
          className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
        >
          <ArrowLeft size={18} />
        </button>
        <div className="flex items-center gap-3 flex-1">
          {call.direction === 'inbound' ? (
            <div className="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center">
              <PhoneIncoming size={20} className="text-blue-600" />
            </div>
          ) : (
            <div className="w-10 h-10 rounded-full bg-indigo-50 flex items-center justify-center">
              <PhoneOutgoing size={20} className="text-indigo-600" />
            </div>
          )}
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-xl font-extrabold text-gray-900">{call.caller}</h1>
              <span className={clsx('px-2 py-0.5 rounded text-xs font-medium', statusColors[call.status])}>
                {statusLabels[call.status] || call.status}
              </span>
              {meta?.customer_satisfied !== undefined && (
                <span className={clsx(
                  'px-2 py-0.5 rounded text-xs font-medium',
                  meta.customer_satisfied ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
                )}>
                  {meta.customer_satisfied ? 'Memnun' : 'Memnun Değil'}
                </span>
              )}
            </div>
            <p className="text-sm text-gray-500">
              {new Date(call.started_at).toLocaleString(locale)}
              {call.extension && ` · ${call.agent_name ? `${call.agent_name} (${call.extension})` : `Dahili: ${call.extension}`}`}
              {call.duration > 0 && ` · ${formatDuration(call.duration)}`}
            </p>
          </div>
        </div>
      </div>

      {/* Action Required Banner */}
      {meta?.action_required && (
        <div className="flex items-start gap-3 p-4 bg-amber-50 rounded-xl border border-amber-200">
          <AlertCircle size={18} className="text-amber-600 mt-0.5 shrink-0" />
          <div>
            <p className="text-sm font-semibold text-amber-800">Aksiyon Gerekli</p>
            {meta.action_note && (
              <p className="text-sm text-amber-700 mt-1">{meta.action_note}</p>
            )}
          </div>
        </div>
      )}

      {/* Top Row: Summary + Sentiment + Category + Resolution */}
      {call.ai_summary && (
        <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm space-y-4">
          <div className="flex items-start gap-3">
            <Brain size={18} className="text-blue-600 mt-0.5 shrink-0" />
            <div className="flex-1">
              <h3 className="text-sm font-semibold text-gray-700 mb-2">AI Analiz Özeti</h3>
              <p className="text-sm text-gray-800 leading-relaxed">{call.ai_summary}</p>
            </div>
          </div>

          {/* Tags Row */}
          <div className="flex flex-wrap gap-2">
            {sentiment && (
              <div className={clsx('flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium', sentiment.bg, sentiment.color)}>
                <sentiment.icon size={14} />
                {sentiment.label}
              </div>
            )}
            {call.ai_category && (
              <div className={clsx('flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium', categoryColors[call.ai_category] || 'bg-gray-50 text-gray-700')}>
                <Tag size={14} />
                {categoryLabels[call.ai_category] || call.ai_category}
              </div>
            )}
            {resolution && (
              <div className={clsx('flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium', resolution.color)}>
                <resolution.icon size={14} />
                {resolution.label}
              </div>
            )}
          </div>

          {/* Customer Intent */}
          {meta?.customer_intent && (
            <div className="flex items-start gap-2 p-3 bg-blue-50/50 rounded-lg">
              <Target size={14} className="text-blue-500 mt-0.5 shrink-0" />
              <div>
                <p className="text-xs font-medium text-blue-600 mb-0.5">Müşteri Amacı</p>
                <p className="text-sm text-gray-700">{meta.customer_intent}</p>
              </div>
            </div>
          )}

          {/* Resolution Note */}
          {meta?.resolution_note && (
            <div className="flex items-start gap-2 p-3 bg-gray-50 rounded-lg">
              <ShieldCheck size={14} className="text-gray-500 mt-0.5 shrink-0" />
              <div>
                <p className="text-xs font-medium text-gray-500 mb-0.5">Çözüm Notu</p>
                <p className="text-sm text-gray-700">{meta.resolution_note}</p>
              </div>
            </div>
          )}

          {/* References */}
          {((meta?.product_references && meta.product_references.length > 0) || (meta?.order_references && meta.order_references.length > 0)) && (
            <div className="flex flex-wrap gap-4">
              {meta?.product_references && meta.product_references.length > 0 && (
                <div className="flex items-center gap-2">
                  <Package size={13} className="text-gray-400" />
                  <span className="text-xs text-gray-500">Ürünler:</span>
                  {meta.product_references.map((p, i) => (
                    <span key={i} className="px-2 py-0.5 bg-violet-50 text-violet-700 rounded text-xs">{p}</span>
                  ))}
                </div>
              )}
              {meta?.order_references && meta.order_references.length > 0 && (
                <div className="flex items-center gap-2">
                  <ShoppingCart size={13} className="text-gray-400" />
                  <span className="text-xs text-gray-500">Siparişler:</span>
                  {meta.order_references.map((o, i) => (
                    <span key={i} className="px-2 py-0.5 bg-indigo-50 text-indigo-700 rounded text-xs">{o}</span>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Keywords */}
          {meta?.keywords && meta.keywords.length > 0 && (
            <div className="flex flex-wrap gap-1.5">
              {meta.keywords.map((kw, i) => (
                <span key={i} className="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs">{kw}</span>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Middle Row: Customer Profile + Agent Performance */}
      {(profile || perf) && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {/* Customer Profile */}
          {profile && (
            <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
              <h3 className="text-sm font-semibold text-gray-700 flex items-center gap-2 mb-4">
                <User size={14} />
                Müşteri Profili
              </h3>
              <div className="space-y-3">
                {/* Mood Transition */}
                {(profile.mood_start || profile.mood_end) && (
                  <div>
                    <p className="text-xs text-gray-500 mb-2">Ruh Hali Değişimi</p>
                    <div className="flex items-center gap-3">
                      {profile.mood_start && moodLabels[profile.mood_start] && (
                        <div className="flex items-center gap-1.5 px-3 py-1.5 bg-gray-50 rounded-lg">
                          <span className="text-base">{moodLabels[profile.mood_start].emoji}</span>
                          <span className="text-sm text-gray-700">{moodLabels[profile.mood_start].label}</span>
                        </div>
                      )}
                      <ArrowRight size={16} className="text-gray-300 shrink-0" />
                      {profile.mood_end && moodLabels[profile.mood_end] && (
                        <div className="flex items-center gap-1.5 px-3 py-1.5 bg-gray-50 rounded-lg">
                          <span className="text-base">{moodLabels[profile.mood_end].emoji}</span>
                          <span className="text-sm text-gray-700">{moodLabels[profile.mood_end].label}</span>
                        </div>
                      )}
                    </div>
                  </div>
                )}

                {/* Loyalty + Repeat Caller */}
                <div className="flex flex-wrap gap-2">
                  {profile.loyalty_indicator && loyaltyLabels[profile.loyalty_indicator] && (
                    <span className={clsx('px-2.5 py-1 rounded-lg text-xs font-medium', loyaltyLabels[profile.loyalty_indicator].color)}>
                      <Star size={10} className="inline mr-1" />
                      {loyaltyLabels[profile.loyalty_indicator].label}
                    </span>
                  )}
                  {profile.is_repeat_caller && (
                    <span className="px-2.5 py-1 rounded-lg text-xs font-medium bg-amber-100 text-amber-700">
                      Tekrar Arayan
                    </span>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* Agent Performance */}
          {perf && (
            <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
              <h3 className="text-sm font-semibold text-gray-700 flex items-center gap-2 mb-4">
                <UserCheck size={14} />
                Temsilci Performansı
              </h3>
              <div className="space-y-3">
                {perf.empathy_score !== undefined && (
                  <ScoreBar label="Empati" score={perf.empathy_score} />
                )}
                {perf.solution_quality !== undefined && (
                  <ScoreBar label="Çözüm Kalitesi" score={perf.solution_quality} />
                )}
                {perf.communication_clarity !== undefined && (
                  <ScoreBar label="İletişim Netliği" score={perf.communication_clarity} />
                )}
                {perf.notes && (
                  <p className="text-xs text-gray-500 mt-2 leading-relaxed italic">{perf.notes}</p>
                )}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Dialog Flow */}
      {meta?.dialog_flow && meta.dialog_flow.length > 0 && (
        <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-700 flex items-center gap-2 mb-4">
            <MessageSquare size={14} />
            Konuşma Akışı
          </h3>
          <div className="space-y-0">
            {meta.dialog_flow.map((step, i) => (
              <div key={i} className="flex gap-3">
                {/* Timeline line */}
                <div className="flex flex-col items-center">
                  <div className={clsx(
                    'w-7 h-7 rounded-full flex items-center justify-center shrink-0 text-xs font-bold',
                    step.role === 'customer' ? 'bg-blue-100 text-blue-600' : 'bg-green-100 text-green-600'
                  )}>
                    {step.role === 'customer' ? 'M' : 'T'}
                  </div>
                  {i < meta.dialog_flow!.length - 1 && (
                    <div className="w-px h-full min-h-[16px] bg-gray-200" />
                  )}
                </div>
                {/* Content */}
                <div className="pb-3 flex-1">
                  <span className={clsx(
                    'text-[10px] font-semibold uppercase tracking-wider',
                    step.role === 'customer' ? 'text-blue-500' : 'text-green-500'
                  )}>
                    {step.role === 'customer' ? 'Müşteri' : 'Temsilci'}
                  </span>
                  <p className="text-sm text-gray-700 mt-0.5">{step.intent || step.action}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* AI Training Insights */}
      {training && (training.ideal_response_strategy || (training.key_info_needed && training.key_info_needed.length > 0)) && (
        <div className="bg-gradient-to-br from-indigo-50 to-blue-50 rounded-xl p-5 border border-indigo-100">
          <h3 className="text-sm font-semibold text-indigo-800 flex items-center gap-2 mb-4">
            <Lightbulb size={14} />
            AI Eğitim Notları
          </h3>
          <div className="space-y-4">
            {training.ideal_response_strategy && (
              <div>
                <p className="text-xs font-medium text-indigo-600 mb-1">İdeal Yanıt Stratejisi</p>
                <p className="text-sm text-gray-700 leading-relaxed">{training.ideal_response_strategy}</p>
              </div>
            )}
            {training.key_info_needed && training.key_info_needed.length > 0 && (
              <div>
                <p className="text-xs font-medium text-indigo-600 mb-1">Gerekli Bilgiler</p>
                <ul className="space-y-1">
                  {training.key_info_needed.map((info, i) => (
                    <li key={i} className="text-sm text-gray-700 flex items-start gap-2">
                      <span className="text-indigo-400 mt-1 shrink-0">•</span>
                      {info}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {training.sample_phrases && training.sample_phrases.length > 0 && (
              <div>
                <p className="text-xs font-medium text-indigo-600 mb-1">Örnek Cümleler</p>
                <div className="flex flex-wrap gap-1.5">
                  {training.sample_phrases.map((phrase, i) => (
                    <span key={i} className="px-2.5 py-1 bg-white/70 text-indigo-700 rounded-lg text-xs italic">
                      "{phrase}"
                    </span>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Call Info Card */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm space-y-3">
          <h3 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
            <Phone size={14} />
            Çağrı Bilgileri
          </h3>
          <div className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span className="text-gray-500">Durum</span>
              <span className={clsx('px-2 py-0.5 rounded text-xs font-medium', statusColors[call.status])}>
                {statusLabels[call.status] || call.status}
              </span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">Yön</span>
              <span>{call.direction === 'inbound' ? 'Gelen' : 'Giden'}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">Arayan</span>
              <span className="font-medium">{call.caller}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">Aranan</span>
              <span>{call.called}</span>
            </div>
            {call.extension && (
              <div className="flex justify-between">
                <span className="text-gray-500">Temsilci</span>
                <span>{call.agent_name ? `${call.agent_name} (${call.extension})` : call.extension}</span>
              </div>
            )}
            <div className="flex justify-between">
              <span className="text-gray-500">Süre</span>
              <span className="flex items-center gap-1">
                <Clock size={12} />
                {formatDuration(call.duration)}
              </span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">Çalma Süresi</span>
              <span>{formatDuration(call.ring_duration)}</span>
            </div>
          </div>
        </div>

        {/* Transcription */}
        <div className="md:col-span-2 bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-700 flex items-center gap-2 mb-3">
            <FileText size={14} />
            Transkript
          </h3>
          {call.transcription ? (
            <pre className="text-sm text-gray-700 whitespace-pre-wrap font-sans leading-relaxed bg-gray-50 rounded-lg p-4 max-h-96 overflow-y-auto">
              {call.transcription}
            </pre>
          ) : call.transcribed_at ? (
            <p className="text-sm text-gray-400">Transkript üretilemedi</p>
          ) : call.recording_url && call.status === 'answered' ? (
            <p className="text-sm text-gray-400 italic">Transkript bekleniyor...</p>
          ) : (
            <p className="text-sm text-gray-400">Kayıt yok</p>
          )}
        </div>
      </div>

      {/* No AI Analysis Fallback */}
      {!call.ai_summary && (
        <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-700 flex items-center gap-2 mb-2">
            <Brain size={14} />
            AI Analiz
          </h3>
          {call.transcribed_at ? (
            <p className="text-sm text-gray-400">AI analizi yapılamadı</p>
          ) : call.recording_url && call.status === 'answered' ? (
            <p className="text-sm text-gray-400 italic">Transkript ve analiz bekleniyor...</p>
          ) : (
            <p className="text-sm text-gray-400">Bu çağrı için kayıt bulunmuyor</p>
          )}
        </div>
      )}

      {/* Recording Player */}
      {call.recording_url && (
        <div className="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-700 mb-3">Ses Kaydı</h3>
          <audio controls className="w-full" src={`/api/calls/${call.id}/stream?token=${localStorage.getItem('auth_token')}`}>
            Tarayıcınız ses oynatmayı desteklemiyor.
          </audio>
        </div>
      )}
    </div>
  )
}
