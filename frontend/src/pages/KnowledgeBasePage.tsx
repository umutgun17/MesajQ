import { useEffect, useState, useCallback, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import type { PaginatedResponse } from '@/types'
import { BookOpen, Plus, Pencil, Trash2, Search, X } from 'lucide-react'

interface KBEntry {
  id: number
  category: string
  title: string
  content: string
  tags: string[] | null
  is_active: boolean
  created_by: number
  creator?: { name: string }
  created_at: string
}

export default function KnowledgeBasePage() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'
  const [entries, setEntries] = useState<KBEntry[]>([])
  const [categories, setCategories] = useState<string[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [search, setSearch] = useState('')
  const [filterCategory, setFilterCategory] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<KBEntry | null>(null)
  const [form, setForm] = useState({ category: '', title: '', content: '', tags: '' })
  const [saving, setSaving] = useState(false)

  const sentinelRef = useRef<HTMLDivElement>(null)

  const fetchData = useCallback(() => {
    setLoading(true)
    const params: Record<string, string> = { per_page: '30' }
    if (search) params.search = search
    if (filterCategory) params.category = filterCategory

    Promise.all([
      api.get<PaginatedResponse<KBEntry>>('/knowledge-base', { params }),
      api.get<string[]>('/knowledge-base/categories'),
    ]).then(([entriesRes, catsRes]) => {
      setEntries(entriesRes.data.data)
      setPage(entriesRes.data.current_page)
      setLastPage(entriesRes.data.last_page)
      setTotal(entriesRes.data.total)
      setCategories(catsRes.data)
      setLoading(false)
    })
  }, [search, filterCategory])

  useEffect(() => { fetchData() }, [fetchData])

  // Fetch more (infinite scroll)
  const fetchMore = useCallback(() => {
    if (loadingMore || page >= lastPage) return
    setLoadingMore(true)
    const nextPage = page + 1
    const params: Record<string, string> = { per_page: '30', page: String(nextPage) }
    if (search) params.search = search
    if (filterCategory) params.category = filterCategory

    api.get<PaginatedResponse<KBEntry>>('/knowledge-base', { params }).then((res) => {
      setEntries((prev) => [...prev, ...res.data.data])
      setPage(res.data.current_page)
      setLastPage(res.data.last_page)
      setLoadingMore(false)
    })
  }, [page, lastPage, loadingMore, search, filterCategory])

  // IntersectionObserver
  useEffect(() => {
    if (loading) return
    const el = sentinelRef.current
    if (!el) return
    const obs = new IntersectionObserver(
      ([entry]) => { if (entry.isIntersecting) fetchMore() },
      { rootMargin: '200px' }
    )
    obs.observe(el)
    return () => obs.disconnect()
  }, [loading, fetchMore])

  const openCreate = () => {
    setEditing(null)
    setForm({ category: '', title: '', content: '', tags: '' })
    setShowForm(true)
  }

  const openEdit = (entry: KBEntry) => {
    setEditing(entry)
    setForm({
      category: entry.category,
      title: entry.title,
      content: entry.content,
      tags: entry.tags?.join(', ') || '',
    })
    setShowForm(true)
  }

  const handleSave = async () => {
    setSaving(true)
    const payload = {
      ...form,
      tags: form.tags ? form.tags.split(',').map((t) => t.trim()).filter(Boolean) : [],
    }

    try {
      if (editing) {
        await api.put(`/knowledge-base/${editing.id}`, payload)
      } else {
        await api.post('/knowledge-base', payload)
      }
      setShowForm(false)
      fetchData()
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (id: number) => {
    if (!confirm(t('knowledgeBaseCommon.deleteConfirm'))) return
    await api.delete(`/knowledge-base/${id}`)
    fetchData()
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-extrabold text-gray-900">{t('knowledgeBaseCommon.title')}</h1>
          <p className="text-sm text-gray-500 mt-1">{total.toLocaleString(locale)} {t('knowledgeBaseCommon.records')}</p>
        </div>
        <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
          <Plus size={16} /> {t('knowledgeBaseCommon.newRecord')}
        </button>
      </div>

      {/* Filters */}
      <div className="flex gap-3">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('knowledgeBaseCommon.searchPlaceholder')}
            className="w-full pl-9 pr-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
        <select
          value={filterCategory}
          onChange={(e) => setFilterCategory(e.target.value)}
          className="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm"
        >
          <option value="">{t('knowledgeBaseCommon.allCategories')}</option>
          {categories.map((cat) => (
            <option key={cat} value={cat}>{cat}</option>
          ))}
        </select>
      </div>

      {/* List */}
      {loading ? (
        <div className="flex justify-center py-20">
          <div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
        </div>
      ) : entries.length === 0 ? (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-10 text-center text-gray-400">
          <BookOpen className="mx-auto mb-2" size={32} />
          <p>{t('knowledgeBaseCommon.noRecords')}</p>
        </div>
      ) : (
        <div className="space-y-3">
          {entries.map((entry) => (
            <div key={entry.id} className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <div className="flex items-start justify-between mb-2">
                <div>
                  <h3 className="font-semibold text-sm">{entry.title}</h3>
                  <span className="text-xs text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full">{entry.category}</span>
                </div>
                <div className="flex items-center gap-1">
                  <button onClick={() => openEdit(entry)} className="p-1.5 text-gray-400 hover:text-indigo-600 rounded-lg hover:bg-gray-50">
                    <Pencil size={14} />
                  </button>
                  <button onClick={() => handleDelete(entry.id)} className="p-1.5 text-gray-400 hover:text-red-600 rounded-lg hover:bg-gray-50">
                    <Trash2 size={14} />
                  </button>
                </div>
              </div>
              <p className="text-sm text-gray-600 line-clamp-2">{entry.content}</p>
              {entry.tags && entry.tags.length > 0 && (
                <div className="flex gap-1.5 mt-3">
                  {entry.tags.map((tag) => (
                    <span key={tag} className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{tag}</span>
                  ))}
                </div>
              )}
            </div>
          ))}
          {/* Infinite scroll sentinel */}
          <div ref={sentinelRef} />
          {loadingMore && (
            <div className="flex justify-center py-4">
              <div className="w-6 h-6 border-3 border-indigo-600 border-t-transparent rounded-full animate-spin" />
            </div>
          )}
        </div>
      )}

      {/* Modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl w-full max-w-lg shadow-xl">
            <div className="flex items-center justify-between p-5 border-b border-gray-100">
              <h2 className="font-semibold">{editing ? t('knowledgeBaseCommon.editRecord') : t('knowledgeBaseCommon.newRecord')}</h2>
              <button onClick={() => setShowForm(false)} className="text-gray-400 hover:text-gray-600">
                <X size={20} />
              </button>
            </div>
            <div className="p-5 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('knowledgeBaseCommon.category')}</label>
                <input
                  type="text"
                  value={form.category}
                  onChange={(e) => setForm({ ...form, category: e.target.value })}
                  placeholder={t('knowledgeBaseCommon.categoryPlaceholder')}
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  list="category-list"
                />
                <datalist id="category-list">
                  {categories.map((cat) => <option key={cat} value={cat} />)}
                </datalist>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('knowledgeBaseCommon.titleLabel')}</label>
                <input
                  type="text"
                  value={form.title}
                  onChange={(e) => setForm({ ...form, title: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('knowledgeBaseCommon.content')}</label>
                <textarea
                  value={form.content}
                  onChange={(e) => setForm({ ...form, content: e.target.value })}
                  rows={4}
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('knowledgeBaseCommon.tags')} <span className="text-gray-400">({t('knowledgeBaseCommon.tagsHint')})</span></label>
                <input
                  type="text"
                  value={form.tags}
                  onChange={(e) => setForm({ ...form, tags: e.target.value })}
                  placeholder={t('knowledgeBaseCommon.tagsPlaceholder')}
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
            </div>
            <div className="flex justify-end gap-2 p-5 border-t border-gray-100">
              <button onClick={() => setShowForm(false)} className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">{t('common.cancel')}</button>
              <button
                onClick={handleSave}
                disabled={saving || !form.category || !form.title || !form.content}
                className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors"
              >
                {saving ? t('common.saving') : t('common.save')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
