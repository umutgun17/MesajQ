import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import {
  X, MessageCircle, Mail, Smartphone, Search, Loader2, Send, AlertTriangle, Info,
} from 'lucide-react'
import clsx from 'clsx'

// "+ Yeni konuşma": daha önce konuşması olmayan kişiye ilk mesaj.
// Kanal listesi tenant'ın AKTİF başlatılabilir kanallarından gelir
// (IG/FB yok — platform işletmenin önce yazmasına izin vermiyor).

interface StartChannel {
  channel_id: number | null
  type: 'whatsapp' | 'email' | 'sms'
  name: string
  detail: string | null
}

interface WaTemplate {
  key: string
  label: string
  category: string
  /** Panelde gruplama başlığı: "Konuşma başlatma" veya hazır cevap kategorisi */
  group?: string
  /** null ise şablon parametresizdir — temsilciden giriş istenmez */
  param_label: string | null
  buttons?: string[]
  preview: string
}

interface CustomerHit {
  id: number
  name: string | null
  surname: string | null
  email: string | null
  phone: string | null
  platform_type: string | null
  total_orders: number | null
}

interface Props {
  onClose: () => void
  onCreated: (conversationId: number) => void
}

const channelTypeIcons = { whatsapp: MessageCircle, email: Mail, sms: Smartphone }

export default function NewConversationModal({ onClose, onCreated }: Props) {
  const { i18n } = useTranslation()
  const tr = i18n.language === 'tr'

  const [channels, setChannels] = useState<StartChannel[]>([])
  const [templates, setTemplates] = useState<WaTemplate[]>([])
  const [qualityWarning, setQualityWarning] = useState<string | null>(null)
  const [templateQuery, setTemplateQuery] = useState('')
  const [loadingOptions, setLoadingOptions] = useState(true)
  const [selected, setSelected] = useState<StartChannel | null>(null)

  const [query, setQuery] = useState('')
  const [hits, setHits] = useState<CustomerHit[]>([])
  const [searching, setSearching] = useState(false)
  const [pickedCustomer, setPickedCustomer] = useState<CustomerHit | null>(null)
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [template, setTemplate] = useState('siparis_bilgilendirme')
  const [templateParam, setTemplateParam] = useState('')
  const [subject, setSubject] = useState('')
  const [content, setContent] = useState('')

  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api.get('/conversations/start/options').then((res) => {
      setChannels(res.data.channels ?? [])
      setTemplates(res.data.wa_templates ?? [])
      setQualityWarning(res.data.quality_warning ?? null)
      if ((res.data.channels ?? []).length === 1) setSelected(res.data.channels[0])
    }).finally(() => setLoadingOptions(false))
  }, [])

  // Debounced müşteri arama
  useEffect(() => {
    if (searchTimer.current) clearTimeout(searchTimer.current)
    const q = query.trim()
    if (q.length < 2) { setHits([]); return }
    searchTimer.current = setTimeout(() => {
      setSearching(true)
      api.get('/conversations/start/customers', { params: { q } })
        .then((res) => setHits(res.data.customers ?? []))
        .finally(() => setSearching(false))
    }, 300)
    return () => { if (searchTimer.current) clearTimeout(searchTimer.current) }
  }, [query])

  const pickCustomer = (c: CustomerHit) => {
    setPickedCustomer(c)
    setHits([])
    setQuery('')
    setName([c.name, c.surname].filter(Boolean).join(' '))
    if (c.phone) setPhone(c.phone)
    if (c.email) setEmail(c.email)
  }

  const selectedTemplate = templates.find((t) => t.key === template)

  // Hazır cevaplar da şablona dönüştüğü için liste 40'ı aşabiliyor:
  // arama + kategori başlıkları olmadan radyo listesi kullanılamaz hale gelir.
  const visibleTemplates = (() => {
    const q = templateQuery.trim().toLocaleLowerCase('tr')
    if (!q) return templates
    return templates.filter(
      (t) => t.label.toLocaleLowerCase('tr').includes(q) || t.preview.toLocaleLowerCase('tr').includes(q),
    )
  })()

  const templateGroups = visibleTemplates.reduce<Record<string, WaTemplate[]>>((acc, t) => {
    const g = t.group ?? 'Diğer'
    ;(acc[g] ??= []).push(t)
    return acc
  }, {})

  const canSubmit = (() => {
    if (!selected || sending) return false
    if (selected.type === 'whatsapp') return !!phone.trim() && (!selectedTemplate?.param_label || !!templateParam.trim())
    if (selected.type === 'email') return !!email.trim() && !!subject.trim() && !!content.trim()
    return !!phone.trim() && !!content.trim()
  })()

  const handleSubmit = async () => {
    if (!selected || !canSubmit) return
    setSending(true)
    setError(null)
    try {
      const res = await api.post('/conversations/start', {
        channel_type: selected.type,
        channel_id: selected.channel_id,
        customer_id: pickedCustomer?.id ?? null,
        name: name.trim() || null,
        phone: phone.trim() || null,
        email: email.trim() || null,
        template: selected.type === 'whatsapp' ? template : null,
        template_param: templateParam.trim() || null,
        subject: subject.trim() || null,
        content: content.trim() || null,
      })
      onCreated(res.data.conversation_id)
    } catch (err) {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message ?? (tr ? 'Gönderilemedi' : 'Send failed'))
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div
        className="w-full max-w-lg bg-white rounded-2xl shadow-xl flex flex-col max-h-[90vh]"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <h2 className="font-bold text-gray-900">{tr ? 'Yeni Konuşma' : 'New Conversation'}</h2>
          <button onClick={onClose} className="p-1 text-gray-400 hover:text-gray-600 rounded">
            <X size={18} />
          </button>
        </div>

        <div className="p-5 overflow-y-auto flex-1 space-y-4">
          {/* Kanal seçimi */}
          {loadingOptions ? (
            <div className="flex justify-center py-6"><Loader2 size={20} className="animate-spin text-gray-400" /></div>
          ) : channels.length === 0 ? (
            <div className="text-sm text-gray-500 text-center py-6">
              {tr ? 'Konuşma başlatılabilecek aktif kanal yok.' : 'No active channel supports starting a conversation.'}
            </div>
          ) : (
            <div>
              <label className="block text-xs font-semibold text-gray-500 mb-1.5">{tr ? 'Kanal' : 'Channel'}</label>
              <div className="flex gap-2">
                {channels.map((ch) => {
                  const Icon = channelTypeIcons[ch.type]
                  const active = selected?.type === ch.type && selected?.channel_id === ch.channel_id
                  return (
                    <button
                      key={`${ch.type}-${ch.channel_id}`}
                      onClick={() => { setSelected(ch); setError(null) }}
                      className={clsx(
                        'flex-1 flex flex-col items-center gap-1 px-3 py-2.5 rounded-xl border text-xs font-semibold transition-colors',
                        active
                          ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                          : 'border-gray-200 text-gray-600 hover:bg-gray-50',
                      )}
                    >
                      <Icon size={18} />
                      {ch.type === 'whatsapp' ? 'WhatsApp' : ch.type === 'email' ? (tr ? 'E-posta' : 'Email') : 'SMS'}
                      {ch.detail && <span className="text-[10px] font-normal text-gray-400 truncate max-w-full">{ch.detail}</span>}
                    </button>
                  )
                })}
              </div>
            </div>
          )}

          {selected && (
            <>
              {/* Kişi seçimi */}
              <div>
                <label className="block text-xs font-semibold text-gray-500 mb-1.5">{tr ? 'Kişi' : 'Contact'}</label>
                <div className="relative">
                  <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                  <input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={tr ? 'Müşteri ara (isim, telefon, e-posta)…' : 'Search customer (name, phone, email)…'}
                    className="w-full pl-9 pr-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white"
                  />
                  {searching && <Loader2 size={14} className="animate-spin absolute right-3 top-1/2 -translate-y-1/2 text-gray-400" />}
                  {hits.length > 0 && (
                    <div className="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-lg max-h-52 overflow-y-auto">
                      {hits.map((c) => (
                        <button
                          key={c.id}
                          onClick={() => pickCustomer(c)}
                          className="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"
                        >
                          <span className="font-medium">{[c.name, c.surname].filter(Boolean).join(' ') || '—'}</span>
                          <span className="block text-xs text-gray-400">
                            {[c.phone, c.email].filter(Boolean).join(' · ')}
                            {c.total_orders ? ` · ${c.total_orders} ${tr ? 'sipariş' : 'orders'}` : ''}
                          </span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
                <p className="mt-1 text-[11px] text-gray-400">
                  {tr ? 'Mevcut müşteriyi seçin veya aşağıya bilgileri elle girin.' : 'Pick an existing customer or enter details manually below.'}
                </p>
              </div>

              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className="block text-xs font-semibold text-gray-500 mb-1.5">{tr ? 'İsim (opsiyonel)' : 'Name (optional)'}</label>
                  <input
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className="w-full px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white"
                  />
                </div>
                {selected.type === 'email' ? (
                  <div>
                    <label className="block text-xs font-semibold text-gray-500 mb-1.5">{tr ? 'E-posta adresi' : 'Email address'}</label>
                    <input
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      type="email"
                      className="w-full px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white"
                    />
                  </div>
                ) : (
                  <div>
                    <label className="block text-xs font-semibold text-gray-500 mb-1.5">{tr ? 'Telefon' : 'Phone'}</label>
                    <input
                      value={phone}
                      onChange={(e) => setPhone(e.target.value)}
                      placeholder="05XX XXX XX XX"
                      className="w-full px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white"
                    />
                  </div>
                )}
              </div>

              {/* Kanala göre içerik */}
              {selected.type === 'whatsapp' && (
                <div>
                  <label className="block text-xs font-semibold text-gray-500 mb-1.5">
                    {tr ? 'Açılış şablonu' : 'Opening template'}
                    <span className="ml-1 font-normal text-gray-400">({templates.length})</span>
                  </label>
                  {templates.length > 8 && (
                    <div className="relative mb-2">
                      <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                      <input
                        value={templateQuery}
                        onChange={(e) => setTemplateQuery(e.target.value)}
                        placeholder={tr ? 'Şablon ara…' : 'Search templates…'}
                        className="w-full pl-9 pr-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white"
                      />
                    </div>
                  )}
                  <div className="space-y-2 max-h-72 overflow-y-auto pr-0.5">
                    {visibleTemplates.length === 0 && (
                      <p className="px-3 py-4 text-center text-xs text-gray-400">
                        {tr ? 'Eşleşen şablon yok.' : 'No matching template.'}
                      </p>
                    )}
                    {Object.entries(templateGroups).map(([group, items]) => (
                      <div key={group} className="space-y-2">
                        <p className="px-1 pt-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400">{group}</p>
                        {items.map((tpl) => (
                      <label
                        key={tpl.key}
                        className={clsx(
                          'block px-3 py-2.5 rounded-xl border cursor-pointer transition-colors',
                          template === tpl.key ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:bg-gray-50',
                        )}
                      >
                        <span className="flex items-center gap-2">
                          <input
                            type="radio"
                            checked={template === tpl.key}
                            onChange={() => setTemplate(tpl.key)}
                            className="accent-indigo-600"
                          />
                          <span className="text-sm font-semibold">{tpl.label}</span>
                          {tpl.category === 'MARKETING' && (
                            <span className="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px] font-semibold uppercase tracking-wide">
                              {tr ? 'Pazarlama' : 'Marketing'}
                            </span>
                          )}
                        </span>
                        <span className="block mt-1 ml-6 text-xs text-gray-500">{tpl.preview}</span>
                        {!!tpl.buttons?.length && (
                          <span className="flex flex-wrap gap-1.5 mt-1.5 ml-6">
                            {tpl.buttons.map((b) => (
                              <span key={b} className="px-2 py-0.5 rounded-full bg-white border border-gray-200 text-[11px] text-indigo-600 font-medium">
                                {b}
                              </span>
                            ))}
                          </span>
                        )}
                      </label>
                        ))}
                      </div>
                    ))}
                  </div>
                  {selectedTemplate?.param_label && (
                    <div className="mt-2">
                      <label className="block text-xs font-semibold text-gray-500 mb-1.5">{selectedTemplate.param_label}</label>
                      <input
                        value={templateParam}
                        onChange={(e) => setTemplateParam(e.target.value)}
                        className="w-full px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white"
                      />
                    </div>
                  )}
                  {qualityWarning && (
                    <p className="mt-2 flex items-start gap-1.5 px-2.5 py-2 rounded-lg bg-amber-50 border border-amber-200 text-[11px] text-amber-800">
                      <AlertTriangle size={12} className="shrink-0 mt-0.5" />
                      {qualityWarning}
                    </p>
                  )}
                  <p className="mt-2 flex items-start gap-1.5 text-[11px] text-gray-400">
                    <Info size={12} className="shrink-0 mt-0.5" />
                    {tr
                      ? 'WhatsApp kuralı: ilk temas onaylı şablonla açılır. Müşteri cevap verince (butona basmak da cevaptır) 24 saatlik normal mesajlaşma penceresi açılır.'
                      : 'WhatsApp rule: the first contact must use an approved template. Once the customer replies (tapping a button counts), the 24-hour free messaging window opens.'}
                  </p>
                </div>
              )}

              {selected.type === 'email' && (
                <>
                  <div>
                    <label className="block text-xs font-semibold text-gray-500 mb-1.5">{tr ? 'Konu' : 'Subject'}</label>
                    <input
                      value={subject}
                      onChange={(e) => setSubject(e.target.value)}
                      className="w-full px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-gray-500 mb-1.5">{tr ? 'Mesaj' : 'Message'}</label>
                    <textarea
                      value={content}
                      onChange={(e) => setContent(e.target.value)}
                      rows={5}
                      className="w-full px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white resize-none"
                    />
                  </div>
                </>
              )}

              {selected.type === 'sms' && (
                <div>
                  <label className="block text-xs font-semibold text-gray-500 mb-1.5">{tr ? 'SMS metni' : 'SMS text'}</label>
                  <textarea
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                    rows={4}
                    className="w-full px-3 py-2 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white resize-none"
                  />
                  <p className="mt-2 flex items-start gap-1.5 text-[11px] text-amber-600">
                    <AlertTriangle size={12} className="shrink-0 mt-0.5" />
                    {tr
                      ? 'SMS tek yönlüdür: gönderici başlığına müşteri cevap yazamaz. Cevap almak için mesaja WhatsApp numarası veya link ekleyin.'
                      : 'SMS is one-way: customers cannot reply to a sender ID. Include a WhatsApp number or link to get responses.'}
                  </p>
                </div>
              )}
            </>
          )}

          {error && (
            <div className="flex items-center gap-2 px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
              <AlertTriangle size={14} className="shrink-0" />
              <span className="flex-1">{error}</span>
            </div>
          )}
        </div>

        <div className="px-5 py-4 border-t border-gray-100 flex justify-end gap-2">
          <button
            onClick={onClose}
            className="px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 rounded-xl transition-colors"
          >
            {tr ? 'Vazgeç' : 'Cancel'}
          </button>
          <button
            onClick={handleSubmit}
            disabled={!canSubmit}
            className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded-xl transition-colors"
          >
            {sending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
            {tr ? 'Gönder' : 'Send'}
          </button>
        </div>
      </div>
    </div>
  )
}
