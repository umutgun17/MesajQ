import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Search, Plus, Pencil, Trash2, X, Check, Loader2 } from 'lucide-react'
import clsx from 'clsx'
import api from '@/lib/api'

export interface QuickReply {
  id: number
  category: string
  title: string
  content: string
  is_active: boolean
  is_default: boolean
  usage_count: number
}

interface Props {
  onSelect: (content: string) => void
  onClose: () => void
}

type EditState = { id: number | null; title: string; category: string; content: string }

const emptyEdit: EditState = { id: null, title: '', category: '', content: '' }

export default function QuickRepliesPanel({ onSelect, onClose }: Props) {
  const { t } = useTranslation()
  const [items, setItems] = useState<QuickReply[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [activeCategory, setActiveCategory] = useState<string | null>(null)
  const [edit, setEdit] = useState<EditState | null>(null)
  const [saving, setSaving] = useState(false)
  const panelRef = useRef<HTMLDivElement>(null)

  const load = async () => {
    try {
      const res = await api.get('/quick-replies')
      setItems(res.data.data ?? [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
  }, [])

  // Panel dışına tıklayınca kapat (düzenleme açıkken kapatma — veri kaybı olmasın)
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (edit) return
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) onClose()
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [onClose, edit])

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        if (edit) setEdit(null)
        else onClose()
      }
    }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [onClose, edit])

  const categories = useMemo(
    () => Array.from(new Set(items.map((i) => i.category))).sort((a, b) => a.localeCompare(b, 'tr')),
    [items],
  )

  const filtered = useMemo(() => {
    const q = search.trim().toLocaleLowerCase('tr')
    return items.filter((i) => {
      if (activeCategory && i.category !== activeCategory) return false
      if (!q) return true
      return (
        i.title.toLocaleLowerCase('tr').includes(q) ||
        i.content.toLocaleLowerCase('tr').includes(q)
      )
    })
  }, [items, search, activeCategory])

  const handlePick = async (item: QuickReply) => {
    onSelect(item.content)
    onClose()
    try {
      await api.post(`/quick-replies/${item.id}/used`)
    } catch {
      // sayaç kritik değil
    }
  }

  const handleSave = async () => {
    if (!edit || !edit.title.trim() || !edit.content.trim() || saving) return
    setSaving(true)
    try {
      const payload = {
        title: edit.title.trim(),
        content: edit.content.trim(),
        category: edit.category.trim() || 'Genel',
      }
      if (edit.id) {
        const res = await api.patch(`/quick-replies/${edit.id}`, payload)
        setItems((prev) => prev.map((i) => (i.id === edit.id ? res.data : i)))
      } else {
        const res = await api.post('/quick-replies', payload)
        setItems((prev) => [...prev, res.data])
      }
      setEdit(null)
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (item: QuickReply) => {
    if (!window.confirm(t('quickReplies.confirmDelete', { title: item.title }))) return
    await api.delete(`/quick-replies/${item.id}`)
    setItems((prev) => prev.filter((i) => i.id !== item.id))
  }

  return (
    <div
      ref={panelRef}
      className="absolute bottom-full left-0 right-0 mb-2 z-30 bg-white border border-gray-200 rounded-xl shadow-xl flex flex-col max-h-[min(26rem,55vh)] overflow-hidden"
    >
      {/* Başlık */}
      <div className="flex items-center gap-2 px-3 py-2 border-b border-gray-100">
        <span className="text-sm font-semibold text-gray-800">{t('quickReplies.title')}</span>
        <span className="text-xs text-gray-400">({items.length})</span>
        <div className="flex-1" />
        {!edit && (
          <button
            onClick={() => setEdit({ ...emptyEdit, category: activeCategory ?? '' })}
            className="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold text-[var(--primary-strong)] bg-[var(--primary-soft)] hover:bg-indigo-100 rounded-lg transition-colors"
          >
            <Plus size={13} />
            {t('quickReplies.new')}
          </button>
        )}
        <button onClick={onClose} className="p-1 hover:bg-gray-100 rounded-lg text-gray-500">
          <X size={15} />
        </button>
      </div>

      {edit ? (
        /* ── Ekleme / düzenleme formu ── */
        <div className="p-3 space-y-2 overflow-y-auto">
          <input
            autoFocus
            value={edit.title}
            onChange={(e) => setEdit({ ...edit, title: e.target.value })}
            placeholder={t('quickReplies.titlePlaceholder')}
            maxLength={120}
            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)]"
          />
          <input
            value={edit.category}
            onChange={(e) => setEdit({ ...edit, category: e.target.value })}
            placeholder={t('quickReplies.categoryPlaceholder')}
            maxLength={60}
            list="quick-reply-categories"
            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)]"
          />
          <datalist id="quick-reply-categories">
            {categories.map((c) => (
              <option key={c} value={c} />
            ))}
          </datalist>
          <textarea
            value={edit.content}
            onChange={(e) => setEdit({ ...edit, content: e.target.value })}
            placeholder={t('quickReplies.contentPlaceholder')}
            rows={5}
            maxLength={4000}
            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm resize-none focus:outline-none focus:ring-2 focus:ring-[var(--primary)]"
          />
          <div className="flex justify-end gap-2">
            <button
              onClick={() => setEdit(null)}
              className="px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 rounded-lg"
            >
              {t('common.cancel')}
            </button>
            <button
              onClick={handleSave}
              disabled={!edit.title.trim() || !edit.content.trim() || saving}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded-lg"
            >
              {saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
              {t('common.save')}
            </button>
          </div>
        </div>
      ) : (
        <>
          {/* Arama */}
          <div className="px-3 pt-2">
            <div className="relative">
              <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
              <input
                autoFocus
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t('quickReplies.search')}
                className="w-full pl-8 pr-3 py-1.5 bg-[var(--app-bg)] rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)]"
              />
            </div>
          </div>

          {/* Kategoriler */}
          {categories.length > 0 && (
            <div className="flex gap-1 px-3 py-2 overflow-x-auto shrink-0">
              <button
                onClick={() => setActiveCategory(null)}
                className={clsx(
                  'px-2 py-0.5 text-xs rounded-full whitespace-nowrap transition-colors',
                  !activeCategory ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200',
                )}
              >
                {t('quickReplies.allCategories')}
              </button>
              {categories.map((c) => (
                <button
                  key={c}
                  onClick={() => setActiveCategory(c === activeCategory ? null : c)}
                  className={clsx(
                    'px-2 py-0.5 text-xs rounded-full whitespace-nowrap transition-colors',
                    c === activeCategory ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200',
                  )}
                >
                  {c}
                </button>
              ))}
            </div>
          )}

          {/* Liste */}
          <div className="flex-1 overflow-y-auto px-2 pb-2">
            {loading ? (
              <div className="flex justify-center py-6 text-gray-400">
                <Loader2 size={18} className="animate-spin" />
              </div>
            ) : filtered.length === 0 ? (
              <div className="py-6 text-center text-sm text-gray-400">{t('quickReplies.empty')}</div>
            ) : (
              filtered.map((item) => (
                <div
                  key={item.id}
                  className="group flex items-start gap-2 px-2 py-2 rounded-lg hover:bg-[var(--app-bg)] cursor-pointer"
                  onClick={() => handlePick(item)}
                >
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1.5">
                      <span className="text-sm font-medium text-gray-800 truncate">{item.title}</span>
                      {!item.is_active && (
                        <span className="text-[10px] px-1 rounded bg-gray-200 text-gray-500">
                          {t('quickReplies.inactive')}
                        </span>
                      )}
                    </div>
                    <p className="text-xs text-gray-500 line-clamp-2">{item.content}</p>
                  </div>
                  <div className="flex gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                    <button
                      onClick={(e) => {
                        e.stopPropagation()
                        setEdit({
                          id: item.id,
                          title: item.title,
                          category: item.category,
                          content: item.content,
                        })
                      }}
                      title={t('common.edit')}
                      className="p-1 hover:bg-gray-200 rounded text-gray-500"
                    >
                      <Pencil size={13} />
                    </button>
                    <button
                      onClick={(e) => {
                        e.stopPropagation()
                        handleDelete(item)
                      }}
                      title={t('common.delete')}
                      className="p-1 hover:bg-red-100 rounded text-red-500"
                    >
                      <Trash2 size={13} />
                    </button>
                  </div>
                </div>
              ))
            )}
          </div>
        </>
      )}
    </div>
  )
}
