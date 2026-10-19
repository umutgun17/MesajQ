import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '@/lib/api'
import {
  MessageSquare, Search, RefreshCw, ChevronDown, ChevronRight, User, Bot, Headphones,
  Users, ExternalLink, AlertTriangle, CheckCircle2, ShoppingBag, Inbox,
} from 'lucide-react'
import ChatProductCards, { type ChatProduct } from '@/components/ChatProductCards'

/* AI Chatbot — KONUŞMALAR (bkz docs/ai-chatbot.md).
   Ayarlar sayfasındaki "Analiz" sekmesinden farkı: burada amaç pilotta HER
   konuşmayı tek tek okumak. Sayfalama, metin araması ve sonuç filtresi var.
   Temsilciye devredilen konuşmalar ayrıca Mesajlar sayfasında "Site Chat"
   kanalı olarak açılır ve personel cevabını oradan verir. */

type Row = {
  id: number
  visitor: string | null
  visitor_id: string | null
  contact: { name?: string; email?: string; phone?: string }
  first_message: string
  detail: string | null
  escalation_reason: string | null
  user_messages: number
  bot_messages: number
  agent_messages: number
  total_messages: number
  status: string
  outcome: string
  page_url: string | null
  is_test: boolean
  started_at: string
  last_activity_at: string
  conversation_id: number | null
}

type Msg = {
  id: number
  role: string
  content: string
  products: ChatProduct[]
  created_at: string
}

type Rollout = {
  mode: string
  limit: number
  used: number
  remaining: number | null
  full: boolean
  wrote: number
  enabled: boolean
}

/* Devir sebepleri — konuşmayı okurken "bot niye cevaplamadı" sorusunun cevabı.
   Kodlar ChatbotService::gate() ve beginEscalation() ile birebir aynı. */
const REASONS: Record<string, { label: string; hint: string }> = {
  musteri_istegi: { label: 'Müşteri istedi', hint: 'Müşteri açıkça temsilciye bağlanmak istedi' },
  bilgi_yok: { label: 'Onaylı bilgi yok', hint: 'Soruya karşılık gelen onaylı kayıt bulunamadı' },
  dusuk_guven: { label: 'Düşük güven', hint: 'Modelin kendi güven puanı eşiğin altında kaldı' },
  kapsam_disi: { label: 'Kapsam dışı', hint: 'Botun cevaplamasına izin verilmeyen bir konu' },
  model_devretti: { label: 'Model devretti', hint: 'Model emin olmadığını bildirip devretti' },
  sayi_dogrulanamadi: { label: 'Sayı doğrulanamadı', hint: 'Cevapta dayanağı olmayan bir sayı vardı, cevap gösterilmedi' },
  saglayici_hatasi: { label: 'Servis hatası', hint: 'Yapay zeka servisi yanıt veremedi' },
  mesaj_limiti: { label: 'Sohbet uzadı', hint: 'Oturum mesaj sınırına ulaştı' },
  siparis_dogrulanamadi: { label: 'Sipariş doğrulanamadı', hint: 'Sipariş doğrulama deneme sınırı doldu' },
  bos_cevap: { label: 'Boş cevap', hint: 'Model kullanılabilir bir cevap üretemedi' },
}

const OUTCOMES: Record<string, { label: string; cls: string; Icon: typeof Bot }> = {
  cevaplandi: { label: 'Otomatik cevaplandı', cls: 'bg-emerald-100 text-emerald-700', Icon: CheckCircle2 },
  urun_onerildi: { label: 'Ürün önerildi', cls: 'bg-sky-100 text-sky-700', Icon: ShoppingBag },
  handoff: { label: 'Temsilciye devredildi', cls: 'bg-amber-100 text-amber-700', Icon: Headphones },
}

function fmt(d: string) {
  return new Date(d).toLocaleString('tr-TR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

export default function ChatConversationsPage() {
  const [rows, setRows] = useState<Row[]>([])
  const [rollout, setRollout] = useState<Rollout | null>(null)
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [days, setDays] = useState(30)
  const [outcome, setOutcome] = useState('')
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [includeTest, setIncludeTest] = useState(false)
  const [open, setOpen] = useState<number | null>(null)
  const [msgs, setMsgs] = useState<Record<number, Msg[]>>({})

  const load = useCallback(() => {
    setLoading(true)
    const params = new URLSearchParams({
      days: String(days),
      page: String(page),
      per_page: '25',
      include_test: includeTest ? '1' : '0',
    })
    if (outcome) params.set('outcome', outcome)
    if (search) params.set('q', search)

    api.get(`/chat/conversations?${params}`)
      .then((r) => {
        setRows(r.data.conversations || [])
        setLastPage(r.data.last_page || 1)
        setTotal(r.data.total || 0)
      })
      .finally(() => setLoading(false))
  }, [days, page, outcome, search, includeTest])

  useEffect(() => { load() }, [load])
  useEffect(() => { api.get('/chat/rollout').then((r) => setRollout(r.data)).catch(() => {}) }, [])

  const toggle = (id: number) => {
    if (open === id) { setOpen(null); return }
    setOpen(id)
    if (!msgs[id]) {
      api.get(`/chat/sessions/${id}/messages`)
        .then((r) => setMsgs((m) => ({ ...m, [id]: r.data.messages || [] })))
        .catch(() => setMsgs((m) => ({ ...m, [id]: [] })))
    }
  }

  const submitSearch = (e: React.FormEvent) => {
    e.preventDefault()
    setPage(1)
    setSearch(q.trim())
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-extrabold text-gray-900">Chatbot Konuşmaları</h1>
          <p className="text-xs text-gray-500 mt-0.5">
            Yapay zekanın ziyaretçilerle yaptığı tüm konuşmalar. Temsilciye devredilenler ayrıca
            Mesajlar sayfasında "Site Chat" kanalında açılır ve cevap oradan verilir.
          </p>
        </div>
        <button
          onClick={load}
          className="flex items-center gap-1.5 text-sm text-gray-600 hover:text-gray-900 border border-gray-200 rounded-lg px-3 py-1.5"
        >
          <RefreshCw size={14} className={loading ? 'animate-spin' : ''} /> Yenile
        </button>
      </div>

      {/* ── Kademeli açılım durumu ── */}
      {rollout && (
        <div className={`rounded-xl border p-4 flex items-start gap-3 ${
          rollout.mode === 'limited' && rollout.full
            ? 'border-amber-200 bg-amber-50'
            : 'border-gray-100 bg-white shadow-sm'
        }`}>
          <Users size={18} className="text-gray-500 mt-0.5 flex-none" />
          <div className="flex-1 text-sm">
            {!rollout.enabled ? (
              <span className="text-gray-700">Chatbot şu anda <b>kapalı</b> — sitede hiç görünmüyor.</span>
            ) : rollout.mode === 'all' ? (
              <span className="text-gray-700">Chatbot <b>tüm ziyaretçilere</b> açık.</span>
            ) : rollout.mode === 'off' ? (
              <span className="text-gray-700">Kademeli açılım <b>kapalı</b> — widget gösterilmiyor.</span>
            ) : (
              <span className="text-gray-700">
                Pilot modu: <b>{rollout.used} / {rollout.limit}</b> ziyaretçi kotayı kullandı
                {rollout.wrote > 0 && <> · <b>{rollout.wrote}</b> kişi gerçekten yazdı</>}.
                {rollout.full
                  ? ' Kota doldu — yeni ziyaretçilerde widget görünmüyor. Kotayı Ayarlar sayfasından büyütebilirsiniz.'
                  : ` Kalan ${rollout.remaining} kişilik yer var.`}
              </span>
            )}
          </div>
        </div>
      )}

      {/* ── Filtreler ── */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-wrap items-center gap-3">
        <form onSubmit={submitSearch} className="relative flex-1 min-w-[240px]">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Mesaj içeriği, isim, e-posta veya telefon ara..."
            className="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-400"
          />
        </form>

        <select
          value={outcome}
          onChange={(e) => { setPage(1); setOutcome(e.target.value) }}
          className="text-sm border border-gray-200 rounded-lg px-3 py-2"
        >
          <option value="">Tüm sonuçlar</option>
          <option value="otomatik">Yalnız otomatik cevaplananlar</option>
          <option value="devir">Yalnız temsilciye devredilenler</option>
        </select>

        <select
          value={days}
          onChange={(e) => { setPage(1); setDays(Number(e.target.value)) }}
          className="text-sm border border-gray-200 rounded-lg px-3 py-2"
        >
          <option value={7}>Son 7 gün</option>
          <option value={30}>Son 30 gün</option>
          <option value={90}>Son 90 gün</option>
          <option value={365}>Son 1 yıl</option>
        </select>

        <label className="flex items-center gap-1.5 text-sm text-gray-600">
          <input
            type="checkbox"
            checked={includeTest}
            onChange={(e) => { setPage(1); setIncludeTest(e.target.checked) }}
            className="rounded border-gray-300"
          />
          Test trafiğini de göster
        </label>
      </div>

      {/* ── Liste ── */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div className="px-4 py-2.5 border-b border-gray-100 text-xs text-gray-500 flex items-center gap-1.5">
          <MessageSquare size={13} /> {total} konuşma
        </div>

        {loading && rows.length === 0 && (
          <div className="p-10 text-center text-sm text-gray-400">Yükleniyor...</div>
        )}

        {!loading && rows.length === 0 && (
          <div className="p-10 text-center">
            <Inbox size={28} className="mx-auto text-gray-300" />
            <p className="mt-2 text-sm text-gray-500">Bu filtrelerde konuşma yok.</p>
          </div>
        )}

        <div className="divide-y divide-gray-100">
          {rows.map((r) => {
            const oc = OUTCOMES[r.outcome] || OUTCOMES.cevaplandi
            const reason = r.escalation_reason ? REASONS[r.escalation_reason] : null
            const isOpen = open === r.id
            return (
              <div key={r.id}>
                <button
                  onClick={() => toggle(r.id)}
                  className="w-full text-left px-4 py-3 hover:bg-gray-50 flex items-start gap-3"
                >
                  {isOpen ? <ChevronDown size={16} className="mt-1 text-gray-400 flex-none" />
                          : <ChevronRight size={16} className="mt-1 text-gray-400 flex-none" />}

                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-sm font-semibold text-gray-900">
                        {r.visitor || 'Anonim ziyaretçi'}
                      </span>
                      <span className={`text-[10px] font-bold rounded px-1.5 py-0.5 inline-flex items-center gap-1 ${oc.cls}`}>
                        <oc.Icon size={10} /> {oc.label}
                      </span>
                      {reason && (
                        <span
                          title={reason.hint}
                          className="text-[10px] font-medium rounded px-1.5 py-0.5 bg-gray-100 text-gray-600 inline-flex items-center gap-1"
                        >
                          <AlertTriangle size={10} /> {reason.label}
                        </span>
                      )}
                      {r.is_test && (
                        <span className="text-[10px] font-bold rounded px-1.5 py-0.5 bg-purple-100 text-purple-700">TEST</span>
                      )}
                    </div>

                    <p className="mt-0.5 text-sm text-gray-600 truncate">{r.first_message || '—'}</p>

                    <div className="mt-1 flex items-center gap-3 text-[11px] text-gray-400 flex-wrap">
                      <span>{fmt(r.started_at)}</span>
                      <span>{r.user_messages} müşteri · {r.bot_messages} bot{r.agent_messages > 0 && ` · ${r.agent_messages} personel`}</span>
                      {r.contact?.email && <span>{r.contact.email}</span>}
                      {r.contact?.phone && <span>{r.contact.phone}</span>}
                    </div>
                  </div>
                </button>

                {isOpen && (
                  <div className="px-4 pb-4 bg-gray-50/70">
                    {r.detail && (
                      <div className="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <p className="text-[11px] font-bold text-amber-800 uppercase tracking-wide">Müşterinin yazdığı detay</p>
                        <p className="mt-1 text-sm text-amber-900 whitespace-pre-wrap">{r.detail}</p>
                      </div>
                    )}

                    {r.conversation_id && (
                      <Link
                        to={`/messages?conversation=${r.conversation_id}`}
                        className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-800"
                      >
                        <ExternalLink size={12} /> Mesajlar sayfasında aç (#{r.conversation_id})
                      </Link>
                    )}

                    <div className="space-y-2">
                      {(msgs[r.id] || []).filter((m) => m.role !== 'system').map((m) => {
                        const mine = m.role === 'user'
                        const agent = m.role === 'agent'
                        return (
                          <div key={m.id} className={`flex gap-2 ${mine ? 'justify-end' : ''}`}>
                            {!mine && (
                              <span className={`mt-1 flex-none w-6 h-6 rounded-full flex items-center justify-center ${agent ? 'bg-emerald-100 text-emerald-700' : 'bg-white border border-gray-200 text-gray-500'}`}>
                                {agent ? <Headphones size={12} /> : <Bot size={12} />}
                              </span>
                            )}
                            <div className={`max-w-[75%] rounded-xl px-3 py-2 text-sm ${
                              mine ? 'bg-indigo-600 text-white'
                                   : agent ? 'bg-emerald-50 border border-emerald-200 text-gray-800'
                                           : 'bg-white border border-gray-200 text-gray-800'
                            }`}>
                              <p className="whitespace-pre-wrap">{m.content}</p>
                              <ChatProductCards products={m.products} />
                            </div>
                            {mine && (
                              <span className="mt-1 flex-none w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center">
                                <User size={12} />
                              </span>
                            )}
                          </div>
                        )
                      })}
                      {msgs[r.id] && msgs[r.id].length === 0 && (
                        <p className="text-sm text-gray-400">Transkript yüklenemedi.</p>
                      )}
                    </div>
                  </div>
                )}
              </div>
            )
          })}
        </div>

        {lastPage > 1 && (
          <div className="px-4 py-3 border-t border-gray-100 flex items-center justify-between text-sm">
            <button
              disabled={page <= 1}
              onClick={() => setPage((p) => p - 1)}
              className="px-3 py-1.5 rounded-lg border border-gray-200 disabled:opacity-40"
            >
              Önceki
            </button>
            <span className="text-gray-500">{page} / {lastPage}</span>
            <button
              disabled={page >= lastPage}
              onClick={() => setPage((p) => p + 1)}
              className="px-3 py-1.5 rounded-lg border border-gray-200 disabled:opacity-40"
            >
              Sonraki
            </button>
          </div>
        )}
      </div>
    </div>
  )
}
