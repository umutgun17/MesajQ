import { useEffect, useState, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import type { Conversation, Comment } from '@/types'
import {
  Send, Bot, Clock, ExternalLink, Sparkles, Loader2, AtSign, Heart, AlertTriangle,
  Mail, MessageCircle, Instagram, Facebook, Headphones, Smartphone, Globe, Reply, X, Trash2,
  Smile,
} from 'lucide-react'
import clsx from 'clsx'
import { getChannelBadgeClasses, getChannelLabel } from '@/lib/channelUtils'
import EmojiPicker from '@/components/EmojiPicker'

interface Props {
  conversationId: number
  onUpdate?: () => void
}

const channelIcons: Record<string, typeof Mail> = {
  email: Mail,
  whatsapp: MessageCircle,
  instagram: Instagram,
  instagram_comment: Instagram,
  facebook: Facebook,
  facebook_comment: Facebook,
  live_chat: Headphones,
  sms: Smartphone,
}

export default function CommentDetailView({ conversationId, onUpdate }: Props) {
  const { t, i18n } = useTranslation()
  const { canUseAi } = useAuth()
  const [conversation, setConversation] = useState<Conversation | null>(null)
  const [comments, setComments] = useState<Comment[]>([])
  const [loading, setLoading] = useState(true)
  const [replyingTo, setReplyingTo] = useState<number | null>(null)
  const [replyText, setReplyText] = useState('')
  const [sending, setSending] = useState(false)
  const [aiLoading, setAiLoading] = useState(false)
  const [aiOriginalContent, setAiOriginalContent] = useState<string | null>(null)
  const [sendError, setSendError] = useState<string | null>(null)
  const [deleting, setDeleting] = useState<number | null>(null)
  const [showEmoji, setShowEmoji] = useState(false)
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const scrollRef = useRef<HTMLDivElement>(null)
  const commentRefs = useRef<Map<number, HTMLDivElement>>(new Map())

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  useEffect(() => {
    setLoading(true)
    setReplyText('')
    setReplyingTo(null)
    setAiOriginalContent(null)
    setSendError(null)
    setShowEmoji(false)
    api.get(`/conversations/${conversationId}`).then((res) => {
      setConversation(res.data)
      const loadedComments: Comment[] = res.data.comments || []
      setComments(loadedComments)
      // Auto-select first pending comment
      const firstPending = loadedComments.find((c) => c.status === 'pending')
      if (firstPending) setReplyingTo(firstPending.id)
      setLoading(false)
      if (!res.data.is_read) {
        api.post(`/conversations/${conversationId}/mark-read`)
      }
    })
  }, [conversationId])

  const selectComment = (commentId: number) => {
    if (replyingTo === commentId) return
    setReplyingTo(commentId)
    setReplyText('')
    setAiOriginalContent(null)
    setSendError(null)
    setTimeout(() => textareaRef.current?.focus(), 50)
  }

  const handleReply = async (commentId: number) => {
    if (!replyText.trim() || sending) return
    setSending(true)
    setSendError(null)
    try {
      const res = await api.post(`/comments/${commentId}/reply`, {
        content: replyText.trim(),
        is_ai_generated: !!aiOriginalContent,
        ai_original_content: aiOriginalContent,
      })
      setComments((prev) =>
        prev.map((c) =>
          c.id === commentId
            ? { ...c, replies: [...(c.replies || []), res.data], status: (res.data.comment_status || 'replied') as Comment['status'] }
            : c
        )
      )
      setReplyText('')
      setAiOriginalContent(null)
      if (res.data.send_error) {
        setSendError(res.data.send_error)
        onUpdate?.()
        return // hata bandı görünür kalsın; sonraki yoruma otomatik geçme
      }
      // Auto-advance to next pending comment
      const nextPending = comments.find((c) => c.id !== commentId && c.status === 'pending')
      if (nextPending) {
        setReplyingTo(nextPending.id)
        setTimeout(() => {
          commentRefs.current.get(nextPending.id)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
          textareaRef.current?.focus()
        }, 100)
      } else {
        setReplyingTo(null)
      }
      onUpdate?.()
    } finally {
      setSending(false)
    }
  }

  // Emoji imleç konumuna eklenir; panel açık kalır (art arda ekleme için)
  const insertEmoji = (emoji: string) => {
    const el = textareaRef.current
    setReplyText((prev) => {
      const start = el?.selectionStart ?? prev.length
      const end = el?.selectionEnd ?? prev.length
      requestAnimationFrame(() => {
        if (el) {
          el.focus()
          const pos = start + emoji.length
          el.setSelectionRange(pos, pos)
        }
      })
      return prev.slice(0, start) + emoji + prev.slice(end)
    })
  }

  const handleDelete = async (commentId: number) => {
    if (!confirm(t('comments.deleteConfirm'))) return
    setDeleting(commentId)
    try {
      await api.delete(`/comments/${commentId}`)
      const updated = comments.filter((c) => c.id !== commentId)
      setComments(updated)
      if (replyingTo === commentId) {
        const nextPending = updated.find((c) => c.status === 'pending')
        setReplyingTo(nextPending?.id ?? null)
        setReplyText('')
        setAiOriginalContent(null)
      }
      onUpdate?.()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
        || t('common.error')
      setSendError(msg)
    } finally {
      setDeleting(null)
    }
  }

  const handleAiGenerate = async (commentId: number) => {
    if (aiLoading) return
    setAiLoading(true)
    try {
      const res = await api.post('/ai/generate', { comment_id: commentId })
      const text = res.data.response || ''
      setReplyText(text)
      setAiOriginalContent(text)
      textareaRef.current?.focus()
    } catch {
      // silently fail
    } finally {
      setAiLoading(false)
    }
  }

  if (loading || !conversation) {
    return (
      <div className="flex justify-center items-center h-full">
        <div className="w-6 h-6 border-3 border-indigo-600 border-t-transparent rounded-full animate-spin" />
      </div>
    )
  }

  const channelType = conversation.channel?.type ?? ''
  const ChannelIcon = channelIcons[channelType] ?? Globe
  const postMeta = (conversation.metadata as Record<string, unknown>)?.post as {
    permalink?: string
    caption?: string
    thumbnail_url?: string
    media_type?: string
    like_count?: number
    comments_count?: number
  } | undefined
  const isInstagram = channelType.includes('instagram')
  const activeComment = comments.find((c) => c.id === replyingTo)
  const pendingCount = comments.filter((c) => c.status === 'pending').length

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="p-3 md:p-4 border-b border-gray-100 shrink-0">
        <div className="flex items-center gap-2 md:gap-3">
          <div className="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-br from-pink-400 to-pink-600 rounded-full flex items-center justify-center text-xs md:text-sm font-semibold text-white shadow-sm shrink-0">
            {conversation.customer?.name?.charAt(0)?.toUpperCase() ?? '?'}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2">
              <AtSign size={14} className="text-gray-400" />
              <h2 className="font-semibold text-sm">{conversation.customer?.name}</h2>
            </div>
            <div className="flex items-center gap-2 mt-0.5">
              <span className={clsx('inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium', getChannelBadgeClasses(channelType))}>
                <ChannelIcon size={12} />
                {getChannelLabel(channelType)}
              </span>
              <span className="text-xs text-gray-400">
                {comments.length} {t('comments.title').toLowerCase()}
              </span>
              {pendingCount > 0 && (
                <span className="text-xs font-medium text-yellow-600 bg-yellow-50 px-1.5 py-0.5 rounded">
                  {pendingCount} {t('comments.pendingComment').toLowerCase()}
                </span>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Post Preview Card */}
      {postMeta && (
        <a
          href={postMeta.permalink || '#'}
          target="_blank"
          rel="noopener noreferrer"
          className={clsx(
            'mx-4 mt-3 mb-1 border rounded-xl overflow-hidden shadow-sm block hover:shadow-md transition-all cursor-pointer',
            isInstagram
              ? 'border-pink-200 hover:border-pink-300 bg-gradient-to-r from-pink-50/50 to-white'
              : 'border-blue-200 hover:border-blue-300 bg-gradient-to-r from-blue-50/50 to-white'
          )}
        >
          <div className="flex gap-3 p-3">
            {postMeta.thumbnail_url ? (
              <img
                src={postMeta.thumbnail_url}
                alt=""
                className="w-20 h-20 rounded-lg object-cover shrink-0 bg-gray-100"
              />
            ) : (
              <div className={clsx(
                'w-20 h-20 rounded-lg shrink-0 flex flex-col items-center justify-center gap-1',
                isInstagram ? 'bg-gradient-to-br from-pink-100 to-purple-100' : 'bg-gradient-to-br from-blue-50 to-blue-100'
              )}>
                {isInstagram ? <Instagram size={28} className="text-pink-400" /> : <Facebook size={28} className="text-blue-500" />}
                <span className={clsx('text-[10px] font-medium', isInstagram ? 'text-pink-400' : 'text-blue-400')}>{isInstagram ? t('messages.instagramPost') : t('messages.sharedPost')}</span>
              </div>
            )}
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-1.5 text-xs font-medium mb-1">
                {isInstagram ? (
                  <>
                    <Instagram size={12} className="text-pink-500" />
                    <span className="text-pink-600">{t('messages.instagramPost')}</span>
                  </>
                ) : (
                  <>
                    <Facebook size={12} className="text-blue-500" />
                    <span className="text-blue-600">Facebook Post</span>
                  </>
                )}
                {postMeta.media_type && (
                  <span className="text-gray-400 font-normal">
                    · {postMeta.media_type === 'VIDEO' ? 'Video' : postMeta.media_type === 'CAROUSEL_ALBUM' ? 'Album' : postMeta.media_type === 'REELS' ? 'Reels' : 'Photo'}
                  </span>
                )}
              </div>
              {postMeta.caption ? (
                <p className="text-sm text-gray-700 line-clamp-2 leading-snug">
                  {postMeta.caption}
                </p>
              ) : (
                <p className="text-xs text-gray-400">
                  {i18n.language === 'tr' ? 'Bu gönderi bir reklam veya kısıtlı içerik olabilir' : 'This post may be an ad or restricted content'}
                </p>
              )}
              <div className="flex items-center justify-between mt-2">
                <div className="flex items-center gap-3 text-xs text-gray-400">
                  {(postMeta.like_count ?? 0) > 0 && (
                    <span className="inline-flex items-center gap-1">
                      <Heart size={12} /> {postMeta.like_count}
                    </span>
                  )}
                  {(postMeta.comments_count ?? 0) > 0 && (
                    <span className="inline-flex items-center gap-1">
                      <MessageCircle size={12} /> {postMeta.comments_count}
                    </span>
                  )}
                </div>
                <span className={clsx(
                  'inline-flex items-center gap-1 text-xs font-medium px-2.5 py-1 rounded-lg',
                  isInstagram ? 'text-pink-600 bg-pink-50 hover:bg-pink-100' : 'text-blue-600 bg-blue-50 hover:bg-blue-100'
                )}>
                  {t('comments.viewPost')} <ExternalLink size={12} />
                </span>
              </div>
            </div>
          </div>
        </a>
      )}

      {/* Comments Thread */}
      <div className="flex-1 overflow-y-auto p-3 md:p-4 space-y-3" ref={scrollRef}>
        {comments.map((comment, idx) => {
          const isActive = replyingTo === comment.id
          const isPending = comment.status === 'pending'

          return (
            <div
              key={comment.id}
              ref={(el) => { if (el) commentRefs.current.set(comment.id, el) }}
              className={clsx(
                'rounded-xl border transition-all',
                isActive
                  ? 'border-indigo-300 bg-indigo-50/40 shadow-sm'
                  : isPending
                    ? 'border-yellow-200 bg-yellow-50/30 hover:border-indigo-200 cursor-pointer'
                    : 'border-gray-100 bg-white hover:border-gray-200 cursor-pointer',
              )}
              onClick={() => selectComment(comment.id)}
            >
              {/* Comment number + status bar */}
              <div className="flex items-center justify-between px-3.5 pt-3 pb-1">
                <span className="text-xs font-medium text-gray-400">
                  #{idx + 1}
                </span>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-gray-400">
                    {new Date(comment.created_at).toLocaleDateString(locale, { day: '2-digit', month: 'short' })}{' '}
                    {new Date(comment.created_at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })}
                  </span>
                  <span className={clsx(
                    'px-1.5 py-0.5 rounded text-xs font-medium',
                    comment.status === 'pending' && 'bg-yellow-100 text-yellow-700',
                    comment.status === 'replied' && 'bg-green-100 text-green-700',
                    comment.status === 'hidden' && 'bg-gray-200 text-gray-600',
                  )}>
                    {comment.status === 'pending' && t('comments.pendingComment')}
                    {comment.status === 'replied' && t('comments.repliedComment')}
                    {comment.status === 'hidden' && (i18n.language === 'tr' ? 'Gizli' : 'Hidden')}
                  </span>
                  <button
                    onClick={(e) => { e.stopPropagation(); handleDelete(comment.id) }}
                    disabled={deleting === comment.id}
                    className="p-1 text-gray-300 hover:text-red-500 transition-colors disabled:opacity-50"
                    title={t('comments.deleteReply')}
                  >
                    {deleting === comment.id ? <Loader2 size={14} className="animate-spin" /> : <Trash2 size={14} />}
                  </button>
                </div>
              </div>

              {/* Comment content */}
              <div className="px-3.5 pb-2">
                <p className="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{comment.content}</p>
              </div>

              {/* Replies */}
              {comment.replies && comment.replies.length > 0 && (
                <div className="mx-3.5 mb-3 space-y-2">
                  {comment.replies.map((reply) => (
                    <div key={reply.id} className={`flex items-start gap-2 text-white rounded-xl px-3.5 py-2.5 ${reply.user_id && !reply.platform_reply_id ? 'bg-red-600' : 'bg-indigo-600'}`}>
                      <Reply size={14} className="shrink-0 mt-0.5 text-indigo-300 scale-x-[-1]" />
                      <div className="flex-1 min-w-0">
                        {reply.user_id && !reply.platform_reply_id && (
                          <div className="flex items-center gap-1 text-xs font-semibold text-red-100 mb-0.5">
                            <AlertTriangle size={11} /> {t('comments.notDelivered')}
                          </div>
                        )}
                        {reply.is_ai_generated && (
                          <div className="flex items-center gap-1 text-xs text-indigo-200 mb-0.5">
                            <Bot size={11} /> {t('messages.aiPowered')}
                          </div>
                        )}
                        <p className="text-sm whitespace-pre-wrap">{reply.content}</p>
                        <div className="flex items-center gap-1 mt-1 text-xs text-indigo-200">
                          <Clock size={10} />
                          {new Date(reply.created_at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })}
                          <span className="ml-1">· {reply.user?.name || 'Business Suite'}</span>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}

              {/* Active indicator */}
              {isActive && (
                <div className="px-3.5 pb-2.5">
                  <div className="flex items-center gap-1.5 text-xs text-indigo-600 font-medium">
                    <Reply size={12} className="scale-x-[-1]" />
                    {i18n.language === 'tr' ? 'Bu yoruma yanıt yazılıyor...' : 'Replying to this comment...'}
                  </div>
                </div>
              )}
            </div>
          )
        })}
      </div>

      {/* Send error warning */}
      {sendError && (
        <div className="mx-4 mb-2 flex items-start gap-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800">
          <AlertTriangle size={14} className="shrink-0 mt-0.5" />
          <span className="flex-1">{sendError}</span>
          <button onClick={() => setSendError(null)} className="shrink-0 hover:text-amber-900">
            <X size={14} />
          </button>
        </div>
      )}

      {/* Reply Input — Fixed Bottom */}
      {activeComment ? (
        <div className="relative border-t border-gray-100 bg-gray-50/50 p-3 md:p-4 shrink-0">
          {showEmoji && (
            <div className="absolute right-3 md:right-4 bottom-full mb-2 z-30">
              <EmojiPicker onPick={insertEmoji} onClose={() => setShowEmoji(false)} />
            </div>
          )}
          {/* Replying-to indicator */}
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2 min-w-0">
              <Reply size={14} className="text-indigo-500 shrink-0 scale-x-[-1]" />
              <span className="text-xs text-gray-500 truncate">
                <span className="font-medium text-gray-700">#{comments.findIndex((c) => c.id === activeComment.id) + 1}</span>
                {' — '}
                {activeComment.content.length > 60
                  ? activeComment.content.slice(0, 60) + '...'
                  : activeComment.content}
              </span>
            </div>
            <button
              onClick={(e) => { e.stopPropagation(); setReplyingTo(null); setReplyText(''); setAiOriginalContent(null) }}
              className="text-gray-400 hover:text-gray-600 shrink-0 ml-2"
            >
              <X size={16} />
            </button>
          </div>

          {/* AI button */}
          {canUseAi && (
            <div className="mb-2">
              <button
                onClick={() => handleAiGenerate(activeComment.id)}
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
            </div>
          )}

          {/* Textarea + Send */}
          <div className="flex gap-2">
            <textarea
              ref={textareaRef}
              value={replyText}
              onChange={(e) => setReplyText(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                  e.preventDefault()
                  handleReply(activeComment.id)
                }
              }}
              placeholder={t('comments.replyPlaceholder')}
              rows={2}
              className="flex-1 px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
            />
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
              onClick={() => handleReply(activeComment.id)}
              disabled={!replyText.trim() || sending}
              className="self-end px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-xl transition-colors"
            >
              {sending ? <Loader2 size={18} className="animate-spin" /> : <Send size={18} />}
            </button>
          </div>
        </div>
      ) : (
        <div className="border-t border-gray-100 p-3 text-center text-xs text-gray-400 shrink-0">
          {i18n.language === 'tr' ? 'Yanıtlamak için bir yoruma tıklayın' : 'Click a comment to reply'}
        </div>
      )}
    </div>
  )
}
