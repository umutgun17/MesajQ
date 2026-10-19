import { useEffect, useState, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import SortableTh from '@/components/crm/SortableTh'
import type { CrmCustomer, CustomerStats, PaginatedResponse } from '@/types'
import { Users, Search, Filter, X } from 'lucide-react'
import clsx from 'clsx'

interface CityCount { city: string; count: number }
interface MemberTypeCount { member_type: string; count: number }

export default function CrmCustomersPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [customers, setCustomers] = useState<CrmCustomer[]>([])
  const [stats, setStats] = useState<CustomerStats | null>(null)
  const [cities, setCities] = useState<CityCount[]>([])
  const [memberTypes, setMemberTypes] = useState<MemberTypeCount[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  // Filters
  const [search, setSearch] = useState('')
  const [filterCity, setFilterCity] = useState('')
  const [filterGender, setFilterGender] = useState('')
  const [filterActive, setFilterActive] = useState('')
  const [filterMailConsent, setFilterMailConsent] = useState('')
  const [filterMemberType, setFilterMemberType] = useState('')
  const [showFilters, setShowFilters] = useState(false)

  const sentinelRef = useRef<HTMLDivElement>(null)

  // Sıralama: başlığa ilk tık desc, ikinci tık asc
  const [sortBy, setSortBy] = useState('platform_created_at')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc')
  const handleSort = (col: string) => {
    if (col === sortBy) {
      setSortDir((d) => (d === 'desc' ? 'asc' : 'desc'))
    } else {
      setSortBy(col)
      setSortDir('desc')
    }
  }

  const buildParams = useCallback(() => {
    const params: Record<string, string> = {
      per_page: '30',
      sort_by: sortBy,
      sort_dir: sortDir,
    }
    if (search) params.search = search
    if (filterCity) params.city = filterCity
    if (filterGender) params.gender = filterGender
    if (filterActive) params.is_active = filterActive
    if (filterMailConsent) params.mail_consent = filterMailConsent
    if (filterMemberType) params.member_type = filterMemberType
    return params
  }, [search, filterCity, filterGender, filterActive, filterMailConsent, filterMemberType, sortBy, sortDir])

  const fetchCustomers = useCallback(() => {
    setLoading(true)
    api.get<PaginatedResponse<CrmCustomer>>('/crm/customers', { params: buildParams() }).then((res) => {
      setCustomers(res.data.data)
      setPage(res.data.current_page)
      setLastPage(res.data.last_page)
      setTotal(res.data.total)
      setLoading(false)
    })
  }, [buildParams])

  const fetchMeta = useCallback(() => {
    Promise.all([
      api.get<CustomerStats>('/crm/customers/stats'),
      api.get<CityCount[]>('/crm/customers/cities'),
      api.get<MemberTypeCount[]>('/crm/customers/member-types'),
    ]).then(([statsRes, citiesRes, typesRes]) => {
      setStats(statsRes.data)
      setCities(citiesRes.data)
      setMemberTypes(typesRes.data)
    })
  }, [])

  useEffect(() => { fetchCustomers() }, [fetchCustomers])
  useEffect(() => { fetchMeta() }, [fetchMeta])

  // Infinite scroll
  const fetchMore = useCallback(() => {
    if (loadingMore || page >= lastPage) return
    setLoadingMore(true)
    const params = buildParams()
    params.page = String(page + 1)

    api.get<PaginatedResponse<CrmCustomer>>('/crm/customers', { params }).then((res) => {
      setCustomers(prev => [...prev, ...res.data.data])
      setPage(res.data.current_page)
      setLastPage(res.data.last_page)
      setLoadingMore(false)
    })
  }, [page, lastPage, loadingMore, buildParams])

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

  const hasActiveFilters = filterCity || filterGender || filterActive || filterMailConsent || filterMemberType

  const clearFilters = () => {
    setFilterCity('')
    setFilterGender('')
    setFilterActive('')
    setFilterMailConsent('')
    setFilterMemberType('')
  }

  const formatCurrency = (val: number) =>
    new Intl.NumberFormat(locale, { style: 'currency', currency: 'TRY' }).format(val)

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
            <Users className="text-indigo-600" size={20} />
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-gray-900">{t('crm.customers.title')}</h1>
            <p className="text-sm text-gray-500">{t('crm.customers.subtitle')}</p>
          </div>
        </div>
      </div>

      {/* Stats Cards */}
      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <StatCard label={t('crm.customers.totalMember')} value={stats.total.toLocaleString(locale)} />
          <StatCard label={t('crm.customers.activeMember')} value={stats.active.toLocaleString(locale)} color="green" />
          <StatCard label={t('crm.customers.totalOrdersStat')} value={stats.total_orders.toLocaleString(locale)} color="blue" />
          <StatCard label={t('crm.customers.totalRevenue')} value={formatCurrency(stats.total_revenue)} color="purple" />
        </div>
      )}

      {/* Search + Filters */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div className="flex items-center gap-3">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('crm.customers.searchPlaceholder')}
              className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
            />
          </div>
          <button
            onClick={() => setShowFilters(!showFilters)}
            className={clsx(
              'flex items-center gap-2 px-3 py-2 text-sm rounded-lg border transition-colors',
              hasActiveFilters
                ? 'bg-indigo-50 text-indigo-700 border-indigo-200'
                : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
            )}
          >
            <Filter size={16} />
            {t('crm.customers.filter')}
            {hasActiveFilters && (
              <span className="w-2 h-2 bg-indigo-500 rounded-full" />
            )}
          </button>
          {hasActiveFilters && (
            <button onClick={clearFilters} className="text-sm text-gray-500 hover:text-gray-700">
              <X size={16} />
            </button>
          )}
        </div>

        {showFilters && (
          <div className="mt-4 pt-4 border-t border-gray-100 grid grid-cols-2 md:grid-cols-5 gap-3">
            <select
              value={filterCity}
              onChange={(e) => setFilterCity(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.customers.allCities')}</option>
              {cities.map((c) => (
                <option key={c.city} value={c.city}>{c.city} ({c.count})</option>
              ))}
            </select>
            <select
              value={filterGender}
              onChange={(e) => setFilterGender(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.customers.gender')}</option>
              <option value="1">{t('crm.customers.male')}</option>
              <option value="2">{t('crm.customers.female')}</option>
            </select>
            <select
              value={filterActive}
              onChange={(e) => setFilterActive(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.customers.activeInactive')}</option>
              <option value="true">{t('common.active')}</option>
              <option value="false">{t('common.inactive')}</option>
            </select>
            <select
              value={filterMailConsent}
              onChange={(e) => setFilterMailConsent(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.customers.mailConsent')}</option>
              <option value="true">{t('crm.customers.hasConsent')}</option>
              <option value="false">{t('crm.customers.noConsent')}</option>
            </select>
            <select
              value={filterMemberType}
              onChange={(e) => setFilterMemberType(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.customers.memberType')}</option>
              {memberTypes.map((mt) => (
                <option key={mt.member_type} value={mt.member_type}>{mt.member_type} ({mt.count})</option>
              ))}
            </select>
          </div>
        )}
      </div>

      {/* Customers Table */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-gray-500">{t('common.loading')}</div>
        ) : customers.length === 0 ? (
          <div className="p-12 text-center text-gray-500">
            <Users className="mx-auto mb-3 text-gray-300" size={40} />
            <p className="text-lg font-medium text-gray-600">{t('crm.customers.noCustomers')}</p>
            <p className="text-sm mt-1">{t('crm.customers.tryChangingFilters')}</p>
          </div>
        ) : (
          <>
            <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
              <span className="text-sm text-gray-500">{t('crm.customers.customerCount', { count: total.toLocaleString(locale) })}</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-100 text-left">
                    <SortableTh col="name" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.customers.fullName')}</SortableTh>
                    <SortableTh col="email" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.customers.email')}</SortableTh>
                    <th className="px-4 py-3 font-medium text-gray-500">{t('crm.customers.phone')}</th>
                    <SortableTh col="city" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.customers.city')}</SortableTh>
                    <SortableTh col="total_orders" className="text-right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.customers.orders')}</SortableTh>
                    <SortableTh col="total_spent" className="text-right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.customers.revenue')}</SortableTh>
                    <SortableTh col="platform_created_at" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.customers.membershipDate')}</SortableTh>
                    <th className="px-4 py-3 font-medium text-gray-500 text-center">{t('crm.products.status')}</th>
                  </tr>
                </thead>
                <tbody>
                  {customers.map((customer) => (
                    <tr
                      key={customer.id}
                      onClick={() => navigate(`/crm/customers/${customer.id}`)}
                      className="border-b border-gray-50 hover:bg-gray-50 cursor-pointer transition-colors"
                    >
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center text-xs font-medium text-indigo-700 shrink-0">
                            {customer.name?.charAt(0).toUpperCase()}{customer.surname?.charAt(0).toUpperCase() || ''}
                          </div>
                          <div>
                            <div className="font-medium text-gray-900">
                              {customer.name} {customer.surname || ''}
                            </div>
                            {customer.member_type && (
                              <div className="text-[11px] text-gray-400">{customer.member_type}</div>
                            )}
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3 text-gray-600 text-xs">{customer.email || '-'}</td>
                      <td className="px-4 py-3 text-gray-600 text-xs">{customer.phone || '-'}</td>
                      <td className="px-4 py-3 text-gray-600 text-xs">
                        {customer.city || '-'}
                        {customer.district && <span className="text-gray-400"> / {customer.district}</span>}
                      </td>
                      <td className="px-4 py-3 text-right font-medium text-gray-900">{customer.total_orders}</td>
                      <td className="px-4 py-3 text-right font-medium text-gray-900">
                        {customer.total_spent > 0 ? formatCurrency(customer.total_spent) : '-'}
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                        {customer.platform_created_at
                          ? new Date(customer.platform_created_at).toLocaleDateString(locale)
                          : '-'}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className={clsx(
                          'inline-flex px-2 py-0.5 text-xs font-medium rounded-full',
                          customer.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
                        )}>
                          {customer.is_active ? t('common.active') : t('common.inactive')}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {loadingMore && (
              <div className="py-4 text-center text-sm text-gray-500">{t('crm.customers.loadingMore')}</div>
            )}
            <div ref={sentinelRef} />
          </>
        )}
      </div>
    </div>
  )
}

function StatCard({ label, value, color }: { label: string; value: string | number; color?: string }) {
  const colorMap: Record<string, string> = {
    green: 'text-green-700 bg-green-50',
    red: 'text-red-700 bg-red-50',
    blue: 'text-blue-700 bg-blue-50',
    purple: 'text-purple-700 bg-purple-50',
  }
  const cls = color ? colorMap[color] || '' : ''

  return (
    <div className={clsx('rounded-xl border border-gray-200 p-4', cls || 'bg-white')}>
      <div className="text-2xl font-semibold">{value}</div>
      <div className="text-xs text-gray-500 mt-1">{label}</div>
    </div>
  )
}
