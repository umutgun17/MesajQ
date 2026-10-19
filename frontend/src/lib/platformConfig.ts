export interface PlatformDef {
  key: string
  name: string
  channelTypes: string[]
  color: string
  bg: string
  borderColor: string
}

export const platforms: PlatformDef[] = [
  { key: 'meta', name: 'Meta', channelTypes: ['facebook-message', 'instagram-message', 'facebook-comment', 'instagram-comment', 'whatsapp'], color: 'text-blue-700', bg: 'bg-blue-100', borderColor: 'border-blue-500' },
  { key: 'trendyol', name: 'Trendyol', channelTypes: ['trendyol-product', 'trendyol-order'], color: 'text-orange-700', bg: 'bg-orange-100', borderColor: 'border-orange-500' },
  { key: 'hepsiburada', name: 'Hepsiburada', channelTypes: ['hepsiburada-product', 'hepsiburada-order'], color: 'text-purple-700', bg: 'bg-purple-100', borderColor: 'border-purple-500' },
  { key: 'ticimax', name: 'Ticimax', channelTypes: ['ticimax-ticket'], color: 'text-red-700', bg: 'bg-red-100', borderColor: 'border-red-500' },
  { key: 'email', name: 'E-posta', channelTypes: ['email'], color: 'text-blue-700', bg: 'bg-blue-100', borderColor: 'border-blue-500' },
]

export const subChannelLabels: Record<string, string> = {
  'facebook-message': 'Facebook Messenger',
  'facebook-comment': 'Facebook Yorum',
  'instagram-message': 'Instagram DM',
  'instagram-comment': 'Instagram Yorum',
  'trendyol-product': 'Ürün Soruları',
  'trendyol-order': 'Sipariş Soruları',
  'hepsiburada-product': 'Ürün Soruları',
  'hepsiburada-order': 'Sipariş Soruları',
  'ticimax-ticket': 'Destek Talepleri',
  'whatsapp': 'WhatsApp Mesajları',
  'email': 'E-posta',
}
