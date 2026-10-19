import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import api from '@/lib/api'
import type { User, Tenant, PaginatedResponse } from '@/types'
import { Users, Plus, Pencil, Trash2, Search, X, Check, Eye, Activity } from 'lucide-react'
import clsx from 'clsx'

// Ürün → sayfa-yetkisi kataloğu — backend Tenant::PRODUCT_PAGES ile birebir aynı
const PRODUCT_CATALOG: { product: string; pages: string[] }[] = [
  { product: 'inbox', pages: ['dashboard', 'messages', 'comments', 'email_archive', 'reports'] },
  { product: 'call', pages: ['calls'] },
  { product: 'return', pages: ['returns', 'returns_shortage', 'returns_returned', 'reports'] },
  { product: 'comments', pages: ['crm'] },
  { product: 'store', pages: ['crm', 'crm_delays'] },
  { product: 'ads', pages: ['ads', 'ads_analytics'] },
  { product: 'social', pages: ['marketing'] },
  { product: 'marketing', pages: ['marketing'] },
  { product: 'cro', pages: ['cro'] },
  { product: 'seo', pages: ['seo'] },
]
const ALL_PRODUCTS = PRODUCT_CATALOG.map((c) => c.product)

// Matris atanmamış temsilcinin varsayılanı (backend ile aynı)
const AGENT_DEFAULT_MATRIX: Record<string, string[]> = {
  inbox: ['messages', 'comments', 'email_archive'],
}

// Tenant'ın sahip olduğu ürünler — backend Tenant::products() türetmesiyle aynı
function tenantProductsOf(tenant: Tenant | null | undefined): string[] {
  const settings = tenant?.settings as Record<string, unknown> | null | undefined
  const products = settings?.products as string[] | undefined
  if (products) return ALL_PRODUCTS.filter((p) => products.includes(p))
  const legacyPages = settings?.allowed_pages as string[] | undefined
  if (!legacyPages) return [...ALL_PRODUCTS]
  return PRODUCT_CATALOG.filter((c) => c.pages.some((p) => legacyPages.includes(p))).map((c) => c.product)
}

function cloneMatrix(m: Record<string, string[]>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(m).map(([k, v]) => [k, [...v]]))
}

export default function UsersPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { user: currentUser, impersonate } = useAuth()
  const isSuperAdmin = currentUser?.is_super_admin ?? false
  const [users, setUsers] = useState<User[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [filterRole, setFilterRole] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<User | null>(null)
  const [form, setForm] = useState({
    name: '', email: '', password: '', role: 'agent' as string, status: 'active' as string,
    return_role: 'none' as string,
    page_permissions: cloneMatrix(AGENT_DEFAULT_MATRIX) as Record<string, string[]>,
  })
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  // Sayfa etiketi — aynı anahtar farklı üründe farklı anlam taşır (ör. reports)
  const pageLabel = (product: string, page: string): string => {
    if (product === 'inbox' && page === 'reports') return t('nav.messageAnalysis')
    if (product === 'return' && page === 'reports') return t('nav.returnCancel')
    const labels: Record<string, string> = {
      dashboard: t('nav.dashboard'),
      messages: t('nav.messages'),
      comments: t('nav.comments'),
      email_archive: t('nav.emailArchive'),
      calls: t('nav.callAnalysis'),
      returns: t('nav.returnsManage'),
      returns_shortage: t('nav.returnsShortage'),
      returns_returned: t('nav.returnsReturned'),
      crm: t('users.pages.commentsAll'),
      ads: t('users.pages.adsManage'),
      ads_analytics: t('users.pages.adsAnalytics'),
    }
    if (product === 'store' && page === 'crm') return t('nav.ticimaxCrm')
    if (product === 'store' && page === 'crm_delays') return 'Gecikmeler (statü değiştirme dahil)'
    if (product === 'social' && page === 'marketing') return t('users.pages.socialAll')
    if (product === 'marketing' && page === 'marketing') return t('users.pages.marketingAll')
    return labels[page] ?? page
  }

  const productLabel = (product: string): string => t(`products.${product}`)

  const roleLabels: Record<string, string> = {
    super_admin: t('roles.superAdmin'),
    admin: t('roles.admin'),
    agent: t('roles.agent'),
  }

  const roleColors: Record<string, string> = {
    super_admin: 'bg-purple-100 text-purple-700',
    admin: 'bg-red-100 text-red-700',
    agent: 'bg-blue-100 text-blue-700',
  }

  const roleDescriptions: Record<string, string> = {
    super_admin: t('users.roleDescriptions.superAdmin'),
    admin: t('users.roleDescriptions.admin'),
    agent: t('users.roleDescriptions.agent'),
  }

  // Kullanıcının fiilen görebildiği ürün→sayfa matrisi (liste görünümü için)
  const effectiveMatrix = (user: User): Record<string, string[]> => {
    if (user.is_super_admin) {
      return Object.fromEntries(PRODUCT_CATALOG.map((c) => [c.product, [...c.pages]]))
    }
    const prods = tenantProductsOf(user.tenant)
    if (user.role === 'admin') {
      return Object.fromEntries(
        PRODUCT_CATALOG.filter((c) => prods.includes(c.product)).map((c) => [c.product, [...c.pages]])
      )
    }
    const raw = user.page_permissions ?? AGENT_DEFAULT_MATRIX
    const m: Record<string, string[]> = {}
    for (const c of PRODUCT_CATALOG) {
      if (!prods.includes(c.product)) continue
      const pages = (raw[c.product] ?? []).filter((p) => c.pages.includes(p))
      if (pages.length) m[c.product] = pages
    }
    return m
  }

  const fetchUsers = () => {
    setLoading(true)
    const params: Record<string, string> = {}
    if (search) params.search = search
    if (filterRole) params.role = filterRole

    api.get<PaginatedResponse<User>>('/users', { params }).then((res) => {
      setUsers(res.data.data)
      setLoading(false)
    })
  }

  useEffect(() => { fetchUsers() }, [search, filterRole])

  // Formda gösterilecek tenant ürünleri: düzenlenen kullanıcının firması,
  // yoksa listedeki firma (SA seçili firmayla filtreli), yoksa kendi firmam
  const formTenant = editing?.tenant ?? users.find((u) => u.tenant)?.tenant ?? currentUser?.tenant
  const formTenantProducts = tenantProductsOf(formTenant)

  const openCreate = () => {
    setEditing(null)
    setForm({
      name: '', email: '', password: '', role: 'agent', status: 'active', return_role: 'none',
      page_permissions: cloneMatrix(AGENT_DEFAULT_MATRIX),
    })
    setFormError('')
    setShowForm(true)
  }

  const openEdit = (user: User) => {
    setEditing(user)
    setForm({
      name: user.name,
      email: user.email,
      password: '',
      role: user.is_super_admin ? 'super_admin' : user.role,
      status: user.status,
      return_role: user.return_role ?? 'none',
      page_permissions: cloneMatrix(user.page_permissions ?? AGENT_DEFAULT_MATRIX),
    })
    setFormError('')
    setShowForm(true)
  }

  const togglePage = (product: string, page: string) => {
    setForm((f) => {
      const m = cloneMatrix(f.page_permissions)
      const current = m[product] ?? []
      m[product] = current.includes(page) ? current.filter((p) => p !== page) : [...current, page]
      if (m[product].length === 0) delete m[product]
      return { ...f, page_permissions: m }
    })
  }

  const toggleProduct = (product: string) => {
    setForm((f) => {
      const m = cloneMatrix(f.page_permissions)
      const catalog = PRODUCT_CATALOG.find((c) => c.product === product)
      if (!catalog) return f
      if ((m[product] ?? []).length > 0) delete m[product]
      else m[product] = [...catalog.pages]
      return { ...f, page_permissions: m }
    })
  }

  const handleSave = async () => {
    setFormError('')
    // Backend kuralı: en az 10 karakter, harf + rakam (Password::min(10)->letters()->numbers())
    if (form.password && (form.password.length < 10 || !/[a-zA-Z]/.test(form.password) || !/\d/.test(form.password))) {
      setFormError(t('users.passwordRule'))
      return
    }
    setSaving(true)
    const isSuperAdminRole = form.role === 'super_admin'
    const payload: Record<string, unknown> = {
      name: form.name,
      email: form.email,
      role: isSuperAdminRole ? 'admin' : form.role,
      status: form.status,
    }
    if (isSuperAdminRole) payload.is_super_admin = true
    if (form.password) payload.password = form.password
    // İade personeli yetkisi (yalnızca normal kullanıcılarda). Tenant admini onay yöneticisi olur.
    payload.return_role = isSuperAdminRole ? 'none' : form.return_role
    // Ürün→sayfa yetki matrisi yalnız temsilciler için gönderilir
    if (form.role === 'agent') {
      const pruned = Object.fromEntries(
        Object.entries(form.page_permissions).filter(([, pages]) => pages.length > 0)
      )
      payload.page_permissions = Object.keys(pruned).length > 0 ? pruned : null
    }

    try {
      if (editing) {
        await api.patch(`/users/${editing.id}`, payload)
      } else {
        await api.post('/users', payload)
      }
      setShowForm(false)
      fetchUsers()
    } catch (err: unknown) {
      const error = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
      const errors = error.response?.data?.errors
      if (errors) {
        // Şifre kural hataları İngilizce döner — Türkçe tek mesaja çevir
        setFormError(errors.password ? t('users.passwordRule') : Object.values(errors).flat().join(' '))
      } else {
        setFormError(error.response?.data?.message ?? t('common.error'))
      }
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (id: number) => {
    if (!confirm(t('users.deleteConfirm'))) return
    await api.delete(`/users/${id}`)
    fetchUsers()
  }

  if (loading) {
    return <div className="flex justify-center py-20"><div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" /></div>
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-extrabold text-gray-900">{t('users.title')}</h1>
        <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
          <Plus size={16} /> {t('users.newUser')}
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
            placeholder={t('users.searchPlaceholder')}
            className="w-full pl-9 pr-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
        <select
          value={filterRole}
          onChange={(e) => setFilterRole(e.target.value)}
          className="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm"
        >
          <option value="">{t('users.allRoles')}</option>
          <option value="admin">{t('roles.admin')}</option>
          <option value="agent">{t('roles.agent')}</option>
        </select>
      </div>

      {/* List */}
      {users.length === 0 ? (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-10 text-center text-gray-400">
          <Users className="mx-auto mb-2" size={32} />
          <p>{t('users.noUsers')}</p>
        </div>
      ) : (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
          <table className="w-full">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="text-left px-5 py-3 text-xs font-medium text-gray-500 uppercase">{t('users.user')}</th>
                {isSuperAdmin && <th className="text-left px-5 py-3 text-xs font-medium text-gray-500 uppercase">{t('users.company')}</th>}
                <th className="text-left px-5 py-3 text-xs font-medium text-gray-500 uppercase">{t('users.roleAccess')}</th>
                <th className="text-left px-5 py-3 text-xs font-medium text-gray-500 uppercase">{t('common.status')}</th>
                <th className="text-right px-5 py-3 text-xs font-medium text-gray-500 uppercase">{t('common.action')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {users.map((user) => {
                const matrix = effectiveMatrix(user)
                return (
                  <tr key={user.id} className="hover:bg-gray-50">
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center text-xs font-medium">
                          {user.name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                          <p className="text-sm font-medium">{user.name}</p>
                          <p className="text-xs text-gray-500">{user.email}</p>
                        </div>
                      </div>
                    </td>
                    {isSuperAdmin && (
                      <td className="px-5 py-3">
                        <span className="text-xs text-gray-500">{user.tenant?.name ?? '—'}</span>
                      </td>
                    )}
                    <td className="px-5 py-3">
                      {user.is_super_admin ? (
                        <span className={clsx('text-xs px-2.5 py-1 rounded-full font-medium', roleColors.super_admin)}>
                          {roleLabels.super_admin}
                        </span>
                      ) : (
                        <span className={clsx('text-xs px-2.5 py-1 rounded-full font-medium', roleColors[user.role])}>
                          {roleLabels[user.role] || user.role}
                        </span>
                      )}
                      <div className="flex flex-wrap gap-1 mt-1.5 max-w-xl">
                        {Object.entries(matrix).map(([product, pages]) => (
                          <span key={product} className="inline-flex items-baseline gap-1 text-[11px] bg-gray-50 border border-gray-100 rounded-md px-1.5 py-0.5">
                            <span className="font-semibold text-gray-700">{productLabel(product)}</span>
                            {/* Admin ürünün tümünü görür — sayfa dökümünü yalnız temsilcide göster */}
                            {!user.is_super_admin && user.role === 'agent' && (
                              <span className="text-gray-400">{pages.map((p) => pageLabel(product, p)).join(', ')}</span>
                            )}
                          </span>
                        ))}
                        {user.return_role && user.return_role !== 'none' && (
                          <span className="inline-flex items-center text-[11px] bg-amber-50 border border-amber-100 text-amber-700 rounded-md px-1.5 py-0.5">
                            {t('users.returnStaffChip')}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-5 py-3">
                      <span className={clsx(
                        'text-xs px-2 py-0.5 rounded-full',
                        user.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
                      )}>
                        {user.status === 'active' ? t('common.active') : t('common.inactive')}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-right">
                      <div className="flex items-center justify-end gap-1">
                        {isSuperAdmin && !user.is_super_admin && (
                          <button
                            onClick={async () => {
                              await impersonate(user.id)
                              navigate('/')
                            }}
                            title={t('users.impersonate')}
                            className="p-1.5 text-gray-400 hover:text-violet-600 rounded-lg hover:bg-gray-100"
                          >
                            <Eye size={14} />
                          </button>
                        )}
                        {currentUser?.is_admin && (
                          <button
                            onClick={() => navigate(`/settings/users/activity?user=${user.id}`)}
                            title={t('nav.userActivity')}
                            className="p-1.5 text-gray-400 hover:text-emerald-600 rounded-lg hover:bg-gray-100"
                          >
                            <Activity size={14} />
                          </button>
                        )}
                        <button onClick={() => openEdit(user)} className="p-1.5 text-gray-400 hover:text-indigo-600 rounded-lg hover:bg-gray-100">
                          <Pencil size={14} />
                        </button>
                        {user.id !== currentUser?.id && (
                          <button onClick={() => handleDelete(user.id)} className="p-1.5 text-gray-400 hover:text-red-600 rounded-lg hover:bg-gray-100">
                            <Trash2 size={14} />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl w-full max-w-lg shadow-xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between p-5 border-b border-gray-100 sticky top-0 bg-white rounded-t-2xl">
              <h2 className="font-semibold">{editing ? t('users.editUser') : t('users.newUser')}</h2>
              <button onClick={() => setShowForm(false)} className="text-gray-400 hover:text-gray-600"><X size={20} /></button>
            </div>
            <div className="p-5 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">{t('users.fullName')}</label>
                  <input type="text" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">{t('users.email')}</label>
                  <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">{t('users.password')} {editing && <span className="text-gray-400">{t('users.passwordHint')}</span>}</label>
                  <input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                  <p className="text-[11px] text-gray-400 mt-1">{t('users.passwordRule')}</p>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">{t('users.role')}</label>
                  <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    {isSuperAdmin && <option value="super_admin">{t('roles.superAdmin')}</option>}
                    <option value="admin">{t('roles.admin')}</option>
                    <option value="agent">{t('roles.agent')}</option>
                  </select>
                </div>
              </div>

              {/* Role description */}
              <div className="bg-gray-50 rounded-lg px-3 py-2 text-xs text-gray-600">
                {roleDescriptions[form.role] || ''}
              </div>

              {/* İade yetkisi */}
              {form.role !== 'super_admin' && (
                <div className="border border-gray-200 rounded-lg px-3 py-2.5">
                  {form.role === 'admin' ? (
                    <p className="text-xs text-gray-500">
                      Firma admini iade taleplerine erişir ve <span className="font-medium text-gray-700">iade personelinden sonra onaylayan yönetici</span> olur.
                    </p>
                  ) : (
                    <label className="flex items-start gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={form.return_role === 'staff'}
                        onChange={(e) => setForm({ ...form, return_role: e.target.checked ? 'staff' : 'none' })}
                        className="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                      />
                      <span className="text-sm text-gray-700">
                        İade personeli yetkisi
                        <span className="block text-[11px] text-gray-400">
                          İade taleplerini işler ve 1. onayı verir. Onay (2. onay) firma admini tarafından verilir.
                        </span>
                      </span>
                    </label>
                  )}
                </div>
              )}

              {/* Sayfa yetkileri */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">{t('users.pageAccess')}</label>

                {form.role === 'agent' ? (
                  <>
                    <div className="space-y-2">
                      {PRODUCT_CATALOG.filter((c) => formTenantProducts.includes(c.product)).map((c) => {
                        const selected = form.page_permissions[c.product] ?? []
                        return (
                          <div key={c.product} className="border border-gray-200 rounded-lg px-3 py-2.5">
                            <label className="flex items-center gap-2 cursor-pointer">
                              <input
                                type="checkbox"
                                checked={selected.length > 0}
                                onChange={() => toggleProduct(c.product)}
                                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                              />
                              <span className="text-sm font-semibold text-gray-800">{productLabel(c.product)}</span>
                              {selected.length > 0 && selected.length < c.pages.length && (
                                <span className="text-[10px] text-gray-400">{selected.length}/{c.pages.length}</span>
                              )}
                            </label>
                            <div className="grid grid-cols-2 gap-x-3 gap-y-1 mt-1.5 pl-6">
                              {c.pages.map((page) => (
                                <label key={page} className="flex items-center gap-1.5 cursor-pointer">
                                  <input
                                    type="checkbox"
                                    checked={selected.includes(page)}
                                    onChange={() => togglePage(c.product, page)}
                                    className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 w-3.5 h-3.5"
                                  />
                                  <span className="text-xs text-gray-600">{pageLabel(c.product, page)}</span>
                                </label>
                              ))}
                            </div>
                          </div>
                        )
                      })}
                    </div>
                    <p className="text-[11px] text-gray-400 mt-1.5">{t('users.pageAccessNotes.agent')}</p>
                  </>
                ) : (
                  <>
                    <div className="flex flex-wrap gap-1.5">
                      {(form.role === 'super_admin' ? ALL_PRODUCTS : formTenantProducts).map((product) => (
                        <span key={product} className="inline-flex items-center gap-1.5 text-xs px-2 py-1 rounded bg-green-50 text-green-700">
                          <Check size={12} strokeWidth={3} /> {productLabel(product)}
                        </span>
                      ))}
                      <span className="inline-flex items-center gap-1.5 text-xs px-2 py-1 rounded bg-green-50 text-green-700">
                        <Check size={12} strokeWidth={3} /> {t('nav.settings')}
                      </span>
                    </div>
                    <p className="text-[11px] text-gray-400 mt-1.5">
                      {form.role === 'super_admin' ? t('users.pageAccessNotes.superAdmin') : t('users.pageAccessNotes.admin')}
                    </p>
                  </>
                )}
              </div>
            </div>
            <div className="flex items-center justify-end gap-2 p-5 border-t border-gray-100 sticky bottom-0 bg-white rounded-b-2xl">
              {formError && <p className="flex-1 text-sm text-red-600">{formError}</p>}
              <button onClick={() => setShowForm(false)} className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">{t('common.cancel')}</button>
              <button
                onClick={handleSave}
                disabled={saving || !form.name || !form.email || (!editing && !form.password)}
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
