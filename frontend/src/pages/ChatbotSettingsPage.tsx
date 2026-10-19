import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import api from '@/lib/api'
import { Bot, Save, CheckCircle, Copy, Check, ExternalLink, MessageSquare, ShieldAlert, Palette, BarChart3, Settings, ChevronDown, ChevronRight, User, Headphones, Users, Clock, ShieldCheck, AlertTriangle } from 'lucide-react'

/* AI Chatbot — site içi sohbet asistanı ayarları (bkz docs/ai-chatbot.md).
   Ayarlar tenants.settings düz anahtarlarında tutulur (shipping widget ile aynı model).
   Handoff edilen konuşmalar Mesajlar sayfasına "Site Chat" (webchat) kanalı olarak düşer.
   Şablon/renk değişikliği kaydedince ANINDA yayına geçer (config endpoint'i canlı okur). */

const TEMPLATES: { key: string; name: string; desc: string }[] = [
  { key: 'modern', name: 'Modern', desc: 'Yuvarlak köşeler, renkli başlık — dengeli varsayılan' },
  { key: 'minimal', name: 'Minimal', desc: 'Köşeli, beyaz başlık, ince vurgu çizgisi — sade temalar' },
  { key: 'soft', name: 'Soft', desc: 'Ekstra yuvarlak, gradyan başlık — canlı/genç markalar' },
  { key: 'elegant', name: 'Elegant', desc: 'Beyaz başlık, renkli avatar, yumuşak renkli zemin — premium görünüm' },
]

const DAY_NAMES = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar']

/** Mesai saatleri varsayılanı — ChatBusinessHours::DEFAULT_HOURS ile aynı olmalı */
const DEFAULT_HOURS: ({ open: string; close: string } | null)[] = [
  { open: '09:00', close: '18:00' },
  { open: '09:00', close: '18:00' },
  { open: '09:00', close: '18:00' },
  { open: '09:00', close: '18:00' },
  { open: '09:00', close: '18:00' },
  { open: '09:00', close: '14:00' },
  null,
]

const PRESET_COLORS = ['#1f2937', '#4f46e5', '#0ea5e9', '#059669', '#dc2626', '#ea580c', '#db2777', '#7c3aed']

/** Soft şablonun gradyanı için koyu ton (widget'taki shade() ile aynı mantık) */
function shade(hex: string, amt: number): string {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || '')
  if (!m) return hex
  const n = parseInt(m[1], 16)
  const r = Math.round(((n >> 16) & 255) * (1 - amt))
  const g = Math.round(((n >> 8) & 255) * (1 - amt))
  const b = Math.round((n & 255) * (1 - amt))
  return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1)
}

/** Saf-CSS mini mockup — şablonun panel görünümünü küçük ölçekte çizer (search TplMock deseni) */
function TplMock({ tpl, color }: { tpl: string; color: string }) {
  const radius = tpl === 'minimal' ? 3 : tpl === 'soft' ? 12 : tpl === 'elegant' ? 9 : 7
  const msgRadius = tpl === 'minimal' ? 2 : tpl === 'soft' ? 9 : 6
  const headerStyle =
    tpl === 'minimal'
      ? { background: '#fff', borderBottom: `2.5px solid ${color}` }
      : tpl === 'elegant'
        ? { background: '#fff', borderBottom: '1px solid #eef0f2' }
        : tpl === 'soft'
          ? { background: `linear-gradient(135deg, ${color}, ${shade(color, 0.35)})` }
          : { background: color }
  const bodyBg = tpl === 'minimal' ? '#fff' : tpl === 'elegant' ? `${color}0D` : '#f4f5f7'
  return (
    <div className="w-full h-28 rounded-lg bg-gray-50 border border-gray-200 flex items-end justify-end p-2 overflow-hidden">
      <div className="w-24 bg-white shadow-md flex flex-col overflow-hidden" style={{ borderRadius: radius, height: '96px' }}>
        <div className="h-5 flex items-center gap-1 px-1.5 flex-none" style={headerStyle}>
          <span className="w-2.5 h-2.5 rounded-full flex-none" style={{ background: tpl === 'minimal' || tpl === 'elegant' ? color : 'rgba(255,255,255,.35)' }} />
          <span className="h-1 w-8 rounded-full" style={{ background: tpl === 'minimal' || tpl === 'elegant' ? '#9ca3af' : 'rgba(255,255,255,.8)' }} />
        </div>
        <div className="flex-1 p-1.5 space-y-1" style={{ background: bodyBg }}>
          <div className="h-3 w-14 border border-gray-200 bg-white" style={{ borderRadius: msgRadius, background: tpl === 'minimal' ? '#f3f4f6' : '#fff', marginLeft: tpl === 'elegant' ? 8 : 0 }} />
          <div className="h-3 w-12 ml-auto" style={{ borderRadius: msgRadius, background: tpl === 'soft' ? `linear-gradient(135deg, ${color}, ${shade(color, 0.35)})` : color }} />
          <div className="h-3 w-16 border border-gray-200" style={{ borderRadius: msgRadius, background: tpl === 'minimal' ? '#f3f4f6' : '#fff' }} />
        </div>
        <div className="h-4 border-t border-gray-100 flex items-center gap-1 px-1.5 flex-none bg-white">
          <span className="h-1.5 flex-1 rounded-full bg-gray-100" />
          <span className="w-2 h-2 flex-none" style={{ borderRadius: tpl === 'minimal' ? 2 : 99, background: color }} />
        </div>
      </div>
    </div>
  )
}

export default function ChatbotSettingsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [tenantSettings, setTenantSettings] = useState<Record<string, string>>({})
  const [tenantSlug, setTenantSlug] = useState('')
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [copied, setCopied] = useState(false)
  const [tab, setTab] = useState<'settings' | 'analytics'>('settings')
  const [rollout, setRollout] = useState<{ used: number; limit: number; wrote: number; full: boolean; mode: string } | null>(null)
  const [resetting, setResetting] = useState(false)

  useEffect(() => {
    api.get('/settings')
      .then((res) => {
        setTenantSettings(res.data.settings || {})
        if (res.data.tenant?.slug) setTenantSlug(res.data.tenant.slug)
      })
      .catch(() => {
        if (user?.tenant?.settings) setTenantSettings(user.tenant.settings as Record<string, string>)
      })
  }, [user])

  const loadRollout = () => {
    api.get('/chat/rollout').then((r) => setRollout(r.data)).catch(() => {})
  }
  useEffect(loadRollout, [])

  const handleResetRollout = async () => {
    if (!confirm('Pilot kohortu sıfırlanacak: şu ana kadar botu gören ziyaretçiler unutulacak ve kota baştan dolmaya başlayacak. Konuşma geçmişi silinmez. Devam edilsin mi?')) return
    setResetting(true)
    try {
      await api.post('/chat/rollout/reset')
      loadRollout()
    } finally {
      setResetting(false)
    }
  }

  const handleSave = async () => {
    setSaving(true)
    setSaved(false)
    try {
      await api.put('/settings', { settings: tenantSettings })
      setSaved(true)
      setTimeout(() => setSaved(false), 3000)
    } finally {
      setSaving(false)
    }
  }

  const updateSetting = (key: string, value: string) => {
    setTenantSettings((prev) => ({ ...prev, [key]: value }))
  }

  const enabled = tenantSettings.chat_widget_enabled === 'true' || tenantSettings.chat_widget_enabled === '1'
  const handoffOn = tenantSettings.chat_handoff_enabled !== 'false' && tenantSettings.chat_handoff_enabled !== '0'
  const slug = tenantSlug || user?.tenant?.slug || 'TENANT_SLUG'
  const themeColor = tenantSettings.chat_theme_color || '#1f2937'
  const template = TEMPLATES.some((x) => x.key === tenantSettings.chat_widget_template)
    ? tenantSettings.chat_widget_template
    : 'modern'
  const botName = tenantSettings.chat_bot_name || 'AI Asistan'
  const greeting = tenantSettings.chat_greeting
    || 'Merhaba! Ben yapay zeka destekli asistanım. Ürün önerisi, sipariş durumu ve sorularınız için buradayım. Size nasıl yardımcı olabilirim?'

  // DİKKAT: chat.js her değiştiğinde v artmalı — Cloudflare edge TTL origin max-age'i
  // eziyor, eski sürüm saatlerce serve edilebiliyor (çark v=7 / arama ?v dersi).
  const embedCode = `<script src="https://hub.yunbi.ai/widget/chat.js?v=13"\n        data-tenant="${slug}" defer></script>`

  const previewHeaderStyle =
    template === 'minimal'
      ? { background: '#fff', color: '#111827', borderBottom: `3px solid ${themeColor}` }
      : template === 'elegant'
        ? { background: '#fff', color: '#111827', borderBottom: '1px solid #eef0f2' }
        : template === 'soft'
          ? { background: `linear-gradient(135deg, ${themeColor}, ${shade(themeColor, 0.35)})`, color: '#fff' }
          : { background: themeColor, color: '#fff' }
  const previewRadius = template === 'minimal' ? 8 : template === 'soft' ? 20 : template === 'elegant' ? 16 : 14
  const previewMsgRadius = template === 'minimal' ? 6 : template === 'soft' ? 16 : 12
  const previewBodyBg = template === 'minimal' ? '#fff' : template === 'elegant' ? `${themeColor}0D` : '#f6f7f9'
  const headerOnWhite = template === 'minimal' || template === 'elegant'
  const greetingStyle = tenantSettings.chat_greeting_style === 'teaser' ? 'teaser' : 'panel'
  const launcherStyle = tenantSettings.chat_launcher_style === 'tab' ? 'tab' : 'bubble'
  const launcherBg = template === 'soft' ? `linear-gradient(135deg, ${themeColor}, ${shade(themeColor, 0.35)})` : themeColor
  const teaserPreviewText = (tenantSettings.chat_teaser_text || greeting).slice(0, 80)

  // ── Kademeli açılım / doğruluk / mesai ──
  const rolloutMode = ['off', 'limited', 'all'].includes(tenantSettings.chat_rollout_mode)
    ? tenantSettings.chat_rollout_mode
    : 'limited'
  const rolloutLimit = tenantSettings.chat_rollout_limit ?? '10'
  const answerStrict = tenantSettings.chat_answer_mode !== 'open'
  const askDetail = tenantSettings.chat_ask_detail !== 'false'
  const allowFaq = tenantSettings.chat_allow_faq !== 'false'
  const allowOrder = tenantSettings.chat_allow_order_status !== 'false'
  const allowProducts = tenantSettings.chat_allow_products !== 'false'
  const minConfidence = Number(tenantSettings.chat_min_confidence ?? '0.75')
  const hoursEnabled = tenantSettings.chat_hours_enabled !== 'false'

  let hours: ({ open: string; close: string } | null)[] = DEFAULT_HOURS
  try {
    const parsed = tenantSettings.chat_hours ? JSON.parse(tenantSettings.chat_hours) : null
    if (Array.isArray(parsed) && parsed.length === 7) hours = parsed
  } catch { /* bozuk değer → varsayılan */ }

  const setHours = (next: typeof hours) => updateSetting('chat_hours', JSON.stringify(next))
  const setDay = (i: number, slot: { open: string; close: string } | null) => {
    const next = hours.slice()
    next[i] = slot
    setHours(next)
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-extrabold text-gray-900">AI Chatbot</h1>
        <a
          href={`https://hub.yunbi.ai/widget/chat-demo.html?cb=${Date.now()}&template=${template}`}
          target="_blank"
          rel="noopener"
          className="flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800"
        >
          <ExternalLink size={14} /> Canlı önizleme
        </a>
      </div>

      <div className="flex gap-1 border-b border-gray-200">
        <button
          type="button"
          onClick={() => setTab('settings')}
          className={`flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${tab === 'settings' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700'}`}
        >
          <Settings size={14} /> Ayarlar
        </button>
        <button
          type="button"
          onClick={() => setTab('analytics')}
          className={`flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${tab === 'analytics' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700'}`}
        >
          <BarChart3 size={14} /> Analiz
        </button>
      </div>

      {tab === 'analytics' && <ChatAnalytics />}

      {tab === 'settings' && (
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 className="font-semibold text-gray-900 mb-1 flex items-center gap-2">
          <Bot size={18} /> Sohbet Asistanı
        </h2>
        <p className="text-xs text-gray-500 mb-4">
          Sitenizin sağ altında sohbet balonu: yapay zeka asistanı ürün önerir (kartlı),
          sipariş/kargo durumunu doğrulamalı yanıtlar, iade-kargo sorularını bilgi bankanızdan
          cevaplar. Yetersiz kaldığında konuşmayı müşteri temsilcisine devreder — konuşma
          Mesajlar sayfanıza "Site Chat" kanalı olarak düşer. Asistanın yapay zeka olduğu
          müşteriye açıkça gösterilir.
        </p>

        <div className="space-y-5">
          <div className="flex items-center justify-between">
            <div>
              <label className="block text-sm font-medium text-gray-700">Chatbot Aktif</label>
              <p className="text-xs text-gray-500">Kapalıyken balon hiçbir sayfada görünmez.</p>
            </div>
            <button
              type="button"
              onClick={() => updateSetting('chat_widget_enabled', enabled ? 'false' : 'true')}
              className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${enabled ? 'bg-indigo-600' : 'bg-gray-200'}`}
            >
              <span className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${enabled ? 'translate-x-6' : 'translate-x-1'}`} />
            </button>
          </div>

          {enabled && (
            <>
              {/* ── Kademeli açılım ────────────────────────────────────────
                  Chatbot son tüketiciye doğrudan görünüyor; herkese birden
                  açmak yerine küçük bir grupla başlanır, konuşmalar okunur,
                  sonra kota büyütülür. Bkz ChatRollout. */}
              <div className="rounded-xl border-2 border-indigo-200 bg-indigo-50/40 p-4">
                <label className="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                  <Users size={15} /> Kademeli Açılım
                </label>
                <p className="text-xs text-gray-600 mt-1 mb-3">
                  Chatbot müşterilerinize doğrudan görünür. Önce küçük bir grupla başlayın,
                  konuşmaları <b>Chatbot Konuşmaları</b> sayfasından okuyun, sorun yoksa kotayı
                  büyütün. Kota dolduğunda yeni ziyaretçilerde balon hiç görünmez; kotaya girmiş
                  ziyaretçiler etkilenmez. Kota yalnızca <b>yazan</b> ziyaretçileri sayar.
                </p>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-3">
                  {[
                    { key: 'limited', title: 'Pilot (kotalı)', desc: 'Yalnız belirlediğiniz sayıda ziyaretçi görür' },
                    { key: 'all', title: 'Herkese açık', desc: 'Tüm ziyaretçiler görür — kota yok' },
                    { key: 'off', title: 'Duraklat', desc: 'Widget hiç gösterilmez (ayarlar korunur)' },
                  ].map((m) => (
                    <button
                      key={m.key}
                      type="button"
                      onClick={() => updateSetting('chat_rollout_mode', m.key)}
                      className={`text-left rounded-lg border-2 p-3 transition-colors ${
                        rolloutMode === m.key ? 'border-indigo-600 bg-white' : 'border-gray-200 bg-white/60 hover:border-gray-300'
                      }`}
                    >
                      <span className="text-sm font-semibold text-gray-900">{m.title}</span>
                      <p className="mt-0.5 text-[11px] text-gray-500 leading-snug">{m.desc}</p>
                    </button>
                  ))}
                </div>

                {rolloutMode === 'limited' && (
                  <div className="flex flex-wrap items-end gap-3">
                    <div>
                      <label className="block text-xs font-medium text-gray-700 mb-1">Kaç ziyaretçi görsün?</label>
                      <input
                        type="number"
                        min={0}
                        value={rolloutLimit}
                        onChange={(e) => updateSetting('chat_rollout_limit', e.target.value)}
                        className="w-28 px-3 py-2 text-sm border border-gray-200 rounded-lg"
                      />
                    </div>
                    <div className="flex gap-1.5">
                      {[10, 50, 100, 500].map((n) => (
                        <button
                          key={n}
                          type="button"
                          onClick={() => updateSetting('chat_rollout_limit', String(n))}
                          className={`px-2.5 py-2 text-xs rounded-lg border ${
                            String(n) === String(rolloutLimit) ? 'border-indigo-600 bg-indigo-50 text-indigo-700 font-semibold' : 'border-gray-200 bg-white text-gray-600'
                          }`}
                        >
                          {n}
                        </button>
                      ))}
                    </div>
                    {rollout && (
                      <div className="flex items-center gap-3 text-xs">
                        <span className={rollout.full ? 'text-amber-700 font-semibold' : 'text-gray-600'}>
                          Şu an: <b>{rollout.used}</b> ziyaretçi kotayı kullandı
                          {rollout.wrote > 0 && <> · {rollout.wrote} kişi yazdı</>}
                          {rollout.full && ' · KOTA DOLU'}
                        </span>
                        <button
                          type="button"
                          onClick={handleResetRollout}
                          disabled={resetting}
                          className="text-gray-500 hover:text-red-600 underline disabled:opacity-50"
                        >
                          kohortu sıfırla
                        </button>
                      </div>
                    )}
                  </div>
                )}
              </div>

              {/* ── Doğruluk kapıları ────────────────────────────────────── */}
              <div className="rounded-xl border border-gray-200 p-4">
                <label className="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                  <ShieldCheck size={15} /> Doğruluk ve Kapsam
                </label>
                <p className="text-xs text-gray-600 mt-1 mb-3">
                  Bot yalnız aşağıda izin verdiğiniz konularda cevap verir. Emin olamadığı,
                  onaylı bir kaynağa dayanmayan veya izin verilmeyen her şeyi müşteri
                  temsilcisine devreder.
                </p>

                <div className="space-y-2.5">
                  {[
                    { key: 'chat_answer_mode', on: answerStrict, onVal: 'strict', offVal: 'open',
                      title: 'Yalnız onaylı bilgiyle cevap ver',
                      desc: 'Cevap; bilgi bankanız, personelin hazır cevapları veya sistem verilerinden birine dayanmak zorunda. Kapatırsanız bot kendi genel bilgisiyle de cevap verebilir — ÖNERİLMEZ.' },
                    { key: 'chat_allow_faq', on: allowFaq, onVal: 'true', offVal: 'false',
                      title: 'Ürün ve mağaza sorularını cevaplasın',
                      desc: 'Kargo, iade, beden/numara, ürün özellikleri, ödeme, garanti, kampanya' },
                    { key: 'chat_allow_order_status', on: allowOrder, onVal: 'true', offVal: 'false',
                      title: 'Sipariş ve kargo durumunu söylesin',
                      desc: 'Sipariş no + e-posta/telefon doğrulamasıyla, veritabanından okunan gerçek durum' },
                    { key: 'chat_allow_products', on: allowProducts, onVal: 'true', offVal: 'false',
                      title: 'Ürün önerisi göstersin',
                      desc: 'Sohbette görselli, fiyatlı ürün kartları' },
                    { key: 'chat_ask_detail', on: askDetail, onVal: 'true', offVal: 'false',
                      title: 'Devretmeden önce sorunun detayını istesin',
                      desc: 'Temsilciye aktarmadan önce müşteriden talebini açıklamasını ister; detay konuşmayla birlikte personele iletilir' },
                  ].map((row) => (
                    <div key={row.key} className="flex items-start justify-between gap-4">
                      <div className="flex-1">
                        <span className="text-sm font-medium text-gray-800">{row.title}</span>
                        <p className="text-[11px] text-gray-500 leading-snug">{row.desc}</p>
                      </div>
                      <button
                        type="button"
                        onClick={() => updateSetting(row.key, row.on ? row.offVal : row.onVal)}
                        className={`mt-1 relative inline-flex h-5 w-9 flex-none items-center rounded-full transition-colors ${row.on ? 'bg-indigo-600' : 'bg-gray-200'}`}
                      >
                        <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform ${row.on ? 'translate-x-5' : 'translate-x-1'}`} />
                      </button>
                    </div>
                  ))}
                </div>

                <div className="mt-4 pt-3 border-t border-gray-100">
                  <label className="block text-sm font-medium text-gray-800">
                    Güven eşiği: <span className="text-indigo-700 font-bold">{Math.round(minConfidence * 100)}%</span>
                  </label>
                  <p className="text-[11px] text-gray-500 mb-2">
                    Bot bu eşiğin altında kalan her cevabı göstermez, temsilciye devreder.
                    Yükseltirseniz daha az yanlış cevap, daha çok devir olur.
                  </p>
                  <input
                    type="range"
                    min={0.4}
                    max={0.95}
                    step={0.05}
                    value={minConfidence}
                    onChange={(e) => updateSetting('chat_min_confidence', e.target.value)}
                    className="w-full max-w-md accent-indigo-600"
                  />
                  <div className="flex justify-between max-w-md text-[10px] text-gray-400">
                    <span>Daha çok cevap</span>
                    <span>Daha çok doğruluk</span>
                  </div>
                </div>

                {!answerStrict && (
                  <div className="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 p-2.5">
                    <AlertTriangle size={14} className="text-amber-600 mt-0.5 flex-none" />
                    <p className="text-[11px] text-amber-800">
                      "Yalnız onaylı bilgiyle cevap ver" kapalı. Bot mağazanız hakkında
                      doğrulanmamış bilgi verebilir. Canlı müşteri trafiğinde açık tutmanız önerilir.
                    </p>
                  </div>
                )}
              </div>

              {/* ── Mesai saatleri ──────────────────────────────────────── */}
              <div className="rounded-xl border border-gray-200 p-4">
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <label className="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                      <Clock size={15} /> Müşteri Hizmetleri Mesai Saatleri
                    </label>
                    <p className="text-xs text-gray-600 mt-1">
                      Temsilciye devirde müşteriye doğru beklenti verilir: mesai içindeyse
                      "kısa süre içinde dönüş yapılacak", dışındaysa ilk açılış saati söylenir.
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => updateSetting('chat_hours_enabled', hoursEnabled ? 'false' : 'true')}
                    className={`mt-1 relative inline-flex h-6 w-11 flex-none items-center rounded-full transition-colors ${hoursEnabled ? 'bg-indigo-600' : 'bg-gray-200'}`}
                  >
                    <span className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${hoursEnabled ? 'translate-x-6' : 'translate-x-1'}`} />
                  </button>
                </div>

                {hoursEnabled && (
                  <div className="mt-3 space-y-1.5 max-w-md">
                    {DAY_NAMES.map((name, i) => {
                      const slot = hours[i]
                      return (
                        <div key={name} className="flex items-center gap-2">
                          <span className="w-24 text-sm text-gray-700">{name}</span>
                          <button
                            type="button"
                            onClick={() => setDay(i, slot ? null : { open: '09:00', close: '18:00' })}
                            className={`text-[11px] font-medium rounded px-2 py-1 border ${
                              slot ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-gray-50 text-gray-500'
                            }`}
                          >
                            {slot ? 'Açık' : 'Kapalı'}
                          </button>
                          {slot && (
                            <>
                              <input
                                type="time"
                                value={slot.open}
                                onChange={(e) => setDay(i, { ...slot, open: e.target.value })}
                                className="px-2 py-1 text-sm border border-gray-200 rounded"
                              />
                              <span className="text-gray-400 text-sm">–</span>
                              <input
                                type="time"
                                value={slot.close}
                                onChange={(e) => setDay(i, { ...slot, close: e.target.value })}
                                className="px-2 py-1 text-sm border border-gray-200 rounded"
                              />
                            </>
                          )}
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>

              {/* ── Tasarım şablonu ── */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2 flex items-center gap-1.5">
                  <Palette size={14} /> Tasarım Şablonu
                </label>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 max-w-4xl">
                  {TEMPLATES.map((tp) => (
                    <button
                      key={tp.key}
                      type="button"
                      onClick={() => updateSetting('chat_widget_template', tp.key)}
                      className={`text-left rounded-xl border-2 p-3 transition-colors ${
                        template === tp.key ? 'border-indigo-600 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300 bg-white'
                      }`}
                    >
                      <TplMock tpl={tp.key} color={themeColor} />
                      <div className="mt-2 flex items-center gap-2">
                        <span className="text-sm font-semibold text-gray-900">{tp.name}</span>
                        {template === tp.key && (
                          <span className="text-[10px] font-bold text-indigo-700 bg-indigo-100 rounded px-1.5 py-0.5">SEÇİLİ</span>
                        )}
                      </div>
                      <p className="mt-0.5 text-[11px] text-gray-500 leading-snug">{tp.desc}</p>
                    </button>
                  ))}
                </div>
              </div>

              {/* ── Renk ── */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Tema Rengi</label>
                <div className="flex items-center gap-2 flex-wrap">
                  {PRESET_COLORS.map((c) => (
                    <button
                      key={c}
                      type="button"
                      onClick={() => updateSetting('chat_theme_color', c)}
                      className={`w-8 h-8 rounded-full border-2 transition-transform hover:scale-110 ${
                        themeColor.toLowerCase() === c ? 'border-gray-900 ring-2 ring-offset-1 ring-gray-300' : 'border-white shadow'
                      }`}
                      style={{ background: c }}
                      title={c}
                    />
                  ))}
                  <span className="mx-1 h-6 w-px bg-gray-200" />
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="color"
                      value={themeColor}
                      onChange={(e) => updateSetting('chat_theme_color', e.target.value)}
                      className="h-8 w-10 border border-gray-200 rounded cursor-pointer"
                    />
                    <span className="text-xs text-gray-500 font-mono">{themeColor}</span>
                  </label>
                </div>
                <p className="mt-1 text-xs text-gray-500">Hazır renklerden seçin veya markanızın rengini girin. Balon, başlık ve butonlara uygulanır.</p>
              </div>

              {/* ── Kimlik + canlı önizleme ── */}
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Asistan Adı</label>
                    <input
                      type="text"
                      value={tenantSettings.chat_bot_name || ''}
                      onChange={(e) => updateSetting('chat_bot_name', e.target.value)}
                      placeholder="AI Asistan"
                      maxLength={40}
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <p className="mt-1 text-xs text-gray-500">Örn: "Tonny", "Yardımcı Ayı", "Moda Asistanı". Yanında her zaman AI rozeti görünür.</p>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Karşılama Mesajı</label>
                    <textarea
                      rows={3}
                      value={tenantSettings.chat_greeting || ''}
                      onChange={(e) => updateSetting('chat_greeting', e.target.value)}
                      placeholder={greeting}
                      maxLength={300}
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <p className="mt-1 text-xs text-gray-500">Boş bırakılırsa varsayılan metin kullanılır. Yapay zeka olduğunu belirtmenizi öneririz (şeffaflık).</p>
                  </div>
                </div>

                {/* Canlı önizleme — seçilen şablon+renk+isim+karşılama */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Önizleme <span className="font-normal text-gray-400">(pencere + kutucuk + ilk mesaj)</span></label>
                  <div className="relative bg-gray-100 rounded-xl p-4 pr-20 pb-16 flex justify-center min-h-[380px]">
                    <div className="w-64 bg-white shadow-lg overflow-hidden" style={{ borderRadius: previewRadius }}>
                      <div className="px-3 py-2.5 flex items-center gap-2" style={previewHeaderStyle}>
                        <span
                          className="w-7 h-7 rounded-full flex items-center justify-center flex-none"
                          style={{ background: headerOnWhite ? themeColor : 'rgba(255,255,255,.2)' }}
                        >
                          <Bot size={14} color="#fff" />
                        </span>
                        <span className="min-w-0">
                          <span className="text-[13px] font-bold flex items-center gap-1.5 truncate">
                            {botName}
                            <span
                              className="text-[8px] font-extrabold rounded px-1 py-px"
                              style={headerOnWhite
                                ? { background: themeColor, color: '#fff' }
                                : { background: 'rgba(255,255,255,.25)', color: '#fff' }}
                            >
                              AI
                            </span>
                          </span>
                          <span className="block text-[10px] opacity-80">Yapay zeka destekli asistan</span>
                        </span>
                      </div>
                      <div className="p-3 space-y-2" style={{ background: previewBodyBg }}>
                        <div
                          className="text-[11px] leading-snug text-gray-800 px-2.5 py-2 max-w-[85%]"
                          style={{
                            borderRadius: previewMsgRadius,
                            background: template === 'minimal' ? '#f3f4f6' : '#fff',
                            border: template === 'minimal' ? 'none' : '1px solid #e5e7eb',
                            marginLeft: template === 'elegant' ? 14 : 0,
                          }}
                        >
                          {greeting.length > 120 ? greeting.slice(0, 120) + '…' : greeting}
                        </div>
                        <div
                          className="text-[11px] text-white px-2.5 py-1.5 ml-auto w-fit"
                          style={{
                            borderRadius: previewMsgRadius,
                            background: template === 'soft' ? `linear-gradient(135deg, ${themeColor}, ${shade(themeColor, 0.35)})` : themeColor,
                          }}
                        >
                          Merhaba!
                        </div>
                      </div>
                      <div className="px-3 py-2 border-t border-gray-100 flex items-center gap-2 bg-white">
                        <span className="flex-1 h-7 rounded-full bg-gray-50 border border-gray-200" />
                        <span
                          className="w-7 h-7 flex-none flex items-center justify-center"
                          style={{
                            borderRadius: template === 'minimal' ? 6 : 99,
                            background: template === 'soft' ? `linear-gradient(135deg, ${themeColor}, ${shade(themeColor, 0.35)})` : themeColor,
                          }}
                        >
                          <svg viewBox="0 0 24 24" width="12" height="12" fill="#fff"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" /></svg>
                        </span>
                      </div>
                    </div>

                    {/* Kutucuk (başlatıcı) önizlemesi */}
                    {launcherStyle === 'tab' ? (
                      <div
                        className="absolute right-0 top-1/2 -translate-y-1/2 w-9 flex flex-col items-center py-2.5 gap-1.5 shadow-lg"
                        style={{ background: launcherBg, height: 150, borderRadius: '12px 0 0 12px' }}
                      >
                        <span className="w-2 h-2 rounded-full bg-emerald-400" style={{ boxShadow: '0 0 0 2.5px rgba(52,211,153,.35)' }} />
                        <span
                          className="flex-1 flex items-center justify-center text-[10px] font-bold text-white tracking-wide overflow-hidden"
                          style={{ writingMode: 'vertical-rl', transform: 'rotate(180deg)' }}
                        >
                          {botName}
                        </span>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="#fff"><path d="M12 3C6.5 3 2 6.9 2 11.8c0 2.5 1.2 4.8 3.1 6.4-.1 1-.6 2.4-1.5 3.3 0 0 2.3-.2 4.2-1.5 1.3.4 2.7.6 4.2.6 5.5 0 10-3.9 10-8.8S17.5 3 12 3z" /></svg>
                      </div>
                    ) : (
                      <span
                        className="absolute right-4 bottom-4 w-11 h-11 rounded-full flex items-center justify-center shadow-lg"
                        style={{ background: launcherBg }}
                      >
                        <svg viewBox="0 0 24 24" width="19" height="19" fill="#fff"><path d="M12 3C6.5 3 2 6.9 2 11.8c0 2.5 1.2 4.8 3.1 6.4-.1 1-.6 2.4-1.5 3.3 0 0 2.3-.2 4.2-1.5 1.3.4 2.7.6 4.2.6 5.5 0 10-3.9 10-8.8S17.5 3 12 3z" /></svg>
                      </span>
                    )}

                    {/* İlk mesaj (teaser) önizlemesi */}
                    {greetingStyle === 'teaser' && (
                      <div
                        className={`absolute bg-white shadow-md border border-gray-100 rounded-xl px-3 py-2 text-[10.5px] leading-snug text-gray-800 max-w-[180px] ${
                          launcherStyle === 'tab' ? 'right-11 top-1/2 -translate-y-1/2' : 'right-[68px] bottom-5'
                        }`}
                      >
                        {teaserPreviewText}
                        <span className="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-gray-100 border border-gray-200 text-gray-400 text-[9px] leading-[13px] text-center">×</span>
                      </div>
                    )}
                  </div>
                </div>
              </div>

              {/* ── Kutucuk (başlatıcı) görünümü ── */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Kutucuk Konumu ve Görünümü</label>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-2xl">
                  <button
                    type="button"
                    onClick={() => updateSetting('chat_launcher_style', 'bubble')}
                    className={`text-left rounded-xl border-2 p-3 transition-colors ${launcherStyle === 'bubble' ? 'border-indigo-600 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300 bg-white'}`}
                  >
                    <div className="relative w-full h-20 rounded-lg bg-gray-50 border border-gray-200 overflow-hidden">
                      <span className="absolute right-2 bottom-2 w-8 h-8 rounded-full shadow" style={{ background: themeColor }} />
                    </div>
                    <div className="mt-2 flex items-center gap-2">
                      <span className="text-sm font-semibold text-gray-900">Yuvarlak balon</span>
                      {launcherStyle === 'bubble' && <span className="text-[10px] font-bold text-indigo-700 bg-indigo-100 rounded px-1.5 py-0.5">SEÇİLİ</span>}
                    </div>
                    <p className="mt-0.5 text-[11px] text-gray-500 leading-snug">Sağ altta klasik sohbet balonu (varsayılan).</p>
                  </button>
                  <button
                    type="button"
                    onClick={() => updateSetting('chat_launcher_style', 'tab')}
                    className={`text-left rounded-xl border-2 p-3 transition-colors ${launcherStyle === 'tab' ? 'border-indigo-600 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300 bg-white'}`}
                  >
                    <div className="relative w-full h-20 rounded-lg bg-gray-50 border border-gray-200 overflow-hidden">
                      <span className="absolute right-0 top-1/2 -translate-y-1/2 w-4 h-14 flex flex-col items-center pt-1" style={{ background: themeColor, borderRadius: '6px 0 0 6px' }}>
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
                      </span>
                    </div>
                    <div className="mt-2 flex items-center gap-2">
                      <span className="text-sm font-semibold text-gray-900">Kenar sekmesi (dikey)</span>
                      {launcherStyle === 'tab' && <span className="text-[10px] font-bold text-indigo-700 bg-indigo-100 rounded px-1.5 py-0.5">SEÇİLİ</span>}
                    </div>
                    <p className="mt-0.5 text-[11px] text-gray-500 leading-snug">Ekranın sağ kenarına yapışık, ortada dikey sekme — asistan adı ve çevrimiçi noktasıyla (rueonline tarzı).</p>
                  </button>
                </div>
              </div>

              {/* ── İlk mesaj görünümü ── */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">İlk Mesaj Görünümü</label>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-2xl">
                  <button
                    type="button"
                    onClick={() => updateSetting('chat_greeting_style', 'panel')}
                    className={`text-left rounded-xl border-2 p-3 transition-colors ${greetingStyle === 'panel' ? 'border-indigo-600 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300 bg-white'}`}
                  >
                    <div className="w-full h-20 rounded-lg bg-gray-50 border border-gray-200 flex items-end justify-end p-2 overflow-hidden">
                      <div className="w-24 h-16 bg-white rounded-md shadow-md border border-gray-100 flex flex-col overflow-hidden mr-1">
                        <div className="h-3.5 flex-none" style={{ background: themeColor }} />
                        <div className="p-1">
                          <div className="h-2.5 w-16 rounded bg-gray-100 border border-gray-200" />
                        </div>
                      </div>
                      <span className="w-6 h-6 rounded-full flex-none self-end" style={{ background: themeColor }} />
                    </div>
                    <div className="mt-2 flex items-center gap-2">
                      <span className="text-sm font-semibold text-gray-900">Sohbet penceresinde</span>
                      {greetingStyle === 'panel' && <span className="text-[10px] font-bold text-indigo-700 bg-indigo-100 rounded px-1.5 py-0.5">SEÇİLİ</span>}
                    </div>
                    <p className="mt-0.5 text-[11px] text-gray-500 leading-snug">Karşılama, müşteri balona tıklayıp sohbeti açınca görünür (varsayılan, sade).</p>
                  </button>
                  <button
                    type="button"
                    onClick={() => updateSetting('chat_greeting_style', 'teaser')}
                    className={`text-left rounded-xl border-2 p-3 transition-colors ${greetingStyle === 'teaser' ? 'border-indigo-600 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300 bg-white'}`}
                  >
                    <div className="w-full h-20 rounded-lg bg-gray-50 border border-gray-200 flex items-end justify-end gap-1.5 p-2 overflow-hidden">
                      <span className="relative bg-white rounded-lg rounded-br-sm shadow-md border border-gray-100 px-2 py-1.5 self-end mb-0.5">
                        <span className="block h-2 w-20 rounded bg-gray-200" />
                        <span className="block h-2 w-14 rounded bg-gray-100 mt-1" />
                        <span className="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-gray-100 border border-gray-200 text-gray-400 text-[9px] leading-3 text-center">×</span>
                      </span>
                      <span className="w-6 h-6 rounded-full flex-none self-end" style={{ background: themeColor }} />
                    </div>
                    <div className="mt-2 flex items-center gap-2">
                      <span className="text-sm font-semibold text-gray-900">Balon yanında kabarcık</span>
                      {greetingStyle === 'teaser' && <span className="text-[10px] font-bold text-indigo-700 bg-indigo-100 rounded px-1.5 py-0.5">SEÇİLİ</span>}
                    </div>
                    <p className="mt-0.5 text-[11px] text-gray-500 leading-snug">İlk mesaj sohbet açılmadan balonun yanında belirir; tıklayan sohbete girer (dikkat çekici). Kapatan müşteriye 7 gün tekrar gösterilmez.</p>
                  </button>
                </div>
                {greetingStyle === 'teaser' && (
                  <div className="mt-3 max-w-2xl">
                    <label className="block text-sm font-medium text-gray-700 mb-1">Kabarcık Metni (opsiyonel)</label>
                    <input
                      type="text"
                      value={tenantSettings.chat_teaser_text || ''}
                      onChange={(e) => updateSetting('chat_teaser_text', e.target.value)}
                      placeholder="Boş bırakılırsa karşılama mesajı kullanılır — örn: Size özel öneriler için buradayım 👋"
                      maxLength={140}
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <p className="mt-1 text-xs text-gray-500">Kısa ve davetkar bir cümle en iyi sonucu verir (en fazla 140 karakter).</p>
                  </div>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Hızlı Yanıt Butonları</label>
                <input
                  type="text"
                  value={tenantSettings.chat_quick_replies || ''}
                  onChange={(e) => updateSetting('chat_quick_replies', e.target.value)}
                  placeholder="Ürün önerisi istiyorum, Siparişim nerede?, Kargom ne zaman gelir?, İade / iptal nasıl yapılır?"
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <p className="mt-1 text-xs text-gray-500">
                  Virgülle ayırın; sohbet açılınca &quot;Sık sorulanlar&quot; menüsü olarak görünür (en fazla 8 çip).
                  Boş bırakırsanız çok sorulan sorular seti kullanılır. Ürün önerisi, sipariş durumu, kargo süresi,
                  iade/iptal, iade ödemesi, değişim, numara/kalıp ve stok sorularının cevapları <b>hazır cevaplarınızdan
                  birebir</b> gelir — metni değiştirmek için ilgili hazır cevabı düzenleyin.
                </p>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Sipariş Durumu Açıklamaları</label>
                <textarea
                  rows={4}
                  value={tenantSettings.chat_order_notes || ''}
                  onChange={(e) => updateSetting('chat_order_notes', e.target.value)}
                  placeholder={'0: Siparişiniz alındı ve hazırlık sürecinde. Siparişler genellikle ertesi iş günü kargoya teslim edilir.\n7: Siparişiniz teslim edilmiş görünüyor.'}
                  maxLength={4000}
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <p className="mt-1 text-xs text-gray-500">
                  Her satır <code>durum kodu: müşteriye söylenecek cümle</code>. Müşteri siparişini sorguladığında
                  durumun yanına bu cümle eklenir (&quot;Siparişiniz Alındı&quot; demek yetmiyor; müşterinin sorduğu
                  &quot;ne zaman elimde olacak&quot;). Yazmadığınız durumlar için varsayılan metin kullanılır.
                  Kodlar: 0 alındı · 3 ödeme bekliyor · 4 paketleniyor · 5 tedarik · 6 kargoda · 7 teslim · 8 iptal ·
                  9/11/12/13/16/17 iade akışı · 18 teslim edilemedi.
                </p>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Ek Talimatlar (opsiyonel)</label>
                <textarea
                  rows={3}
                  value={tenantSettings.chat_extra_instructions || ''}
                  onChange={(e) => updateSetting('chat_extra_instructions', e.target.value)}
                  placeholder={'Örn: Kampanya sorularında %10 hoş geldin indirimini hatırlat.\nÖrn: Mağazamız hafta içi 09:00-18:00 arası hizmet verir.'}
                  maxLength={2000}
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <p className="mt-1 text-xs text-gray-500">Asistanın davranışına mağazanıza özel bilgi/üslup ekler (kampanyalar, çalışma saatleri, iade politikası detayı...).</p>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-1.5">
                  <ShieldAlert size={14} className="text-amber-600" /> Katı Kurallar (opsiyonel)
                </label>
                <textarea
                  rows={3}
                  value={tenantSettings.chat_strict_rules || ''}
                  onChange={(e) => updateSetting('chat_strict_rules', e.target.value)}
                  placeholder={'Örn: Bir soruya yardımcı olamadığında MUTLAKA "0850 123 45 67 numaralı hattımızı arayabilirsiniz" de.\nÖrn: Fiyat pazarlığı tekliflerini her zaman kibarca reddet.\nÖrn: Rakip marka sorulduğunda asla yorum yapma.'}
                  maxLength={2000}
                  className="w-full px-3 py-2 border border-amber-200 bg-amber-50/40 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                />
                <p className="mt-1 text-xs text-gray-500">
                  Asistanın <b>her cevapta istisnasız</b> uyacağı kırmızı çizgiler. Ek Talimatlar'dan farkı:
                  bu kurallar en yüksek öncelikle uygulanır ve genel davranış kurallarını ezer.
                </p>
              </div>

              <div className="flex items-center justify-between">
                <div>
                  <label className="block text-sm font-medium text-gray-700 flex items-center gap-1.5">
                    <MessageSquare size={14} /> Müşteri Temsilcisine Devir (Handoff)
                  </label>
                  <p className="text-xs text-gray-500">
                    Müşteri isterse veya asistan yetersiz kalırsa konuşma Mesajlar sayfasına düşer;
                    oradan verdiğiniz cevap müşterinin sohbet penceresinde görünür.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => updateSetting('chat_handoff_enabled', handoffOn ? 'false' : 'true')}
                  className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${handoffOn ? 'bg-indigo-600' : 'bg-gray-200'}`}
                >
                  <span className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${handoffOn ? 'translate-x-6' : 'translate-x-1'}`} />
                </button>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">İzinli Domainler</label>
                <input
                  type="text"
                  value={tenantSettings.chat_widget_domains || ''}
                  onChange={(e) => updateSetting('chat_widget_domains', e.target.value)}
                  placeholder="magazaniz.com"
                  className="w-full max-w-md px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <p className="mt-1 text-xs text-gray-500">
                  Virgülle ayırın. Çıplak domain www dahil tüm alt alan adlarını kapsar.
                  Boş bırakılırsa widget hiçbir sitede çalışmaz (güvenlik).
                </p>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Gömme Kodu</label>
                <div className="relative">
                  <textarea
                    readOnly
                    rows={2}
                    value={embedCode}
                    className="w-full max-w-lg px-3 py-2 pr-10 bg-gray-50 border border-gray-200 rounded-lg text-xs font-mono focus:outline-none"
                  />
                  <button
                    type="button"
                    onClick={() => {
                      navigator.clipboard.writeText(embedCode)
                      setCopied(true)
                      setTimeout(() => setCopied(false), 2000)
                    }}
                    className="absolute top-2 right-2 p-1.5 text-gray-400 hover:text-gray-600 rounded"
                    title={t('feedCommon.copy')}
                  >
                    {copied ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
                  </button>
                </div>
                <p className="mt-1 text-xs text-gray-500">
                  Bu kodu sitenizin {'<head>'} veya {'<body>'} sonuna ekleyin. Balon tüm sayfalarda görünür.
                  Şablon/renk/metin değişiklikleri kaydedince temaya dokunmadan anında yayına geçer.
                </p>
              </div>
            </>
          )}

          <div className="pt-2 flex items-center gap-3">
            <button
              onClick={handleSave}
              disabled={saving}
              className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors"
            >
              <Save size={16} />
              {saving ? t('common.saving') : t('common.save')}
            </button>
            {saved && (
              <span className="flex items-center gap-1 text-sm text-green-600">
                <CheckCircle size={16} /> {t('common.saved')}
              </span>
            )}
          </div>
        </div>
      </div>
      )}
    </div>
  )
}

/* ── Analiz sekmesi: kim yazdı, ne yazdı, konuşma ağırlığı, sonuç ── */
const OUTCOME_META: Record<string, { label: string; cls: string }> = {
  handoff: { label: 'Temsilciye devredildi', cls: 'bg-amber-100 text-amber-800' },
  urun_onerildi: { label: 'Ürün önerildi', cls: 'bg-indigo-100 text-indigo-700' },
  cevaplandi: { label: 'Bot cevapladı', cls: 'bg-green-100 text-green-700' },
  bos: { label: 'Mesaj yazılmadı', cls: 'bg-gray-100 text-gray-500' },
}

function weightBadge(n: number) {
  if (n >= 6) return { label: `Yoğun · ${n} mesaj`, cls: 'bg-rose-100 text-rose-700' }
  if (n >= 3) return { label: `Orta · ${n} mesaj`, cls: 'bg-sky-100 text-sky-700' }
  return { label: `Hafif · ${n} mesaj`, cls: 'bg-gray-100 text-gray-600' }
}

function ChatAnalytics() {
  const [range, setRange] = useState(30)
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [openId, setOpenId] = useState<number | null>(null)
  const [transcripts, setTranscripts] = useState<Record<number, any>>({})

  useEffect(() => {
    setLoading(true)
    api.get(`/chat/analytics?range=${range}`)
      .then((r) => setData(r.data))
      .finally(() => setLoading(false))
  }, [range])

  const toggleRow = (id: number) => {
    if (openId === id) { setOpenId(null); return }
    setOpenId(id)
    if (!transcripts[id]) {
      api.get(`/chat/sessions/${id}/messages`).then((r) =>
        setTranscripts((p) => ({ ...p, [id]: r.data }))
      )
    }
  }

  const s = data?.summary
  const cards = s ? [
    { label: 'Konuşma', value: s.sessions, hint: `${s.engaged_sessions} mesajlı` },
    { label: 'Yazan Ziyaretçi', value: s.engaged_sessions, hint: `${s.with_contact} iletişim bıraktı` },
    { label: 'Mesaj', value: s.user_messages + s.bot_messages + s.agent_messages, hint: `${s.user_messages} müşteri · ${s.bot_messages} bot · ${s.agent_messages} personel` },
    { label: 'Temsilciye Devir', value: `%${Number(s.handoff_rate).toFixed(1)}`, hint: `${s.handoffs} konuşma` },
    { label: 'Ürünlü Cevap / Tıklama', value: `${s.product_messages} / ${s.product_clicks}`, hint: 'kart gösterimi / tıklanma' },
    { label: 'Sohbetten Ciro', value: `${Number(s.attributed_revenue).toLocaleString('tr-TR', { maximumFractionDigits: 0 })} ₺`, hint: `${s.attributed_orders} sipariş (24s atıf)` },
  ] : []

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-xs text-gray-500">Chatbot konuşmalarının dökümü. Hub üzerinden yapılan denemeler "test" olarak işaretlenir.</p>
        <div className="flex gap-1">
          {[7, 30, 90].map((d) => (
            <button
              key={d}
              type="button"
              onClick={() => setRange(d)}
              className={`px-3 py-1.5 rounded-lg text-xs font-medium border ${range === d ? 'bg-indigo-600 border-indigo-600 text-white' : 'bg-white border-gray-200 text-gray-600 hover:border-gray-300'}`}
            >
              {d} gün
            </button>
          ))}
        </div>
      </div>

      {loading && <div className="text-sm text-gray-400 py-8 text-center">Yükleniyor…</div>}

      {!loading && s && (
        <>
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            {cards.map((c) => (
              <div key={c.label} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                <div className="text-[11px] font-medium text-gray-500">{c.label}</div>
                <div className="mt-1 text-xl font-extrabold text-gray-900">{c.value}</div>
                <div className="mt-0.5 text-[10px] text-gray-400">{c.hint}</div>
              </div>
            ))}
          </div>

          <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-800">
              Konuşmalar <span className="text-gray-400 font-normal">(son {data.conversations.length})</span>
            </div>
            {data.conversations.length === 0 && (
              <div className="p-8 text-center text-sm text-gray-400">Bu aralıkta konuşma yok.</div>
            )}
            <div className="divide-y divide-gray-50">
              {data.conversations.map((c: any) => {
                const om = OUTCOME_META[c.outcome] || OUTCOME_META.bos
                const wb = weightBadge(c.user_messages)
                const tr = transcripts[c.id]
                return (
                  <div key={c.id}>
                    <button
                      type="button"
                      onClick={() => toggleRow(c.id)}
                      className="w-full text-left px-4 py-3 hover:bg-gray-50 flex items-center gap-3"
                    >
                      {openId === c.id ? <ChevronDown size={14} className="text-gray-400 flex-none" /> : <ChevronRight size={14} className="text-gray-400 flex-none" />}
                      <span className="w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center flex-none">
                        <User size={13} className="text-gray-500" />
                      </span>
                      <span className="flex-1 min-w-0">
                        <span className="flex items-center gap-2">
                          <span className="text-sm font-medium text-gray-900 truncate">
                            {c.visitor || 'Anonim ziyaretçi'}
                          </span>
                          {c.is_test && <span className="text-[9px] font-bold bg-gray-100 text-gray-500 rounded px-1 py-px">TEST</span>}
                        </span>
                        <span className="block text-xs text-gray-500 truncate mt-0.5">
                          {c.first_message || '—'}
                        </span>
                      </span>
                      <span className={`text-[10px] font-semibold rounded-full px-2 py-1 flex-none ${wb.cls}`}>{wb.label}</span>
                      <span className={`text-[10px] font-semibold rounded-full px-2 py-1 flex-none ${om.cls}`}>{om.label}</span>
                      <span className="text-[11px] text-gray-400 flex-none w-24 text-right">
                        {new Date(c.last_activity_at).toLocaleString('tr-TR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                      </span>
                    </button>

                    {openId === c.id && (
                      <div className="px-6 pb-4 bg-gray-50/60">
                        {!tr && <div className="text-xs text-gray-400 py-3">Transkript yükleniyor…</div>}
                        {tr && (
                          <div className="space-y-2 pt-2 max-h-96 overflow-y-auto">
                            {(tr.session?.contact?.email || tr.session?.contact?.phone) && (
                              <div className="text-[11px] text-gray-500">
                                İletişim: {[tr.session.contact.name, tr.session.contact.email, tr.session.contact.phone].filter(Boolean).join(' · ')}
                              </div>
                            )}
                            {tr.messages.map((m: any) => (
                              <div key={m.id} className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                                {m.role === 'system' ? (
                                  <div className="mx-auto text-[10px] text-gray-400 bg-gray-100 rounded-full px-3 py-1 flex items-center gap-1">
                                    <Headphones size={10} /> Temsilciye devredildi
                                  </div>
                                ) : (
                                  <div className={`max-w-[75%] rounded-xl px-3 py-2 text-xs leading-relaxed ${
                                    m.role === 'user' ? 'bg-indigo-600 text-white'
                                      : m.role === 'agent' ? 'bg-emerald-50 text-emerald-900 border border-emerald-200'
                                        : 'bg-white text-gray-800 border border-gray-200'
                                  }`}>
                                    {m.role === 'agent' && <div className="text-[9px] font-bold text-emerald-600 mb-0.5">PERSONEL</div>}
                                    <div className="whitespace-pre-wrap break-words">{m.content}</div>
                                    {m.products?.length > 0 && (
                                      <div className="mt-1.5 space-y-0.5">
                                        {m.products.map((p: any, i: number) => (
                                          <a key={i} href={p.url} target="_blank" rel="noopener" className="block text-[10px] underline opacity-80 truncate">
                                            🛍 {p.name}
                                          </a>
                                        ))}
                                      </div>
                                    )}
                                  </div>
                                )}
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
