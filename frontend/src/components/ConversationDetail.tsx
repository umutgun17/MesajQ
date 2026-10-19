import { useEffect, useState, useRef, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import { storefrontUrl } from '@/lib/tenant'
import type { Conversation, Message } from '@/types'
import {
  Send, Bot, AlertTriangle, Clock, Package, ShoppingBag, ShoppingCart, Ticket,
  Sparkles, ChevronDown, ArrowUpRight, CheckCircle, X, Shield,
  Mail, MessageCircle, Instagram, Facebook, Headphones, Smartphone, Globe,
  Loader2, Image as ImageIcon, Film, Share2, ExternalLink, FileText,
  Flag, Zap, Smile, Paperclip, Search,
} from 'lucide-react'
import clsx from 'clsx'
import { getChannelBadgeClasses, getChannelLabel, customerPhone, canReachOnWhatsApp, isWaHiddenNumber } from '@/lib/channelUtils'
import QuickRepliesPanel from '@/components/QuickRepliesPanel'
import EmojiPicker from '@/components/EmojiPicker'
import ChatProductCards, { type ChatProduct } from '@/components/ChatProductCards'

interface Props {
  conversationId: number
  onUpdate?: () => void
}

const channelIcons: Record<string, typeof Mail> = {
  email: Mail,
  whatsapp: MessageCircle,
  instagram: Instagram,
  'instagram-message': Instagram,
  'instagram-comment': Instagram,
  facebook: Facebook,
  'facebook-message': Facebook,
  'facebook-comment': Facebook,
  live_chat: Headphones,
  sms: Smartphone,
  'trendyol-product': ShoppingBag,
  'trendyol-order': ShoppingBag,
  'ticimax-ticket': Headphones,
}

function formatMessageTime(dateStr: string, locale: string): string {
  const date = new Date(dateStr)
  const now = new Date()
  const time = date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })

  // Same calendar day → only time
  const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  if (date >= todayStart) return time

  // Older than today → "25 Feb 19:20" format
  return `${date.toLocaleDateString(locale, { day: '2-digit', month: 'short' })} ${time}`
}

const INLINE_ATTACHMENT_RE = /\[Ek dosya:\s*(https?:\/\/[^\]\s]+)\]/g
const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']

function MessageBubble({ msg, isAdmin, onPenalty, onImageClick, locale, t }: {
  msg: Message
  isAdmin?: boolean
  onPenalty: (messageId: number, points: number) => void
  onImageClick: (url: string) => void
  locale: string
  t: (key: string, opts?: Record<string, unknown>) => string
}) {
  const isAgent = msg.sender_type === 'agent'
  const isAutomation = msg.sender_type === 'automation'
  const canPenalize = isAdmin && isAgent
  const isFaulty = msg.penalty_points > 0
  const autoMeta = isAutomation ? (msg.metadata as Record<string, string> | null) : null
  const AUTO_SOURCE_TR: Record<string, string> = {
    cart_recovery: 'Sepet Kurtarma', wheel_coupon: 'Çark Kuponu', order_status: 'Sipariş Bildirimi',
  }

  return (
    <div className={clsx('flex', msg.sender_type === 'customer' ? 'justify-start' : 'justify-end')}>
      <div className={clsx('relative group', isAgent ? 'max-w-[85%] md:max-w-[75%]' : 'max-w-[85%] md:max-w-[75%]')}>
        <div className={clsx(
          'rounded-2xl px-3 md:px-4 py-2 md:py-2.5',
          msg.sender_type === 'customer'
            ? 'bg-gray-100 text-gray-900 rounded-bl-md'
            : isAutomation
              ? 'bg-emerald-600 text-white rounded-br-md'
              : isFaulty
                ? 'bg-indigo-600 text-white rounded-br-md ring-2 ring-red-400'
                : 'bg-indigo-600 text-white rounded-br-md'
        )}>
          {isAutomation && (
            <div className="flex items-center gap-1 text-xs mb-1 text-emerald-100">
              <Bot size={12} />
              Otomasyon · {AUTO_SOURCE_TR[autoMeta?.automation_source ?? ''] ?? autoMeta?.automation_source}
              {autoMeta?.automation_channel === 'sms' && ' (SMS)'}
            </div>
          )}
          {msg.is_ai_generated && (
            <div className={clsx(
              'flex items-center gap-1 text-xs mb-1',
              msg.sender_type === 'customer' ? 'text-gray-500' : 'text-indigo-200'
            )}>
              <Bot size={12} /> {t('messages.aiPowered')}
            </div>
          )}
          <MessageAttachments
            messageId={msg.id}
            metadata={msg.metadata as Record<string, unknown> | null}
            isCustomer={msg.sender_type === 'customer'}
            onImageClick={onImageClick}
            t={t}
          />
          {/* Medya gösteriliyorsa "[Fotoğraf]" gibi yer tutucu metni tekrarlama (başlık/caption ise göster) */}
          {!((msg.metadata as Record<string, unknown> | null)?.media_id
            && /^\[(Fotoğraf|Video|Ses Mesajı|Çıkartma|Dosya[^\]]*)\]$/.test(msg.content.trim())) && (
            <MessageContent content={msg.content} isCustomer={msg.sender_type === 'customer'} onImageClick={onImageClick} />
          )}
          {/* Bot sohbette ürün önerdiyse müşteriye gösterilen kartların aynısı */}
          <ChatProductCards
            products={((msg.metadata as Record<string, unknown> | null)?.chat_products as ChatProduct[] | undefined) ?? []}
          />
          <div className={clsx(
            'flex items-center justify-between gap-2 mt-1 text-xs',
            msg.sender_type === 'customer' ? 'text-gray-400' : isAutomation ? 'text-emerald-100' : 'text-indigo-200'
          )}>
            <div className="flex items-center gap-1">
              <Clock size={10} />
              {formatMessageTime(msg.created_at, locale)}
              {msg.sender?.name && <span className="ml-1">· {msg.sender.name}</span>}
            </div>
            {/* Penalty toggle for admins on agent messages */}
            {canPenalize && (
              <button
                onClick={() => onPenalty(Number(msg.id), isFaulty ? 0 : 1)}
                className={clsx(
                  'flex items-center gap-1 rounded-full px-1.5 py-0.5 transition-all text-[10px] font-medium',
                  isFaulty
                    ? 'bg-red-500/30 text-red-100 hover:bg-red-500/50'
                    : 'opacity-0 group-hover:opacity-100 hover:bg-indigo-500/30 text-indigo-200'
                )}
                title={isFaulty ? t('messages.removeFault') : t('messages.markAsFaulty')}
              >
                <Flag size={10} />
                {isFaulty ? t('messages.faulty') : t('messages.markAsFaulty')}
              </button>
            )}
            {/* Show faulty badge for non-admins */}
            {!canPenalize && isFaulty && (
              <span className="flex items-center gap-1 bg-red-500/30 text-red-100 rounded-full px-1.5 py-0.5 text-[10px] font-medium">
                <Flag size={10} /> {t('messages.faulty')}
              </span>
            )}
          </div>
          {msg.sender_type === 'agent' && (msg.metadata as Record<string, unknown>)?.sms_sent === true && (
            <div className="flex items-center gap-1 mt-1.5 px-2 py-1 bg-emerald-500/20 rounded text-[11px] text-emerald-100">
              <Smartphone size={10} />
              <span>SMS ile iletildi{(msg.metadata as Record<string, string>)?.sms_to ? ' · ' + (msg.metadata as Record<string, string>).sms_to : ''}</span>
            </div>
          )}
          {msg.sender_type === 'agent' && (msg.metadata as Record<string, unknown>)?.sms_sent === false && (
            <div className="flex items-center gap-1 mt-1.5 px-2 py-1 bg-red-500/20 rounded text-[11px] text-red-100">
              <AlertTriangle size={10} />
              <span>SMS gönderilemedi: {(msg.metadata as Record<string, string>)?.sms_error ?? 'bilinmeyen hata'}</span>
            </div>
          )}
          {msg.sender_type === 'agent' && (msg.metadata as Record<string, string>)?.sms_skipped === 'no_phone' && (
            <div className="flex items-center gap-1 mt-1.5 px-2 py-1 bg-amber-500/20 rounded text-[11px] text-amber-100">
              <AlertTriangle size={10} />
              <span>Mesai dışı — müşterinin telefon numarası yok, SMS gönderilemedi</span>
            </div>
          )}
          {msg.sender_type === 'agent' && (msg.metadata as Record<string, boolean | string>)?.used_human_agent && (
            <div className="flex items-center gap-1 mt-1.5 px-2 py-1 bg-indigo-500/20 rounded text-[11px] text-indigo-200">
              <Clock size={10} />
              <span>{t('messages.outsideWindow')}</span>
            </div>
          )}
          {msg.sender_type === 'agent' && (msg.metadata as Record<string, string>)?.delivery_error && (
            <div className="flex items-center gap-1 mt-1.5 px-2 py-1 bg-red-500/20 rounded text-xs text-red-100">
              <AlertTriangle size={11} />
              <span>{t('messages.deliveryFailed', { error: (msg.metadata as Record<string, string>).delivery_error })}</span>
            </div>
          )}
          {/* Firma SMTP'si kullanılamayıp yerel sendmail'e düşüldü: mail "gönderildi"
              ama SPF/DKIM olmadığı için Gmail/Outlook reddetmiş olabilir. */}
          {msg.sender_type === 'agent'
            && !(msg.metadata as Record<string, string>)?.delivery_error
            && (msg.metadata as Record<string, string>)?.delivery_warning && (
            <div className="flex items-center gap-1 mt-1.5 px-2 py-1 bg-amber-500/20 rounded text-xs text-amber-100">
              <AlertTriangle size={11} />
              <span>{t('messages.deliverySuspect')}</span>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

/**
 * Pazaryeri (Hepsiburada/Trendyol) cevabı gönderilmeden ÖNCE composer'da sarı uyarı.
 * Hepsiburada API'si (code 1001) şunları reddediyor: link/telefon/e-posta/site adresi
 * ("iletişim bilgisi paylaşılamaz") ve "… etmenizi tavsiye/rica ederiz" kalıbı
 * (küfür filtresi yanlış pozitifi — 5 Eyl + 14 Eyl 2026; "… etmenizi öneririz" geçiyor).
 * Ağu-Eyl'de 20 cevap bu yüzden sessizce kaybolmuştu.
 */
function marketplaceReplyWarnings(text: string): string[] {
  const w: string[] = []
  if (!text) return w
  if (/https?:\/\/|www\.|\b[a-z0-9-]+\.(com|net|org|co)(\.tr)?\b/i.test(text)) w.push('link / site adresi')
  if (/[^\s@]+@[^\s@]+\.[^\s@]+/.test(text)) w.push('e-posta adresi')
  if (/(\+90|\b0)?[\s(]*\d{3}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}\b/.test(text)) w.push('telefon numarası')
  if (/\b(instagram|whatsapp|telegram|web sitemiz|sitemizden)\b/i.test(text)) w.push('başka platforma yönlendirme')
  if (/etmenizi\s+(tavsiye|rica)\s+eder/i.test(text)) w.push('"… etmenizi tavsiye/rica ederiz" kalıbı (küfür filtresine takılıyor → "… etmenizi öneririz" yazın)')
  return w
}

function MessageContent({ content, isCustomer, onImageClick }: {
  content: string
  isCustomer: boolean
  onImageClick: (url: string) => void
}) {
  // Treat "[]", empty, or whitespace-only as no content
  if (!content || content === '[]' || !content.trim()) return null

  // Check if content has inline attachments
  if (!INLINE_ATTACHMENT_RE.test(content)) {
    return <p className="text-sm whitespace-pre-wrap">{content}</p>
  }

  // Reset regex lastIndex
  INLINE_ATTACHMENT_RE.lastIndex = 0

  // Split content into text parts and attachment URLs
  const parts: { type: 'text' | 'image' | 'file'; value: string }[] = []
  let lastIndex = 0
  let match: RegExpExecArray | null

  while ((match = INLINE_ATTACHMENT_RE.exec(content)) !== null) {
    // Text before this match
    if (match.index > lastIndex) {
      const text = content.slice(lastIndex, match.index).trim()
      if (text) parts.push({ type: 'text', value: text })
    }
    const url = match[1]
    const ext = url.split('.').pop()?.toLowerCase().split('?')[0] || ''
    parts.push({ type: IMAGE_EXTS.includes(ext) ? 'image' : 'file', value: url })
    lastIndex = match.index + match[0].length
  }

  // Remaining text after last match
  if (lastIndex < content.length) {
    const text = content.slice(lastIndex).trim()
    if (text) parts.push({ type: 'text', value: text })
  }

  return (
    <div className="space-y-1.5">
      {parts.map((part, i) => {
        if (part.type === 'text') {
          return <p key={i} className="text-sm whitespace-pre-wrap">{part.value}</p>
        }
        if (part.type === 'image') {
          return (
            <img
              key={i}
              src={part.value}
              alt=""
              className="max-w-[240px] max-h-[240px] rounded-lg object-cover cursor-pointer hover:opacity-90 transition-opacity"
              loading="lazy"
              onClick={() => onImageClick(part.value)}
            />
          )
        }
        // file (pdf, zip, etc.)
        return (
          <a
            key={i}
            href={part.value}
            target="_blank"
            rel="noopener noreferrer"
            className={clsx(
              'inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs',
              isCustomer ? 'bg-gray-200 text-gray-700 hover:bg-gray-300' : 'bg-indigo-500 text-indigo-100 hover:bg-indigo-400'
            )}
          >
            <FileText size={12} />
            <span>{part.value.split('/').pop()}</span>
            <ExternalLink size={10} />
          </a>
        )
      })}
    </div>
  )
}

interface Attachment {
  type: string
  payload?: {
    url?: string
    title?: string
    reel_video_id?: string
    ig_post_media_id?: string
  }
}

function MediaExpiredBadge({ label, href, isCustomer }: { label: string; href?: string; isCustomer: boolean }) {
  const cls = clsx(
    'flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs',
    isCustomer ? 'bg-gray-200 text-gray-500' : 'bg-indigo-500/60 text-indigo-100'
  )
  if (href) {
    return (
      <a href={href} target="_blank" rel="noopener noreferrer" className={clsx(cls, 'hover:opacity-80')}>
        <ImageIcon size={14} />
        <span>{label}</span>
        <ExternalLink size={10} />
      </a>
    )
  }
  return <div className={cls}><ImageIcon size={14} /><span>{label}</span></div>
}

function AttachmentImage({ url, isCustomer, onImageClick, t }: { url: string; isCustomer: boolean; onImageClick: (u: string) => void; t: (key: string) => string }) {
  const [failed, setFailed] = useState(false)
  if (failed) return <MediaExpiredBadge label={t('messages.imageExpired')} isCustomer={isCustomer} />
  return (
    <img
      src={url}
      alt=""
      className="max-w-[240px] max-h-[240px] rounded-lg object-cover cursor-pointer hover:opacity-90 transition-opacity"
      loading="lazy"
      onClick={() => onImageClick(url)}
      onError={() => setFailed(true)}
    />
  )
}

function AttachmentVideo({ att, isCustomer, t }: { att: Attachment; isCustomer: boolean; t: (key: string) => string }) {
  const [failed, setFailed] = useState(false)
  const url = att.payload?.url || ''
  const reelId = att.payload?.reel_video_id
  const title = att.payload?.title
  const isReel = att.type === 'ig_reel' || att.type === 'reel'
  const instagramUrl = reelId ? `https://www.instagram.com/reel/${reelId}/` : undefined

  if (failed) {
    return (
      <div className="max-w-[240px]">
        <MediaExpiredBadge
          label={isReel ? t('messages.instagramReel') : t('messages.videoExpired')}
          href={instagramUrl}
          isCustomer={isCustomer}
        />
        {title && (
          <p className={clsx('text-xs mt-0.5 line-clamp-2', isCustomer ? 'text-gray-500' : 'text-indigo-200')}>
            {title}
          </p>
        )}
      </div>
    )
  }

  return (
    <div className="relative max-w-[240px]">
      <video
        src={url}
        className="max-w-[240px] max-h-[240px] rounded-lg object-cover"
        controls
        preload="metadata"
        onError={() => setFailed(true)}
      />
      {title && (
        <p className={clsx('text-xs mt-0.5 line-clamp-2', isCustomer ? 'text-gray-500' : 'text-indigo-200')}>
          {title}
        </p>
      )}
      {instagramUrl && (
        <a
          href={instagramUrl}
          target="_blank"
          rel="noopener noreferrer"
          className={clsx('inline-flex items-center gap-1 mt-0.5 text-[10px]', isCustomer ? 'text-blue-600' : 'text-indigo-200')}
        >
          <Instagram size={10} /> {t('messages.viewOnInstagram')} <ExternalLink size={8} />
        </a>
      )}
    </div>
  )
}

/**
 * WhatsApp medyası: webhook yalnız media_id kaydeder; içerik auth'lu endpoint'ten
 * blob olarak çekilip gösterilir (backend ilk istekte Meta'dan indirip cache'ler).
 */
function WhatsAppMedia({ messageId, mediaType, isCustomer, onImageClick, t }: {
  messageId: number | string
  mediaType: string
  isCustomer: boolean
  onImageClick: (url: string) => void
  t: (key: string) => string
}) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false
    api.get(`/messages/${messageId}/media`, { responseType: 'blob' })
      .then((res) => {
        objectUrl = URL.createObjectURL(res.data as Blob)
        if (!cancelled) setUrl(objectUrl)
      })
      .catch(() => { if (!cancelled) setFailed(true) })
    return () => { cancelled = true; if (objectUrl) URL.revokeObjectURL(objectUrl) }
  }, [messageId])

  if (failed) return <MediaExpiredBadge label={t('messages.imageExpired')} isCustomer={isCustomer} />
  if (!url) return <div className="w-[180px] h-[130px] rounded-lg bg-gray-200 animate-pulse" />

  if (mediaType === 'image' || mediaType === 'sticker') {
    return (
      <img
        src={url}
        alt=""
        className="max-w-[240px] max-h-[280px] rounded-lg object-cover cursor-pointer hover:opacity-90 transition-opacity"
        onClick={() => onImageClick(url)}
      />
    )
  }
  if (mediaType === 'video') {
    return <video src={url} className="max-w-[240px] max-h-[240px] rounded-lg" controls preload="metadata" />
  }
  if (mediaType === 'audio') {
    return <audio src={url} controls className="max-w-[240px]" />
  }
  return (
    <a href={url} download className={clsx(
      'flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs',
      isCustomer ? 'bg-gray-200 text-gray-600 hover:bg-gray-300' : 'bg-indigo-500/60 text-indigo-100'
    )}>
      <ImageIcon size={14} /> Dosyayı indir
    </a>
  )
}

function MessageAttachments({ messageId, metadata, isCustomer, onImageClick, t }: {
  messageId: number | string
  metadata: Record<string, unknown> | null
  isCustomer: boolean
  onImageClick: (url: string) => void
  t: (key: string) => string
}) {
  if (!metadata) return null

  // WhatsApp medyası (media_id tabanlı — Instagram/Messenger'ın URL'li attachments'ından farklı)
  if (metadata.media_id && metadata.media_type) {
    return (
      <div className="mb-1.5">
        <WhatsAppMedia
          messageId={messageId}
          mediaType={String(metadata.media_type)}
          isCustomer={isCustomer}
          onImageClick={onImageClick}
          t={t}
        />
      </div>
    )
  }

  const rawAttachments = (metadata.attachments as Attachment[]) || []
  // Filter out ig_post duplicates (same content as share attachment)
  const attachments = rawAttachments.filter(att => att.type !== 'ig_post')
  if (attachments.length === 0) return null

  return (
    <div className="flex flex-wrap gap-1.5 mb-1.5">
      {attachments.map((att, i) => {
        const url = att.payload?.url

        // No URL — show placeholder for known types
        if (!url) {
          if (att.type === 'template') {
            return (
              <div key={i} className={clsx(
                'flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs',
                isCustomer ? 'bg-gray-200 text-gray-500' : 'bg-indigo-500/60 text-indigo-100'
              )}>
                <Share2 size={12} />
                <span>{t('messages.sharedPost')}</span>
              </div>
            )
          }
          return null
        }

        // Image
        if (att.type === 'image') {
          return <AttachmentImage key={i} url={url} isCustomer={isCustomer} onImageClick={onImageClick} t={t} />
        }

        // Video / Reel
        if (att.type === 'video' || att.type === 'ig_reel' || att.type === 'reel') {
          return <AttachmentVideo key={i} att={att} isCustomer={isCustomer} t={t} />
        }

        // Story mention / IG story
        if (att.type === 'story_mention' || att.type === 'ig_story') {
          return (
            <AttachmentStory key={i} url={url} type={att.type} isCustomer={isCustomer} onImageClick={onImageClick} t={t} />
          )
        }

        // Share (shared post/link) — redirect to Instagram post if permalink exists
        if (att.type === 'share') {
          const permalink = (att.payload as Record<string, unknown>)?.permalink as string | undefined
          const shareUrl = permalink || url
          const isIgPost = !!permalink || !!att.payload?.ig_post_media_id
          return (
            <a
              key={i}
              href={shareUrl}
              target="_blank"
              rel="noopener noreferrer"
              className={clsx(
                'flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs max-w-[240px]',
                isCustomer ? 'bg-gray-200 text-gray-700 hover:bg-gray-300' : 'bg-indigo-500 text-indigo-100 hover:bg-indigo-400'
              )}
            >
              {isIgPost ? <Instagram size={12} className="shrink-0" /> : <Share2 size={12} className="shrink-0" />}
              <span className="truncate">{att.payload?.title || t('messages.sharedPost')}</span>
              <ExternalLink size={10} className="shrink-0" />
            </a>
          )
        }

        // Ephemeral / template / fallback
        return (
          <a
            key={i}
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className={clsx(
              'flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs',
              isCustomer ? 'bg-gray-200 text-gray-700 hover:bg-gray-300' : 'bg-indigo-500 text-indigo-100 hover:bg-indigo-400'
            )}
          >
            <Film size={12} />
            <span>{att.type === 'ephemeral' ? (t('messages.attachment')) : t('messages.attachment')}</span>
            <ExternalLink size={10} />
          </a>
        )
      })}
    </div>
  )
}

function AttachmentStory({ url, type, isCustomer, onImageClick, t }: { url: string; type: string; isCustomer: boolean; onImageClick: (u: string) => void; t: (key: string) => string }) {
  const [failed, setFailed] = useState(false)
  if (failed) return <MediaExpiredBadge label={type === 'story_mention' ? t('messages.storyMention') : t('messages.storyReply')} isCustomer={isCustomer} />
  return (
    <div className="relative max-w-[200px]">
      <img
        src={url}
        alt=""
        className="max-w-[200px] max-h-[280px] rounded-lg object-cover cursor-pointer hover:opacity-90"
        loading="lazy"
        onClick={() => onImageClick(url)}
        onError={() => setFailed(true)}
      />
      <span className={clsx(
        'absolute top-1.5 left-1.5 px-1.5 py-0.5 rounded text-[10px] font-medium',
        isCustomer ? 'bg-black/50 text-white' : 'bg-white/30 text-white'
      )}>
        {type === 'story_mention' ? t('messages.storyMention') : t('messages.storyReply')}
      </span>
    </div>
  )
}

export default function ConversationDetail({ conversationId, onUpdate }: Props) {
  const { t, i18n } = useTranslation()
  const { user, canUseAi } = useAuth()
  const storeUrl = storefrontUrl(user)
  const [conversation, setConversation] = useState<Conversation | null>(null)
  const [messages, setMessages] = useState<Message[]>([])
  const [newMessage, setNewMessage] = useState('')
  const [showQuickReplies, setShowQuickReplies] = useState(false)
  const [showEmoji, setShowEmoji] = useState(false)
  const [sending, setSending] = useState(false)
  const [loading, setLoading] = useState(true)
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null)
  const [aiLoading, setAiLoading] = useState(false)
  const [aiOriginalContent, setAiOriginalContent] = useState<string | null>(null)
  const [showDropdown, setShowDropdown] = useState(false)
  const [sendError, setSendError] = useState<string | null>(null)
  // Site sohbeti (webchat): mesai dışında cevap otomatik SMS'e düşer. Bu anahtar
  // mesai İÇİNDE de SMS gönderilmesini ister (müşteri sayfadan ayrılmışsa).
  const [sendAsSms, setSendAsSms] = useState(false)
  const [waTplOpen, setWaTplOpen] = useState(false)
  const [waTemplates, setWaTemplates] = useState<{ key: string; label: string; category?: string; group?: string; param_label: string | null; buttons?: string[]; preview: string }[]>([])
  const [waTplQuery, setWaTplQuery] = useState('')
  const [waQualityWarning, setWaQualityWarning] = useState<string | null>(null)
  const [waTemplate, setWaTemplate] = useState('siparis_bilgilendirme')
  const [waParam, setWaParam] = useState('')
  const [attachFile, setAttachFile] = useState<File | null>(null)
  const [attachPreview, setAttachPreview] = useState<string | null>(null)
  const messagesEndRef = useRef<HTMLDivElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const dropdownRef = useRef<HTMLDivElement>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const openedAtRef = useRef<number>(Date.now())
  const typingRef = useRef(false)

  // Sessiz tazeleme guard'ı: composer'da gönderilmemiş metin/ek varken tur atlanır
  useEffect(() => {
    typingRef.current = newMessage.trim().length > 0 || !!attachFile
  }, [newMessage, attachFile])

  // WhatsApp dosya eki seçimi (görsel jpg/png 5MB, video mp4 16MB, PDF 16MB)
  const pickAttachment = (f: File | null) => {
    if (f) {
      const allowed = ['image/jpeg', 'image/png', 'video/mp4', 'application/pdf']
      if (!allowed.includes(f.type)) {
        setSendError(i18n.language === 'tr'
          ? 'Desteklenmeyen dosya türü — JPG, PNG, MP4 veya PDF gönderilebilir.'
          : 'Unsupported file type — JPG, PNG, MP4 or PDF only.')
        return
      }
      const limitMb = f.type.startsWith('image/') ? 5 : 16
      if (f.size > limitMb * 1024 * 1024) {
        setSendError(i18n.language === 'tr'
          ? `Dosya çok büyük — WhatsApp sınırı bu tür için ${limitMb} MB.`
          : `File too large — WhatsApp limit for this type is ${limitMb} MB.`)
        return
      }
    }
    setAttachPreview((prev) => {
      if (prev) URL.revokeObjectURL(prev)
      return f && f.type.startsWith('image/') ? URL.createObjectURL(f) : null
    })
    setAttachFile(f)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  useEffect(() => {
    // Bayat-yanıt koruması: hızlı konuşma değiştirmede önceki GET sonradan
    // dönerse ekran eski konuşmayı gösterirken gönderim yeni ID'ye gider
    // (yanlış müşteriye mesaj). Eski isteğin yanıtı burada çöpe atılır.
    let active = true
    setLoading(true)
    setAiOriginalContent(null)
    setNewMessage('')
    setShowEmoji(false)
    setAttachFile(null)
    setAttachPreview((prev) => { if (prev) URL.revokeObjectURL(prev); return null })
    openedAtRef.current = Date.now()
    api.get(`/conversations/${conversationId}`).then((res) => {
      if (!active) return
      setConversation(res.data)
      setMessages(res.data.messages || [])
      setLoading(false)
      // Auto-fill AI suggestion if available
      if (res.data.ai_suggestion) {
        setNewMessage(res.data.ai_suggestion)
        setAiOriginalContent(res.data.ai_suggestion)
        // Auto-resize textarea to fit suggestion
        requestAnimationFrame(() => {
          if (textareaRef.current) {
            textareaRef.current.style.height = 'auto'
            textareaRef.current.style.height = Math.min(textareaRef.current.scrollHeight, 200) + 'px'
          }
        })
      }
      if (!res.data.is_read) {
        api.post(`/conversations/${conversationId}/mark-read`)
      }
    })
    return () => { active = false }
  }, [conversationId])

  // Açık konuşmayı sessizce tazele: yeni müşteri mesajı sayfa yenilemeden görünsün.
  // Composer'a (newMessage) asla dokunmaz; mesaj listesi yalnız değişiklik varsa güncellenir
  // (aksi halde her turda scroll-to-bottom tetiklenir).
  // Temsilci YAZARKEN tur tamamen atlanır — yazma sırasında ekran oynamaz.
  useEffect(() => {
    let active = true
    const timer = setInterval(() => {
      if (typingRef.current) return
      api.get(`/conversations/${conversationId}`).then((res) => {
        if (!active || res.data.id !== conversationId) return
        const incoming: Message[] = res.data.messages || []
        setMessages((prev) => {
          const changed = prev.length !== incoming.length
            || (incoming.length > 0 && prev[prev.length - 1]?.id !== incoming[incoming.length - 1]?.id)
          return changed ? incoming : prev
        })
        setConversation((prev) => (prev && prev.id === res.data.id ? { ...prev, ...res.data } : prev))
        if (!res.data.is_read) {
          api.post(`/conversations/${conversationId}/mark-read`)
        }
      }).catch(() => { /* geçici ağ hatasında sessiz kal, sonraki turda dener */ })
    }, 10000)
    return () => { active = false; clearInterval(timer) }
  }, [conversationId])

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages])

  // Close dropdown on outside click
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setShowDropdown(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  const handleSend = async () => {
    if ((!newMessage.trim() && !attachFile) || sending) return
    // Emniyet kemeri: ekranda yüklü konuşma ile gönderim hedefi aynı olmalı —
    // uyuşmazlıkta gönderme (yanlış müşteriye mesaj gitmesin).
    if (!conversation || conversation.id !== conversationId) {
      setSendError(t('messages.conversationMismatch'))
      return
    }
    // Ne telefon ne BSUID: gönderim Meta'da düşer, denemeyelim.
    if ((conversation.channel?.type ?? '') === 'whatsapp' && !canReachOnWhatsApp(conversation.customer)) {
      setSendError(i18n.language === 'tr'
        ? 'Müşterinin geçerli bir WhatsApp adresi yok — bu konuşmaya cevap iletilemez.'
        : 'Customer has no valid WhatsApp address — this reply cannot be delivered.')
      return
    }
    const content = newMessage.trim()

    // Dosya ekli gönderim (yalnız WhatsApp): iyimser balon yok — media_id sunucudan
    // döner, balon gerçek kayıtla eklenir; upload sürerken buton spinner gösterir.
    if (attachFile) {
      setSending(true)
      setSendError(null)
      try {
        const fd = new FormData()
        fd.append('attachment', attachFile)
        if (content) fd.append('content', content)
        const handlingSecs = Math.round((Date.now() - openedAtRef.current) / 1000)
        if (handlingSecs > 0) fd.append('handling_time_seconds', String(handlingSecs))
        const res = await api.post(`/conversations/${conversationId}/messages`, fd, {
          headers: { 'Content-Type': 'multipart/form-data' },
        })
        setMessages((prev) => (prev.some((m) => m.id === res.data.id) ? prev : [...prev, res.data]))
        setNewMessage('')
        setAiOriginalContent(null)
        pickAttachment(null)
        openedAtRef.current = Date.now()
        if (res.data.send_error) setSendError(res.data.send_error)
        onUpdate?.()
      } catch (err) {
        const e = err as { response?: { data?: { message?: string } } }
        setSendError(e.response?.data?.message ?? t('messages.sendFailed'))
      } finally {
        setSending(false)
      }
      return
    }
    const aiOrig = aiOriginalContent
    const handlingSeconds = Math.round((Date.now() - openedAtRef.current) / 1000)

    // İyimser gönderim: Meta/WA API çağrısı 1-2 sn sürüyor — balon anında
    // görünsün, composer anında boşalsın. Hata olursa mesaj geri alınır ve
    // yazılan metin composer'a iade edilir.
    const tempId = `tmp_${Date.now()}`
    const optimistic: Message = {
      id: tempId,
      conversation_id: conversationId,
      sender_type: 'agent',
      sender_id: user?.id ?? null,
      content,
      is_ai_generated: !!aiOrig,
      ai_original_content: aiOrig,
      penalty_points: 0,
      penalized_by: null,
      metadata: null,
      created_at: new Date().toISOString(),
      sender: user ? { id: user.id, name: user.name, avatar: null } : null,
    }
    setMessages((prev) => [...prev, optimistic])
    setNewMessage('')
    setAiOriginalContent(null)
    openedAtRef.current = Date.now()
    setSending(true)
    setSendError(null)

    try {
      const res = await api.post(`/conversations/${conversationId}/messages`, {
        content,
        is_ai_generated: !!aiOrig,
        ai_original_content: aiOrig,
        handling_time_seconds: handlingSeconds > 0 ? handlingSeconds : null,
        // Site sohbeti: mesai dışında cevap zaten SMS'e düşer; bu bayrak mesai
        // içinde de SMS gönderilmesini ister (müşteri sayfadan ayrılmışsa).
        send_sms: sendAsSms || undefined,
      })
      setMessages((prev) => {
        // Sessiz tazeleme POST bitmeden gerçek kaydı getirmiş olabilir — çift ekleme
        if (prev.some((m) => m.id === res.data.id)) return prev.filter((m) => m.id !== tempId)
        return prev.map((m) => (m.id === tempId ? res.data : m))
      })
      if (res.data.send_error) {
        setSendError(res.data.send_error)
      }
      onUpdate?.()
    } catch {
      setMessages((prev) => prev.filter((m) => m.id !== tempId))
      setNewMessage(content)
      setAiOriginalContent(aiOrig)
      setSendError(t('messages.sendFailed'))
    } finally {
      setSending(false)
    }
  }

  // WhatsApp: 24s penceresi kapalıyken onaylı şablonla sohbeti başlat.
  // Şablonların bir kısmı parametreli ({{2}} zorunlu), teşekkür şablonu gibi
  // parametresiz olanlarda giriş kutusu hiç gösterilmez.
  const openWaTemplatePanel = () => {
    setWaTplOpen((v) => !v)
    if (waTemplates.length === 0) {
      api.get('/conversations/start/options').then((res) => {
        const tpls = res.data.wa_templates ?? []
        setWaTemplates(tpls)
        setWaQualityWarning(res.data.quality_warning ?? null)
        if (tpls.length > 0) setWaTemplate(tpls[0].key)
      })
    }
  }

  const selectedWaTemplate = waTemplates.find((t2) => t2.key === waTemplate)

  // Hazır cevaplar da şablona dönüştüğü için liste uzun: arama + grup başlıkları.
  const visibleWaTemplates = (() => {
    const q = waTplQuery.trim().toLocaleLowerCase('tr')
    if (!q) return waTemplates
    return waTemplates.filter(
      (t2) => t2.label.toLocaleLowerCase('tr').includes(q) || t2.preview.toLocaleLowerCase('tr').includes(q),
    )
  })()
  const waTemplateGroups = visibleWaTemplates.reduce<Record<string, typeof waTemplates>>((acc, t2) => {
    const g = t2.group ?? 'Diğer'
    ;(acc[g] ??= []).push(t2)
    return acc
  }, {})

  const handleSendWaTemplate = async () => {
    if (sending || (selectedWaTemplate?.param_label && !waParam.trim())) return
    setSending(true)
    setSendError(null)
    try {
      const res = await api.post(`/conversations/${conversationId}/whatsapp-template`, {
        template: waTemplate,
        template_param: selectedWaTemplate?.param_label ? waParam.trim() : null,
      })
      setMessages((prev) => [...prev, res.data])
      setWaTplOpen(false)
      setWaParam('')
      onUpdate?.()
    } catch (err) {
      const e = err as { response?: { data?: { message?: string } } }
      setSendError(e.response?.data?.message ?? (i18n.language === 'tr' ? 'Şablon gönderilemedi' : 'Template send failed'))
    } finally {
      setSending(false)
    }
  }

  const handleResolve = async () => {
    try {
      const res = await api.patch(`/conversations/${conversationId}`, { status: 'resolved' })
      setConversation(res.data)
      setShowDropdown(false)
      onUpdate?.()
    } catch {
      // silently fail
    }
  }

  const handleAiGenerate = async () => {
    if (aiLoading) return
    setAiLoading(true)
    try {
      const res = await api.post('/ai/generate', { conversation_id: conversationId })
      const text = res.data.response || ''
      setNewMessage(text)
      setAiOriginalContent(text)
      requestAnimationFrame(() => {
        if (textareaRef.current) {
          textareaRef.current.style.height = 'auto'
          textareaRef.current.style.height = Math.min(textareaRef.current.scrollHeight, 200) + 'px'
          textareaRef.current.focus()
        }
      })
    } catch {
      // silently fail
    } finally {
      setAiLoading(false)
    }
  }

  // Hazır cevap seçildiğinde: yazma alanı boşsa değiştirir, doluysa sonuna ekler.
  const applyQuickReply = (content: string) => {
    setNewMessage((prev) => {
      const base = prev.trim()
      return base ? `${base}\n\n${content}` : content
    })
    requestAnimationFrame(() => {
      if (textareaRef.current) {
        textareaRef.current.style.height = 'auto'
        textareaRef.current.style.height = Math.min(textareaRef.current.scrollHeight, 200) + 'px'
        textareaRef.current.focus()
      }
    })
  }

  // Emoji imleç konumuna eklenir; panel açık kalır (art arda ekleme için)
  const insertEmoji = (emoji: string) => {
    const el = textareaRef.current
    setNewMessage((prev) => {
      const start = el?.selectionStart ?? prev.length
      const end = el?.selectionEnd ?? prev.length
      requestAnimationFrame(() => {
        if (el) {
          el.focus()
          const pos = start + emoji.length
          el.setSelectionRange(pos, pos)
          el.style.height = 'auto'
          el.style.height = Math.min(el.scrollHeight, 200) + 'px'
        }
      })
      return prev.slice(0, start) + emoji + prev.slice(end)
    })
  }

  const handleUrgentToggle = async () => {
    setShowDropdown(false)
    if (!conversation) return
    try {
      await api.patch(`/conversations/${conversationId}`, { is_urgent: !conversation.is_urgent })
      setConversation((prev) => prev ? { ...prev, is_urgent: !prev.is_urgent } : prev)
      onUpdate?.()
    } catch {
      // silently fail
    }
  }

  // Meta 24h messaging window check (must be before conditional return — React hooks rule)
  const metaWindowInfo = useMemo(() => {
    if (!conversation) return null
    const ct = conversation.channel?.type ?? ''
    const isMeta = ct === 'facebook-message' || ct === 'instagram-message'
    if (!isMeta || messages.length === 0) return null
    const lastCustomerMsg = [...messages].reverse().find(m => m.sender_type === 'customer')
    if (!lastCustomerMsg) return null
    const lastTime = new Date(lastCustomerMsg.created_at).getTime()
    const hoursAgo = (Date.now() - lastTime) / (1000 * 60 * 60)
    const isOutside = hoursAgo > 24
    const remainingHours = Math.max(0, 24 - hoursAgo)
    const daysLeft = Math.max(0, 7 - (hoursAgo / 24))
    return { isOutside, hoursAgo, remainingHours, daysLeft }
  }, [conversation, messages])

  // WhatsApp 24h window: dışındaysa serbest metin iletilmez — şablonla başlatmak gerekir
  const waWindowInfo = useMemo(() => {
    if (!conversation || (conversation.channel?.type ?? '') !== 'whatsapp') return null
    const lastCustomerMsg = [...messages].reverse().find(m => m.sender_type === 'customer')
    if (!lastCustomerMsg) return { isOutside: true, remainingHours: 0 }
    const hoursAgo = (Date.now() - new Date(lastCustomerMsg.created_at).getTime()) / (1000 * 60 * 60)
    return { isOutside: hoursAgo > 24, remainingHours: Math.max(0, 24 - hoursAgo) }
  }, [conversation, messages])

  if (loading || !conversation) {
    return (
      <div className="flex justify-center items-center h-full">
        <div className="w-6 h-6 border-3 border-indigo-600 border-t-transparent rounded-full animate-spin" />
      </div>
    )
  }

  const channelType = conversation.channel?.type ?? ''
  const ChannelIcon = channelIcons[channelType] ?? Globe

  // Meta numarasız kimlik gönderdiğinde (wa_id = "TR.1683650359366855") müşteriye
  // ne serbest mesaj ne şablon iletilebilir. Composer'ı kilitle ki cevap yazılıp
  // "gitti" sanılmasın — 9 Eyl'de kurtarılan konuşmalarda tam olarak bu oldu.
  const waUnreachable = channelType === 'whatsapp' && !canReachOnWhatsApp(conversation.customer)
  // Telefonunu gizleyen müşteri (Meta kullanıcı adı akışı): cevap ve şablon Meta kimliğiyle gider.
  const waHiddenNumber = channelType === 'whatsapp' && !waUnreachable && isWaHiddenNumber(conversation.customer)
  const waUsername = (conversation.customer?.metadata as Record<string, unknown> | undefined)?.wa_username as string | undefined

  const contextIcon = { product: Package, order: ShoppingCart, ticket: Ticket }
  const CtxIcon = conversation.context ? contextIcon[conversation.context.context_type] : null
  const ctxData = conversation.context?.data as Record<string, string> | undefined

  // Channel type flags
  const isTrendyol = channelType === 'trendyol-product' || channelType === 'trendyol-order'
  const isHepsiburada = channelType === 'hepsiburada-product' || channelType === 'hepsiburada-order'
  const isTicimax = channelType === 'ticimax-ticket'
  const ticimaxMeta = isTicimax && conversation.metadata ? conversation.metadata as Record<string, string> : null
  const ticimaxEklemeTarihi = ticimaxMeta?.ekleme_tarihi
    ? new Date(ticimaxMeta.ekleme_tarihi).toLocaleString(locale, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    : null
  const ticimaxDurum = ticimaxMeta?.durum === 'Beklemede' || ticimaxMeta?.durum === 'Açık'
    ? 'Beklemede'
    : ticimaxMeta?.durum || null

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="p-3 md:p-4 border-b border-gray-100 shrink-0">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 md:gap-3 min-w-0">
            <div className="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-br from-indigo-400 to-indigo-600 rounded-full flex items-center justify-center text-xs md:text-sm font-semibold text-white shadow-sm shrink-0">
              {conversation.customer?.name?.charAt(0)?.toUpperCase() ?? '?'}
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="font-semibold text-sm flex items-center gap-1.5">
                  {conversation.customer?.name}
                  {channelType === 'email' && conversation.customer?.email && (
                    <span className="font-normal text-xs text-gray-400">({conversation.customer.email})</span>
                  )}
                  {(channelType === 'whatsapp' || channelType === 'sms') && customerPhone(conversation.customer) && (
                    <span className="font-normal text-xs text-gray-400">{customerPhone(conversation.customer)}</span>
                  )}
                  {(() => {
                    const igUser = (channelType === 'instagram-message' || channelType === 'instagram-comment')
                      ? String((conversation.customer?.metadata as Record<string, string> | null)?.instagram_username ?? '')
                      : ''
                    return igUser ? (
                      <a
                        href={`https://www.instagram.com/${igUser}/`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-0.5 font-normal text-xs text-pink-500 hover:text-pink-700 transition-colors"
                      >
                        @{igUser}
                        <ArrowUpRight size={10} />
                      </a>
                    ) : null
                  })()}
                </h2>
                {conversation.is_urgent && (
                  <span className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-medium">
                    <AlertTriangle size={11} /> {t('messages.urgent')}
                  </span>
                )}
              </div>
              <div className="flex items-center gap-2 mt-0.5">
                <span className={clsx('inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium', getChannelBadgeClasses(channelType))}>
                  <ChannelIcon size={12} />
                  {getChannelLabel(channelType)}
                </span>
                {conversation.channel?.category && (
                  <span className="text-xs text-gray-400">{conversation.channel.category}</span>
                )}
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <span className={clsx(
              'text-xs px-3 py-1 rounded-full font-medium',
              conversation.status === 'open' ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700',
            )}>
              {conversation.status === 'open' ? t('messages.waitingForReply') : t('messages.replied')}
            </span>

            {/* Dropdown menu */}
            <div className="relative" ref={dropdownRef}>
              <button
                onClick={() => setShowDropdown(!showDropdown)}
                className="p-1.5 rounded-lg hover:bg-gray-100 transition-colors"
              >
                <ChevronDown size={16} className="text-gray-500" />
              </button>
              {showDropdown && (
                <div className="absolute right-0 top-full mt-1 w-48 bg-white border border-gray-200 rounded-xl shadow-lg z-20 py-1">
                  <button
                    onClick={handleUrgentToggle}
                    className="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                  >
                    <AlertTriangle size={14} className={conversation.is_urgent ? 'text-gray-400' : 'text-red-500'} />
                    {conversation.is_urgent ? t('messages.removeUrgent') : t('messages.markUrgent')}
                  </button>
                  {conversation.status !== 'resolved' && (
                    <button
                      onClick={handleResolve}
                      className="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                      <CheckCircle size={14} className="text-green-500" />
                      {t('messages.markResolved')}
                    </button>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Context (from context relation or metadata) */}
        {conversation.context && ctxData && (
          <div className="mt-3 p-3 bg-gray-50 rounded-lg text-sm">
            {conversation.context.context_type === 'product' && (
              <div className="flex items-center gap-3">
                {ctxData.image ? (
                  <img src={ctxData.image} alt="" className="w-12 h-12 rounded-lg object-cover border border-gray-200" />
                ) : (
                  <div className="w-12 h-12 bg-gray-200 rounded-lg flex items-center justify-center">
                    <ImageIcon size={18} className="text-gray-400" />
                  </div>
                )}
                <div className="min-w-0">
                  <p className="font-medium truncate">{ctxData.name}</p>
                  <div className="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
                    {ctxData.sku && <span>SKU: {ctxData.sku}</span>}
                    {ctxData.brand && <span>· {ctxData.brand}</span>}
                  </div>
                </div>
              </div>
            )}
            {conversation.context.context_type === 'order' && (
              <div className="flex items-center gap-3">
                {CtxIcon && <CtxIcon size={18} className="text-gray-500 shrink-0" />}
                <div>
                  <p className="font-medium">{t('messages.orderInfo')} #{ctxData.orderNumber}</p>
                  {ctxData.date && <p className="text-xs text-gray-500 mt-0.5">{ctxData.date}</p>}
                </div>
              </div>
            )}
            {conversation.context.context_type === 'ticket' && (
              <div className="flex items-center gap-3">
                {CtxIcon && <CtxIcon size={18} className="text-gray-500 shrink-0" />}
                <div>
                  <p className="font-medium truncate">{ctxData.title}</p>
                  {ctxData.date && <p className="text-xs text-gray-500 mt-0.5">{ctxData.date}</p>}
                </div>
              </div>
            )}
          </div>
        )}
        {/* Trendyol product info from metadata */}
        {isTrendyol && conversation.metadata && (conversation.metadata as Record<string, string>).product_name && (() => {
          const meta = conversation.metadata as Record<string, string>
          return (
            <div className="mt-3 p-3 bg-orange-50 rounded-lg text-sm">
              <div className="flex items-center gap-3">
                {meta.image_url ? (
                  <img src={meta.image_url} alt="" className="w-12 h-12 rounded-lg object-cover border border-orange-200" />
                ) : (
                  <div className="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                    <Package size={18} className="text-orange-400" />
                  </div>
                )}
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-sm truncate">{meta.product_name}</p>
                  <div className="flex items-center gap-2 text-xs text-orange-600 mt-0.5">
                    {meta.product_main_id && <span>Kod: {meta.product_main_id}</span>}
                    {meta.web_url && (
                      <a href={meta.web_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 hover:underline">
                        Trendyol <ArrowUpRight size={10} />
                      </a>
                    )}
                  </div>
                </div>
              </div>
            </div>
          )
        })()}
        {/* Hepsiburada product info from metadata */}
        {isHepsiburada && conversation.metadata && (conversation.metadata as Record<string, string>).product_name && (() => {
          const meta = conversation.metadata as Record<string, string>
          const imageUrl = meta.image_url ? meta.image_url.replace('{size}', '200') : null
          const productUrl = meta.product_sku ? `https://www.hepsiburada.com/-p-${meta.product_sku}` : null
          return (
            <div className="mt-3 p-3 bg-purple-50 rounded-lg text-sm">
              <div className="flex items-center gap-3">
                {imageUrl ? (
                  <img src={imageUrl} alt="" className="w-12 h-12 rounded-lg object-cover border border-purple-200" />
                ) : (
                  <div className="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                    <ShoppingBag size={18} className="text-purple-400" />
                  </div>
                )}
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-sm truncate">{meta.product_name}</p>
                  <div className="flex items-center gap-2 text-xs text-purple-600 mt-0.5">
                    {meta.product_sku && <span>SKU: {meta.product_sku}</span>}
                    {productUrl && (
                      <a href={productUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 hover:underline">
                        Hepsiburada <ArrowUpRight size={10} />
                      </a>
                    )}
                  </div>
                </div>
              </div>
            </div>
          )
        })()}
        {/* Ticimax ticket info from metadata */}
        {isTicimax && ticimaxMeta && (
          <div className="mt-3 p-3 bg-red-50 rounded-lg text-sm">
            <div className="flex items-center gap-2">
              <Headphones size={16} className="text-red-500 shrink-0" />
              <p className="font-medium text-sm truncate">{ticimaxMeta.konu || (i18n.language === 'tr' ? 'Destek Talebi' : 'Support Ticket')}</p>
            </div>
            <div className="flex items-center gap-2 text-xs text-red-600 mt-1.5 ml-6">
              {ticimaxEklemeTarihi && <span>{ticimaxEklemeTarihi}</span>}
              {ticimaxEklemeTarihi && ticimaxDurum && <span>·</span>}
              {ticimaxDurum && (
                <span className="inline-flex items-center px-1.5 py-0.5 rounded-full bg-red-100 text-red-700 text-[11px] font-medium">
                  {ticimaxDurum}
                </span>
              )}
            </div>
            <div className="mt-1.5 ml-6 flex items-center gap-3">
              {ticimaxMeta.ticimax_ticket_id && storeUrl && (
                <a
                  href={`${storeUrl}/Admin/DestekTalepCevap.aspx?ID=${ticimaxMeta.ticimax_ticket_id}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-0.5 text-xs text-red-600 hover:underline"
                >
                  {i18n.language === 'tr' ? 'Destek Talebi' : 'Support Ticket'} <ArrowUpRight size={10} />
                </a>
              )}
              {ticimaxMeta.uye_id && storeUrl && (
                <a
                  href={`${storeUrl}/Admin/UyeDetaylari.aspx?ID=${ticimaxMeta.uye_id}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-0.5 text-xs text-red-600 hover:underline"
                >
                  {i18n.language === 'tr' ? 'Ticimax Üye' : 'Ticimax Member'} <ArrowUpRight size={10} />
                </a>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Messages */}
      <div className="flex-1 overflow-y-auto p-3 md:p-4 space-y-3">
        {messages.map((msg) => (
          <MessageBubble
            key={msg.id}
            msg={msg}
            isAdmin={user?.is_super_admin || user?.role === 'admin'}
            onPenalty={(messageId, points) => {
              api.post(`/messages/${messageId}/penalty`, { points }).then((res) => {
                setMessages((prev) => prev.map((m) => m.id === messageId ? { ...m, ...res.data } : m))
              })
            }}
            onImageClick={setLightboxUrl}
            locale={locale}
            t={t}
          />
        ))}
        <div ref={messagesEndRef} />
      </div>

      {/* Input */}
      <div className="relative p-3 md:p-4 border-t border-gray-100 shrink-0">
        {showQuickReplies && (
          <div className="absolute left-3 right-3 md:left-4 md:right-4 bottom-full">
            <div className="relative">
              <QuickRepliesPanel onSelect={applyQuickReply} onClose={() => setShowQuickReplies(false)} />
            </div>
          </div>
        )}
        {/* Meta 24h window status banner */}
        {metaWindowInfo && (
          <div className={clsx(
            'flex items-center gap-2 mb-2 px-3 py-2 rounded-lg text-sm font-medium',
            metaWindowInfo.isOutside
              ? 'bg-amber-50 border border-amber-300 text-amber-800'
              : 'bg-green-50 border border-green-200 text-green-700'
          )}>
            <Shield size={15} className="shrink-0" />
            {metaWindowInfo.isOutside ? (
              <span>
                {i18n.language === 'tr'
                  ? <>24 saat penceresi doldu — mesaj <strong>HUMAN_AGENT</strong> etiketi ile gönderilecek</>
                  : <>24h window expired — message will be sent with <strong>HUMAN_AGENT</strong> tag</>
                }
                {metaWindowInfo.daysLeft > 0 && (
                  <span className="text-xs font-normal ml-1">
                    ({i18n.language === 'tr' ? 'kalan süre' : 'remaining'}: {Math.floor(metaWindowInfo.daysLeft)} {i18n.language === 'tr' ? 'gün' : 'd'} {Math.round((metaWindowInfo.daysLeft % 1) * 24)} {i18n.language === 'tr' ? 'saat' : 'h'})
                  </span>
                )}
              </span>
            ) : (
              <span>
                {i18n.language === 'tr'
                  ? '24 saat penceresi açık — standart mesaj olarak gönderilecek'
                  : '24h window open — will be sent as standard message'
                }
                <span className="text-xs font-normal ml-1">
                  ({i18n.language === 'tr' ? 'kalan' : 'remaining'}: {metaWindowInfo.remainingHours >= 1
                    ? `${Math.floor(metaWindowInfo.remainingHours)} ${i18n.language === 'tr' ? 'saat' : 'h'} ${Math.round((metaWindowInfo.remainingHours % 1) * 60)} ${i18n.language === 'tr' ? 'dk' : 'min'}`
                    : `${Math.round(metaWindowInfo.remainingHours * 60)} ${i18n.language === 'tr' ? 'dk' : 'min'}`
                  })
                </span>
              </span>
            )}
          </div>
        )}
        {/* SMS: tek yönlü kanal uyarısı */}
        {channelType === 'sms' && (
          <div className="flex items-center gap-2 mb-2 px-3 py-2 rounded-lg text-xs font-medium bg-teal-50 border border-teal-200 text-teal-700">
            <Smartphone size={13} className="shrink-0" />
            {i18n.language === 'tr'
              ? 'SMS tek yönlüdür — müşteri bu başlığa cevap yazamaz. Her mesaj yeni bir SMS olarak gönderilir.'
              : 'SMS is one-way — the customer cannot reply to a sender ID. Each message is sent as a new SMS.'}
          </div>
        )}
        {/* Geçersiz WhatsApp adresi (ne numara ne BSUID) — gönderim tamamen kapalı */}
        {waUnreachable && (
          <div className="flex items-start gap-2 mb-2 px-3 py-2 rounded-lg text-xs font-medium bg-rose-50 border border-rose-300 text-rose-800">
            <AlertTriangle size={14} className="shrink-0 mt-0.5" />
            <span className="flex-1">
              {i18n.language === 'tr'
                ? 'Bu müşterinin geçerli bir WhatsApp adresi yok — panelden cevap da şablon da iletilemez. Müşteriye e-posta/telefon ile ulaşın.'
                : 'This customer has no valid WhatsApp address — neither replies nor templates can be delivered from the panel.'}
            </span>
          </div>
        )}
        {/* Telefonunu gizleyen müşteri (Meta kullanıcı adı akışı) — gönderim kimlikle çalışır */}
        {waHiddenNumber && (
          <div className="flex items-start gap-2 mb-2 px-3 py-2 rounded-lg text-xs font-medium bg-sky-50 border border-sky-200 text-sky-800">
            <Shield size={14} className="shrink-0 mt-0.5" />
            <span className="flex-1">
              {i18n.language === 'tr'
                ? <>Bu müşteri WhatsApp'ta telefon numarasını gizlemiş{waUsername ? <> (kullanıcı adı: <b>@{waUsername}</b>)</> : null}. Cevaplar ve şablonlar Meta kimliğiyle iletilir; numarası panelde görünmez, gerekiyorsa sipariş kaydından bulun.</>
                : <>This customer hides their phone number on WhatsApp{waUsername ? <> (username: <b>@{waUsername}</b>)</> : null}. Replies and templates are delivered via the Meta user ID; the number is not shown in the panel.</>}
            </span>
          </div>
        )}
        {/* WhatsApp 24h window banner + şablonla başlat */}
        {!waUnreachable && waWindowInfo && waWindowInfo.isOutside && (
          <div className="mb-2 rounded-lg bg-amber-50 border border-amber-300">
            <div className="flex items-center gap-2 px-3 py-2 text-sm font-medium text-amber-800">
              <Shield size={15} className="shrink-0" />
              <span className="flex-1">
                {i18n.language === 'tr'
                  ? '24 saat penceresi kapalı — serbest mesaj müşteriye ulaşmaz. Sohbeti onaylı şablonla başlatın; müşteri cevap verince normal mesajlaşma açılır.'
                  : '24h window closed — free-form messages will not be delivered. Start with an approved template; once the customer replies, normal messaging opens.'}
              </span>
              <button
                onClick={openWaTemplatePanel}
                className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white bg-amber-600 hover:bg-amber-700 rounded-full transition-colors"
              >
                <Send size={13} />
                {i18n.language === 'tr' ? 'Şablonla başlat' : 'Send template'}
              </button>
            </div>
            {waTplOpen && (
              <div className="px-3 pb-3 space-y-2">
                {waQualityWarning && (
                  <p className="flex items-start gap-1.5 px-2.5 py-2 rounded-lg bg-amber-100/70 border border-amber-300 text-[11px] text-amber-900">
                    <AlertTriangle size={12} className="shrink-0 mt-0.5" />
                    {waQualityWarning}
                  </p>
                )}
                {waTemplates.length > 8 && (
                  <div className="relative">
                    <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                      value={waTplQuery}
                      onChange={(e) => setWaTplQuery(e.target.value)}
                      placeholder={i18n.language === 'tr' ? 'Şablon ara…' : 'Search templates…'}
                      className="w-full pl-8 pr-3 py-2 bg-white border border-gray-200 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-amber-500"
                    />
                  </div>
                )}
                <div className="max-h-64 overflow-y-auto space-y-2 pr-0.5">
                {visibleWaTemplates.length === 0 && (
                  <p className="py-3 text-center text-[11px] text-gray-400">
                    {i18n.language === 'tr' ? 'Eşleşen şablon yok.' : 'No matching template.'}
                  </p>
                )}
                {Object.entries(waTemplateGroups).map(([group, items]) => (
                  <div key={group} className="space-y-2">
                    <p className="pt-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400">{group}</p>
                {items.map((tpl) => (
                  <label
                    key={tpl.key}
                    className={clsx(
                      'block px-3 py-2 rounded-lg border cursor-pointer bg-white text-xs transition-colors',
                      waTemplate === tpl.key ? 'border-amber-500' : 'border-gray-200 hover:bg-gray-50',
                    )}
                  >
                    <span className="flex items-center gap-2">
                      <input
                        type="radio"
                        checked={waTemplate === tpl.key}
                        onChange={() => setWaTemplate(tpl.key)}
                        className="accent-amber-600"
                      />
                      <span className="font-semibold">{tpl.label}</span>
                      {tpl.category === 'MARKETING' && (
                        <span className="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px] font-semibold uppercase tracking-wide">
                          {i18n.language === 'tr' ? 'Pazarlama' : 'Marketing'}
                        </span>
                      )}
                    </span>
                    <span className="block mt-1 ml-6 text-gray-500">{tpl.preview}</span>
                    {!!tpl.buttons?.length && (
                      <span className="flex flex-wrap gap-1.5 mt-1.5 ml-6">
                        {tpl.buttons.map((b) => (
                          <span key={b} className="px-2 py-0.5 rounded-full bg-white border border-gray-200 text-[11px] font-medium text-amber-700">
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
                <div className="flex gap-2">
                  {selectedWaTemplate?.param_label && (
                    <input
                      value={waParam}
                      onChange={(e) => setWaParam(e.target.value)}
                      placeholder={selectedWaTemplate.param_label}
                      className="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-amber-500"
                    />
                  )}
                  <button
                    onClick={handleSendWaTemplate}
                    disabled={sending || (!!selectedWaTemplate?.param_label && !waParam.trim())}
                    className={clsx(
                      'inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-white bg-amber-600 hover:bg-amber-700 disabled:opacity-50 rounded-lg transition-colors',
                      !selectedWaTemplate?.param_label && 'flex-1',
                    )}
                  >
                    {sending ? <Loader2 size={13} className="animate-spin" /> : <Send size={13} />}
                    {i18n.language === 'tr' ? 'Gönder' : 'Send'}
                  </button>
                </div>
              </div>
            )}
          </div>
        )}
        {sendError && (
          <div className="flex items-center gap-2 mb-2 px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            <AlertTriangle size={14} className="shrink-0" />
            <span className="flex-1">{t('messages.deliveryFailed', { error: sendError })}</span>
            <button onClick={() => setSendError(null)} className="shrink-0 p-0.5 hover:bg-red-100 rounded">
              <X size={14} />
            </button>
          </div>
        )}
        {/* Hazır cevaplar + AI önerisi */}
        <div className="flex items-center gap-2 mb-2">
          <button
            onClick={() => setShowQuickReplies((v) => !v)}
            className={clsx(
              'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-full transition-colors shrink-0',
              showQuickReplies
                ? 'bg-indigo-600 text-white'
                : 'text-gray-600 bg-gray-100 hover:bg-gray-200',
            )}
          >
            <Zap size={14} />
            {t('quickReplies.button')}
          </button>
          {canUseAi && (
            <div className="flex-1 min-w-0">
            {aiOriginalContent && conversation?.ai_suggestion ? (
              <div className="flex items-center gap-2 px-3 py-1.5 bg-indigo-50 border border-indigo-200 rounded-lg text-xs text-indigo-700">
                <Sparkles size={14} className="shrink-0" />
                <span className="flex-1">
                  {t('messages.aiSuggestionReady', { confidence: Math.round((conversation.ai_suggestion_confidence ?? 0) * 100) })}
                </span>
                <button
                  onClick={() => { setNewMessage(''); setAiOriginalContent(null) }}
                  className="shrink-0 p-0.5 hover:bg-indigo-100 rounded"
                >
                  <X size={14} />
                </button>
              </div>
            ) : (
              <button
                onClick={handleAiGenerate}
                disabled={aiLoading}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-[var(--primary-strong)] bg-[var(--primary-soft)] hover:bg-indigo-100 rounded-full transition-colors disabled:opacity-50"
              >
                {aiLoading ? (
                  <Loader2 size={14} className="animate-spin" />
                ) : (
                  <Sparkles size={14} />
                )}
                {t('messages.generateAiReply')}
              </button>
            )}
            </div>
          )}
        </div>
        {showEmoji && (
          <div className="absolute right-3 md:right-4 bottom-full mb-2 z-30">
            <EmojiPicker onPick={insertEmoji} onClose={() => setShowEmoji(false)} />
          </div>
        )}
        {/* WhatsApp dosya eki önizlemesi */}
        {attachFile && (
          <div className="flex items-center gap-2.5 mb-2 px-3 py-2 bg-[var(--app-bg)] border border-gray-200 rounded-lg">
            {attachPreview ? (
              <img src={attachPreview} alt="" className="w-11 h-11 rounded-lg object-cover shrink-0" />
            ) : attachFile.type === 'video/mp4' ? (
              <Film size={20} className="text-gray-500 shrink-0" />
            ) : (
              <FileText size={20} className="text-gray-500 shrink-0" />
            )}
            <span className="flex-1 min-w-0 truncate text-xs text-gray-700">
              {attachFile.name}
              <span className="text-gray-400 ml-1">({(attachFile.size / 1024 / 1024).toFixed(1)} MB)</span>
            </span>
            <button onClick={() => pickAttachment(null)} className="shrink-0 p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-200 rounded">
              <X size={14} />
            </button>
          </div>
        )}
        {channelType === 'webchat' && (
          <div className="flex items-center gap-2 mb-2 px-3 py-1.5 rounded-lg bg-[var(--app-bg)] border border-gray-200 text-[11px] text-gray-600">
            <Smartphone size={12} className="shrink-0 text-gray-400" />
            <span className="flex-1 min-w-0">
              {customerPhone(conversation.customer)
                ? <>Mesai saatleri dışında cevabınız müşteriye <b>SMS</b> olarak da gider ({customerPhone(conversation.customer)}).</>
                : <>Bu müşterinin telefon numarası yok — cevap yalnız sohbet penceresinde görünür.</>}
            </span>
            {customerPhone(conversation.customer) && (
              <label className="flex items-center gap-1.5 shrink-0 cursor-pointer select-none">
                <input type="checkbox" checked={sendAsSms} onChange={(e) => setSendAsSms(e.target.checked)} className="rounded border-gray-300" />
                <span>SMS olarak gönder</span>
              </label>
            )}
          </div>
        )}
        {(isHepsiburada || isTrendyol) && newMessage.trim() && (() => {
          const warns = marketplaceReplyWarnings(newMessage)
          if (!warns.length) return null
          return (
            <div className="flex items-start gap-2 mb-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-800">
              <AlertTriangle size={14} className="shrink-0 mt-0.5 text-amber-600" />
              <span className="flex-1 min-w-0">
                <b>{isHepsiburada ? 'Hepsiburada' : 'Trendyol'} bu cevabı reddedebilir:</b> {warns.join(', ')}.
                {' '}Pazaryeri cevaplarında link, telefon, site adresi yazılmaz.
              </span>
            </div>
          )
        })()}
        <div className="flex gap-2">
          <textarea
            ref={textareaRef}
            value={newMessage}
            onChange={(e) => {
              setNewMessage(e.target.value)
              e.target.style.height = 'auto'
              e.target.style.height = Math.min(e.target.scrollHeight, 200) + 'px'
            }}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault()
                handleSend()
              }
            }}
            onPaste={(e) => {
              // Panodan görsel yapıştırma → WhatsApp eki olarak seç
              if (channelType !== 'whatsapp' || waUnreachable || waWindowInfo?.isOutside) return
              const img = Array.from(e.clipboardData.files).find((f) => f.type.startsWith('image/'))
              if (img) {
                e.preventDefault()
                pickAttachment(img)
              }
            }}
            disabled={waUnreachable}
            placeholder={waUnreachable
              ? (i18n.language === 'tr' ? 'Numara olmadığı için bu konuşmaya cevap gönderilemez' : 'No phone number — replies cannot be delivered')
              : attachFile
                ? (i18n.language === 'tr' ? 'Başlık ekleyin (isteğe bağlı)…' : 'Add a caption (optional)…')
                : t('messages.typeMessage')}
            rows={3}
            style={{ height: newMessage ? undefined : 'auto' }}
            className="flex-1 px-4 py-2.5 bg-[var(--app-bg)] border border-transparent rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white transition-colors resize-none overflow-y-auto disabled:opacity-60 disabled:cursor-not-allowed"
          />
          {channelType === 'whatsapp' && (
            <>
              <input
                ref={fileInputRef}
                type="file"
                accept="image/jpeg,image/png,video/mp4,application/pdf"
                className="hidden"
                onChange={(e) => pickAttachment(e.target.files?.[0] ?? null)}
              />
              <button
                onClick={() => fileInputRef.current?.click()}
                disabled={waUnreachable || !!waWindowInfo?.isOutside}
                title={waUnreachable
                  ? (i18n.language === 'tr' ? 'Müşterinin telefon numarası yok — gönderim yapılamaz' : 'Customer has no phone number — sending is not possible')
                  : waWindowInfo?.isOutside
                  ? (i18n.language === 'tr' ? '24 saat penceresi kapalı — önce şablonla sohbeti başlatın' : '24h window closed — start with a template first')
                  : (i18n.language === 'tr' ? 'Dosya ekle (görsel, video, PDF)' : 'Attach file (image, video, PDF)')}
                className={clsx(
                  'self-end p-2.5 rounded-xl transition-colors disabled:opacity-40',
                  attachFile
                    ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]'
                    : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100',
                )}
              >
                <Paperclip size={18} />
              </button>
            </>
          )}
          <button
            onClick={() => setShowEmoji((v) => !v)}
            onMouseDown={(e) => e.stopPropagation()}
            title={t('emoji.title')}
            className={clsx(
              'self-end p-2.5 rounded-xl transition-colors',
              showEmoji
                ? 'bg-[var(--primary-soft)] text-[var(--primary-strong)]'
                : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100',
            )}
          >
            <Smile size={18} />
          </button>
          <button
            onClick={handleSend}
            disabled={waUnreachable || (!newMessage.trim() && !attachFile) || sending}
            className="self-end px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-xl transition-colors"
          >
            {sending && attachFile ? <Loader2 size={18} className="animate-spin" /> : <Send size={18} />}
          </button>
        </div>
      </div>

      {/* Lightbox */}
      {lightboxUrl && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/80"
          onClick={() => setLightboxUrl(null)}
        >
          <button
            onClick={() => setLightboxUrl(null)}
            className="absolute top-4 right-4 p-2 text-white/80 hover:text-white"
          >
            <X size={24} />
          </button>
          <img
            src={lightboxUrl}
            alt=""
            className="max-w-[90vw] max-h-[90vh] object-contain rounded-lg"
            onClick={(e) => e.stopPropagation()}
          />
        </div>
      )}
    </div>
  )
}
