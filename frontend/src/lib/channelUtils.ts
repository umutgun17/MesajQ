// Channel color/icon/label utilities + relative time formatting

export type ChannelType =
  | 'facebook-message'
  | 'facebook-comment'
  | 'instagram-message'
  | 'instagram-comment'
  | 'whatsapp'
  | 'trendyol-product'
  | 'trendyol-order'
  | 'hepsiburada-product'
  | 'hepsiburada-order'
  | 'ticimax-ticket'
  | 'email'
  | 'webchat'
  | string

const channelMap: Record<string, { color: string; bg: string; label: string; icon: string }> = {
  'facebook-message':     { color: 'text-blue-700',    bg: 'bg-blue-100',    label: 'Facebook',      icon: 'Facebook' },
  'facebook-comment':     { color: 'text-blue-700',    bg: 'bg-blue-100',    label: 'Facebook',      icon: 'Facebook' },
  'instagram-message':    { color: 'text-pink-700',    bg: 'bg-pink-100',    label: 'Instagram',     icon: 'Instagram' },
  'instagram-comment':    { color: 'text-pink-700',    bg: 'bg-pink-100',    label: 'Instagram',     icon: 'Instagram' },
  'whatsapp':             { color: 'text-green-700',   bg: 'bg-green-100',   label: 'WhatsApp',      icon: 'MessageCircle' },
  'trendyol-product':     { color: 'text-orange-700',  bg: 'bg-orange-100',  label: 'Trendyol',      icon: 'ShoppingBag' },
  'trendyol-order':       { color: 'text-orange-700',  bg: 'bg-orange-100',  label: 'Trendyol',      icon: 'ShoppingBag' },
  'hepsiburada-product':  { color: 'text-purple-700',  bg: 'bg-purple-100',  label: 'Hepsiburada',   icon: 'ShoppingBag' },
  'hepsiburada-order':    { color: 'text-purple-700',  bg: 'bg-purple-100',  label: 'Hepsiburada',   icon: 'ShoppingBag' },
  'ticimax-ticket':       { color: 'text-red-700',     bg: 'bg-red-100',     label: 'Ticimax',       icon: 'Headphones' },
  'email':                { color: 'text-blue-700',    bg: 'bg-blue-100',    label: 'E-posta',       icon: 'Mail' },
  'webchat':              { color: 'text-indigo-700',  bg: 'bg-indigo-100',  label: 'Site Chat',     icon: 'Bot' },
  'sms':                  { color: 'text-teal-700',    bg: 'bg-teal-100',    label: 'SMS',           icon: 'Smartphone' },
}

const defaultChannel = { color: 'text-gray-700', bg: 'bg-gray-100', label: 'Kanal', icon: 'Globe' }

export function getChannelColor(type: string): string {
  return (channelMap[type] ?? defaultChannel).color
}

export function getChannelBg(type: string): string {
  return (channelMap[type] ?? defaultChannel).bg
}

export function getChannelLabel(type: string): string {
  return (channelMap[type] ?? defaultChannel).label
}

export function getChannelIconName(type: string): string {
  return (channelMap[type] ?? defaultChannel).icon
}

const filterLabels: Record<string, string> = {
  'facebook-message':    'Facebook Messenger',
  'facebook-comment':    'Facebook Yorum',
  'instagram-message':   'Instagram DM',
  'instagram-comment':   'Instagram Yorum',
  'whatsapp':            'WhatsApp',
  'trendyol-product':    'Trendyol Ürün Soruları',
  'trendyol-order':      'Trendyol Sipariş Soruları',
  'hepsiburada-product': 'Hepsiburada Ürün Soruları',
  'hepsiburada-order':   'Hepsiburada Sipariş Soruları',
  'ticimax-ticket':      'Ticimax Destek Talepleri',
  'email':               'E-posta',
  'webchat':             'Site Chat (AI Chatbot)',
  'sms':                 'SMS',
}

export function getChannelFilterLabel(type: string): string {
  return filterLabels[type] ?? (channelMap[type]?.label ?? type)
}

export function getChannelBadgeClasses(type: string): string {
  const ch = channelMap[type] ?? defaultChannel
  return `${ch.bg} ${ch.color}`
}

// Meta'nın numarasız kimlik akışında wa_id "TR.1683650359366855" gibi gelir.
// Bu bir telefon numarası DEĞİLDİR: ne gösterilebilir ne de gönderim adresi olur.
export function isSendablePhone(value?: string | null): boolean {
  const raw = (value ?? '').trim()
  if (!raw) return false
  return /^\d{10,15}$/.test(raw)
}

// Meta'nın kullanıcı adı akışında telefonunu gizleyen müşteri "TR.1108540038525267"
// gibi bir işletmeye özel kimlikle (BSUID) gelir. Numara değildir ama Temmuz 2026'dan
// beri Cloud API bu kimliğe de gönderim yapar (arka uçta `recipient` alanı).
export function isWaBsuid(value?: string | null): boolean {
  const raw = (value ?? '').trim()
  return /^[A-Z]{2}\.[A-Za-z0-9]{1,128}$/.test(raw)
}

// WhatsApp konuşmasında müşteriye cevap yazılabilir mi? (numara ya da BSUID varsa evet)
export function canReachOnWhatsApp(customer?: { phone?: string | null; platform_id?: string | null } | null): boolean {
  if (!customer) return false
  return isSendablePhone(customer.phone) || isSendablePhone(customer.platform_id) || isWaBsuid(customer.platform_id)
}

// Telefonu gizli (BSUID) WhatsApp müşterisi mi? Panelde bilgi bandı için.
export function isWaHiddenNumber(customer?: { platform_id?: string | null; platform_type?: string | null } | null): boolean {
  if (!customer) return false
  return customer.platform_type === 'whatsapp' && isWaBsuid(customer.platform_id)
}

// WhatsApp/SMS müşteri numarası: phone boşsa numara platform_id'de (wa_id) durur.
export function customerPhone(customer?: { phone?: string | null; platform_id?: string | null; platform_type?: string | null } | null): string {
  if (!customer) return ''
  const fromPlatform = customer.platform_type === 'whatsapp' && isSendablePhone(customer.platform_id)
    ? customer.platform_id
    : ''
  const raw = customer.phone || fromPlatform || ''
  const digits = raw.replace(/\D+/g, '')
  if (!digits) return ''
  // 905XXXXXXXXX → +90 5XX XXX XX XX
  if (digits.length === 12 && digits.startsWith('90')) {
    return `+90 ${digits.slice(2, 5)} ${digits.slice(5, 8)} ${digits.slice(8, 10)} ${digits.slice(10)}`
  }
  // 05XXXXXXXXX / 5XXXXXXXXX (TR yerel biçim)
  const local = digits.length === 11 && digits.startsWith('0') ? digits.slice(1) : digits
  if (local.length === 10) {
    return `+90 ${local.slice(0, 3)} ${local.slice(3, 6)} ${local.slice(6, 8)} ${local.slice(8)}`
  }
  return `+${digits}`
}

export function formatRelativeTime(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const now = new Date()
  const date = new Date(dateStr)
  const diffMs = now.getTime() - date.getTime()
  const diffSec = Math.floor(diffMs / 1000)
  const diffMin = Math.floor(diffSec / 60)
  const diffHour = Math.floor(diffMin / 60)
  const diffDay = Math.floor(diffHour / 24)

  if (diffSec < 60) return 'Şimdi'
  if (diffMin < 60) return `${diffMin} dk`
  if (diffHour < 24) return `${diffHour} saat`
  if (diffDay < 7) return `${diffDay} gün`

  return date.toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' })
}
