import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import api from '@/lib/api'
import { Building2, Plus, X, Save, CheckCircle, Users, Loader2, Pencil } from 'lucide-react'
import clsx from 'clsx'

interface TenantItem {
  id: number
  name: string
  slug: string
  is_active: boolean
  users_count: number
  channels_count: number
  conversations_count: number
  settings: Record<string, unknown> | null
  created_at: string | null
}

// 9 alt ürün — tenant aboneliği bu eksende atanır (sayfa yetkileri backend'de üründen türetilir)
const ALL_PRODUCTS = [
  { key: 'inbox', label: 'Mesaj' },
  { key: 'chatbot', label: 'Web Chat' },
]



export default function TenantsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [tenants, setTenants] = useState<TenantItem[]>([])
  const [loading, setLoading] = useState(true)

  // Create modal
  const [showCreate, setShowCreate] = useState(false)
  const [createForm, setCreateForm] = useState({ name: '', slug: '', admin_name: '', admin_email: '', admin_password: '' })
  const [createProducts, setCreateProducts] = useState<string[]>(ALL_PRODUCTS.map(p => p.key))
  const [creating, setCreating] = useState(false)
  const [createError, setCreateError] = useState('')

  // Edit modal
  const [editTenant, setEditTenant] = useState<TenantItem | null>(null)
  const [editForm, setEditForm] = useState({ name: '', slug: '', is_active: true })
  const [editProducts, setEditProducts] = useState<string[]>([])
  const [editing, setEditing] = useState(false)
  const [deleteSlug, setDeleteSlug] = useState('')
  const [deleting, setDeleting] = useState(false)

  // Kota modalı (yalnız süper admin sayfası olduğundan ek rol kontrolü gerekmez)

  const [saved, setSaved] = useState(false)

  useEffect(() => {
    fetchTenants()
  }, [])

  const fetchTenants = async () => {
    try {
      const res = await api.get('/tenants')
      setTenants(res.data)
    } finally {
      setLoading(false)
    }
  }

  if (!user?.is_super_admin) {
    return (
      <div className="flex items-center justify-center py-20">
        <p className="text-gray-500">{t('tenantsCommon.noAccess')}</p>
      </div>
    )
  }

  const handleCreate = async () => {
    setCreating(true)
    setCreateError('')
    try {
      const res = await api.post('/tenants', { ...createForm, products: createProducts })
      setTenants((prev) => [...prev, { ...res.data, users_count: 1, channels_count: 0, conversations_count: 0 }])
      setShowCreate(false)
      setCreateForm({ name: '', slug: '', admin_name: '', admin_email: '', admin_password: '' })
      setCreateProducts(ALL_PRODUCTS.map(p => p.key))
      setSaved(true)
      setTimeout(() => setSaved(false), 3000)
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.errors
      if (typeof msg === 'object') {
        setCreateError(Object.values(msg).flat().join(' '))
      } else {
        setCreateError(msg || 'Bir hata olustu.')
      }
    } finally {
      setCreating(false)
    }
  }

  const openEdit = (tenant: TenantItem) => {
    setEditTenant(tenant)
    setEditForm({ name: tenant.name, slug: tenant.slug, is_active: tenant.is_active })
    const tenantProducts = (tenant.settings as any)?.products as string[] ?? ALL_PRODUCTS.map(p => p.key)
    setEditProducts(tenantProducts)
  }

  const handleEdit = async () => {
    if (!editTenant) return
    setEditing(true)
    try {
      const res = await api.patch(`/tenants/${editTenant.id}`, { ...editForm, products: editProducts })
      setTenants((prev) => prev.map((t) => (t.id === editTenant.id ? { ...t, ...res.data } : t)))
      setEditTenant(null)
      setSaved(true)
      setTimeout(() => setSaved(false), 3000)
    } finally {
      setEditing(false)
    }
  }

  const handleDelete = async () => {
    if (!editTenant || deleteSlug !== editTenant.slug) return
    if (!window.confirm(`${editTenant.name} firmasının TÜM verileri (DB + dosyalar) kalıcı olarak silinecek. Bu işlem GERİ ALINAMAZ. Emin misiniz?`)) return
    setDeleting(true)
    try {
      await api.delete(`/tenants/${editTenant.id}`, { data: { confirm_slug: deleteSlug } })
      setTenants((prev) => prev.filter((t) => t.id !== editTenant.id))
      setEditTenant(null)
      setDeleteSlug('')
    } finally {
      setDeleting(false)
    }
  }

  const togglePage = (pages: string[], setPages: (p: string[]) => void, page: string) => {
    if (pages.includes(page)) {
      setPages(pages.filter(p => p !== page))
    } else {
      setPages([...pages, page])
    }
  }

  if (loading) {
    return <div className="flex justify-center py-20"><div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" /></div>
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-extrabold text-gray-900">{t('tenantsCommon.title')}</h1>
        <div className="flex items-center gap-3">
          {saved && (
            <span className="flex items-center gap-1 text-sm text-green-600">
              <CheckCircle size={16} /> {t('tenantsCommon.saved')}
            </span>
          )}
          <button
            onClick={() => setShowCreate(true)}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors"
          >
            <Plus size={16} />
            {t('tenantsCommon.newCompany')}
          </button>
        </div>
      </div>

      {/* Tenant cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {tenants.map((tenant) => (
          <div key={tenant.id} className={clsx(
            'bg-white rounded-xl shadow-sm border overflow-hidden',
            tenant.is_active ? 'border-gray-100' : 'border-red-100 bg-red-50/30'
          )}>
            <div className="p-5">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className={clsx(
                    'w-11 h-11 rounded-2xl flex items-center justify-center',
                    tenant.is_active ? 'bg-indigo-100 text-indigo-600' : 'bg-gray-100 text-gray-400'
                  )}>
                    <Building2 size={20} />
                  </div>
                  <div>
                    <h3 className="font-semibold text-sm">{tenant.name}</h3>
                    <p className="text-xs text-gray-500">{tenant.slug}</p>
                  </div>
                </div>
                <div className="flex items-center gap-1">
                  <button
                    onClick={() => openEdit(tenant)}
                    className="p-1.5 text-gray-400 hover:text-indigo-600 rounded-lg hover:bg-indigo-50 transition-colors"
                    title="Duzenle"
                  >
                    <Pencil size={14} />
                  </button>
                </div>
              </div>

              <div className="flex items-center gap-4 mt-4 text-xs text-gray-500">
                <span className="flex items-center gap-1">
                  <Users size={12} />
                  {tenant.users_count} {t('tenantsCommon.users')}
                </span>
              </div>

              {/* Ürün rozetleri */}
              {(() => {
                const prods = ((tenant.settings as any)?.products as string[]) ?? ALL_PRODUCTS.map(p => p.key)
                return (
                  <div className="flex flex-wrap gap-1 mt-3">
                    {prods.map((key) => (
                      <span key={key} className="text-[10px] px-2 py-0.5 bg-indigo-50 text-indigo-600 rounded-full font-semibold">
                        {ALL_PRODUCTS.find(p => p.key === key)?.label ?? key}
                      </span>
                    ))}
                  </div>
                )
              })()}

              {!tenant.is_active && (
                <div className="mt-3 px-2 py-1 bg-red-100 text-red-700 text-xs rounded-md text-center">
                  Pasif
                </div>
              )}
            </div>
          </div>
        ))}
      </div>

      {tenants.length === 0 && (
        <div className="text-center py-12 text-gray-500">
          {t('tenantsCommon.noCompanies')}
        </div>
      )}

      {/* Create Modal */}
      {showCreate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between p-5 border-b border-gray-100 sticky top-0 bg-white rounded-t-xl">
              <h2 className="font-semibold text-gray-900">{t('tenantsCommon.addNewCompany')}</h2>
              <button onClick={() => { setShowCreate(false); setCreateError('') }} className="p-1 text-gray-400 hover:text-gray-600 rounded">
                <X size={18} />
              </button>
            </div>

            <div className="p-5 space-y-4">
              {createError && (
                <div className="bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-xs text-red-700">
                  {createError}
                </div>
              )}

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Firma Adi *</label>
                <input
                  type="text"
                  value={createForm.name}
                  onChange={(e) => setCreateForm((p) => ({ ...p, name: e.target.value }))}
                  placeholder="Ornek: Firma Adı"
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Slug (otomatik olusturulur)</label>
                <input
                  type="text"
                  value={createForm.slug}
                  onChange={(e) => setCreateForm((p) => ({ ...p, slug: e.target.value }))}
                  placeholder="magaza-slug"
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <hr className="border-gray-100" />
              <p className="text-xs text-gray-500">Firma admin hesabi olusturulacak:</p>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Admin Adi *</label>
                <input
                  type="text"
                  value={createForm.admin_name}
                  onChange={(e) => setCreateForm((p) => ({ ...p, admin_name: e.target.value }))}
                  placeholder="Ahmet Yilmaz"
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">E-posta *</label>
                <input
                  type="email"
                  value={createForm.admin_email}
                  onChange={(e) => setCreateForm((p) => ({ ...p, admin_email: e.target.value }))}
                  placeholder="admin@firma.com"
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Sifre *</label>
                <input
                  type="password"
                  value={createForm.admin_password}
                  onChange={(e) => setCreateForm((p) => ({ ...p, admin_password: e.target.value }))}
                  placeholder="En az 8 karakter"
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <hr className="border-gray-100" />

              {/* Ürünler */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Ürünler</label>
                <div className="grid grid-cols-2 gap-2">
                  {ALL_PRODUCTS.map((product) => (
                    <label key={product.key} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={createProducts.includes(product.key)}
                        onChange={() => togglePage(createProducts, setCreateProducts, product.key)}
                        className="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500"
                      />
                      Yunbi {product.label}
                    </label>
                  ))}
                </div>
              </div>
            </div>

            <div className="flex items-center justify-end gap-3 p-5 border-t border-gray-100 sticky bottom-0 bg-white rounded-b-xl">
              <button onClick={() => { setShowCreate(false); setCreateError('') }} className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                Iptal
              </button>
              <button
                onClick={handleCreate}
                disabled={creating || !createForm.name || !createForm.admin_name || !createForm.admin_email || !createForm.admin_password}
                className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors"
              >
                {creating ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />}
                {creating ? 'Olusturuluyor...' : 'Olustur'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Edit Modal */}
      {editTenant && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between p-5 border-b border-gray-100 sticky top-0 bg-white rounded-t-xl">
              <h2 className="font-semibold text-gray-900">{t('tenantsCommon.editCompany')}</h2>
              <button onClick={() => setEditTenant(null)} className="p-1 text-gray-400 hover:text-gray-600 rounded">
                <X size={18} />
              </button>
            </div>

            <div className="p-5 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Firma Adi</label>
                <input
                  type="text"
                  value={editForm.name}
                  onChange={(e) => setEditForm((p) => ({ ...p, name: e.target.value }))}
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                <input
                  type="text"
                  value={editForm.slug}
                  onChange={(e) => setEditForm((p) => ({ ...p, slug: e.target.value }))}
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Durum</label>
                <select
                  value={editForm.is_active ? 'active' : 'inactive'}
                  onChange={(e) => setEditForm((p) => ({ ...p, is_active: e.target.value === 'active' }))}
                  className="w-full max-w-xs px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <option value="active">Aktif</option>
                  <option value="inactive">Pasif</option>
                </select>
              </div>

              <hr className="border-gray-100" />

              {/* Ürünler */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Ürünler</label>
                <div className="grid grid-cols-2 gap-2">
                  {ALL_PRODUCTS.map((product) => (
                    <label key={product.key} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={editProducts.includes(product.key)}
                        onChange={() => togglePage(editProducts, setEditProducts, product.key)}
                        className="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500"
                      />
                      Yunbi {product.label}
                    </label>
                  ))}
                </div>
              </div>
            </div>

            {/* Tehlikeli Bölge: kalıcı silme (slug yazarak onay) */}
            <div className="mx-5 mb-4 border border-red-200 bg-red-50/50 rounded-lg p-3 space-y-2">
              <div className="text-xs font-semibold text-red-700">Tehlikeli Bölge</div>
              <p className="text-xs text-red-600">
                Firmayı kalıcı silmek TÜM verilerini (ürün, sipariş, müşteri, mesaj, medya…) veritabanından ve
                sunucudan geri dönüşsüz kaldırır. Onay için firma slug'ını (<b>{editTenant.slug}</b>) yazın.
              </p>
              <div className="flex items-center gap-2">
                <input
                  value={deleteSlug}
                  onChange={(e) => setDeleteSlug(e.target.value)}
                  placeholder={editTenant.slug}
                  className="flex-1 px-3 py-1.5 border border-red-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
                />
                <button
                  onClick={handleDelete}
                  disabled={deleting || deleteSlug !== editTenant.slug}
                  className="px-3 py-1.5 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 disabled:opacity-40"
                >
                  {deleting ? 'Siliniyor…' : 'Kalıcı Sil'}
                </button>
              </div>
            </div>

            <div className="flex items-center justify-end gap-3 p-5 border-t border-gray-100 sticky bottom-0 bg-white rounded-b-xl">
              <button onClick={() => setEditTenant(null)} className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                Iptal
              </button>
              <button
                onClick={handleEdit}
                disabled={editing}
                className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors"
              >
                {editing ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
                {editing ? 'Kaydediliyor...' : 'Kaydet'}
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  )
}
