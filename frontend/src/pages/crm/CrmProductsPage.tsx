import { useEffect, useState, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import SortableTh from '@/components/crm/SortableTh'
import type { Product, ProductStats, PaginatedResponse } from '@/types'
import { Package, Search, Filter, X } from 'lucide-react'
import clsx from 'clsx'

interface BrandCount { brand: string; count: number }
interface CategoryCount { category: string; count: number }

export default function CrmProductsPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [products, setProducts] = useState<Product[]>([])
  const [stats, setStats] = useState<ProductStats | null>(null)
  const [brands, setBrands] = useState<BrandCount[]>([])
  const [categories, setCategories] = useState<CategoryCount[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)

  // Filters
  const [search, setSearch] = useState('')
  const [filterBrand, setFilterBrand] = useState('')
  const [filterCategory, setFilterCategory] = useState('')
  const [filterActive, setFilterActive] = useState('')
  const [filterStock, setFilterStock] = useState('')
  const [filterTrendyol, setFilterTrendyol] = useState('')
  const [showFilters, setShowFilters] = useState(false)

  const sentinelRef = useRef<HTMLDivElement>(null)

  // Sıralama: başlığa ilk tık desc (büyükten küçüğe), ikinci tık asc
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

  const fetchProducts = useCallback(() => {
    setLoading(true)
    const params: Record<string, string> = {
      per_page: '30',
      sort_by: sortBy,
      sort_dir: sortDir,
    }
    if (search) params.search = search
    if (filterBrand) params.brand = filterBrand
    if (filterCategory) params.category = filterCategory
    if (filterActive) params.is_active = filterActive
    if (filterStock) params.in_stock = filterStock
    if (filterTrendyol) params.trendyol_status = filterTrendyol

    api.get<PaginatedResponse<Product>>('/crm/products', { params }).then((res) => {
      setProducts(res.data.data)
      setPage(res.data.current_page)
      setLastPage(res.data.last_page)
      setTotal(res.data.total)
      setLoading(false)
    })
  }, [search, filterBrand, filterCategory, filterActive, filterStock, filterTrendyol, sortBy, sortDir])

  const fetchMeta = useCallback(() => {
    Promise.all([
      api.get<ProductStats>('/crm/products/stats'),
      api.get<BrandCount[]>('/crm/products/brands'),
      api.get<CategoryCount[]>('/crm/products/categories'),
    ]).then(([statsRes, brandsRes, catsRes]) => {
      setStats(statsRes.data)
      setBrands(brandsRes.data)
      setCategories(catsRes.data)
    })
  }, [])

  useEffect(() => { fetchProducts() }, [fetchProducts])
  useEffect(() => { fetchMeta() }, [fetchMeta])

  // Infinite scroll
  const fetchMore = useCallback(() => {
    if (loadingMore || page >= lastPage) return
    setLoadingMore(true)
    const nextPage = page + 1
    const params: Record<string, string> = {
      per_page: '30',
      page: String(nextPage),
      sort_by: sortBy,
      sort_dir: sortDir,
    }
    if (search) params.search = search
    if (filterBrand) params.brand = filterBrand
    if (filterCategory) params.category = filterCategory
    if (filterActive) params.is_active = filterActive
    if (filterStock) params.in_stock = filterStock
    if (filterTrendyol) params.trendyol_status = filterTrendyol

    api.get<PaginatedResponse<Product>>('/crm/products', { params }).then((res) => {
      setProducts(prev => [...prev, ...res.data.data])
      setPage(res.data.current_page)
      setLastPage(res.data.last_page)
      setLoadingMore(false)
    })
  }, [page, lastPage, loadingMore, search, filterBrand, filterCategory, filterActive, filterStock, filterTrendyol, sortBy, sortDir])

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

  const hasActiveFilters = filterBrand || filterCategory || filterActive || filterStock || filterTrendyol

  const clearFilters = () => {
    setFilterBrand('')
    setFilterCategory('')
    setFilterActive('')
    setFilterStock('')
    setFilterTrendyol('')
  }

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  const formatPrice = (price: string | null, currency?: string) => {
    if (!price) return '-'
    const num = parseFloat(price)
    return new Intl.NumberFormat(locale, { style: 'currency', currency: currency || 'TRY' }).format(num)
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
            <Package className="text-indigo-600" size={20} />
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-gray-900">{t('crm.products.title')}</h1>
            <p className="text-sm text-gray-500">{t('crm.products.subtitle')}</p>
          </div>
        </div>
      </div>

      {/* Stats Cards */}
      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
          <StatCard label={t('crm.products.totalStat')} value={stats.total} locale={locale} />
          <StatCard label={t('crm.products.activeStat')} value={stats.active} color="green" locale={locale} />
          <StatCard label={t('crm.products.inactiveStat')} value={stats.inactive} color="red" locale={locale} />
          <StatCard label={t('crm.products.outOfStockStat')} value={stats.out_of_stock} color="yellow" locale={locale} />
          <StatCard label={t('crm.products.variantStat')} value={stats.total_variants} color="blue" locale={locale} />
          <StatCard label={t('crm.products.brandStat')} value={stats.brands_count} color="purple" locale={locale} />
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
              placeholder={t('crm.products.searchPlaceholder')}
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
            {t('crm.products.filter')}
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
              value={filterBrand}
              onChange={(e) => setFilterBrand(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.products.allBrands')}</option>
              {brands.map((b) => (
                <option key={b.brand} value={b.brand}>{b.brand} ({b.count})</option>
              ))}
            </select>
            <select
              value={filterCategory}
              onChange={(e) => setFilterCategory(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.products.allCategories')}</option>
              {categories.map((c) => (
                <option key={c.category} value={c.category}>{c.category} ({c.count})</option>
              ))}
            </select>
            <select
              value={filterActive}
              onChange={(e) => setFilterActive(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.products.activeInactive')}</option>
              <option value="true">{t('common.active')}</option>
              <option value="false">{t('common.inactive')}</option>
            </select>
            <select
              value={filterStock}
              onChange={(e) => setFilterStock(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.products.stockStatus')}</option>
              <option value="true">{t('crm.products.hasStock')}</option>
              <option value="false">{t('crm.products.noStock')}</option>
            </select>
            <select
              value={filterTrendyol}
              onChange={(e) => setFilterTrendyol(e.target.value)}
              className="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">{t('crm.products.trendyolStatus')}</option>
              <option value="mapped">{t('crm.products.mapped')}</option>
              <option value="unmapped">{t('crm.products.unmapped')}</option>
            </select>
          </div>
        )}
      </div>

      {/* Products Table */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-gray-500">{t('common.loading')}</div>
        ) : products.length === 0 ? (
          <div className="p-12 text-center text-gray-500">
            <Package className="mx-auto mb-3 text-gray-300" size={40} />
            <p className="text-lg font-medium text-gray-600">{t('crm.products.noProducts')}</p>
            <p className="text-sm mt-1">{t('crm.products.tryChangingFilters')}</p>
          </div>
        ) : (
          <>
            <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
              <span className="text-sm text-gray-500">{t('crm.products.productCount', { count: total })}</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-100 text-left">
                    <th className="px-4 py-3 font-medium text-gray-500 w-16"></th>
                    <SortableTh col="name" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.favorites.product')}</SortableTh>
                    <th className="px-4 py-3 font-medium text-gray-500">{t('crm.products.sku')}</th>
                    <th className="px-4 py-3 font-medium text-gray-500">{t('crm.products.category')}</th>
                    <SortableTh col="brand" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.products.brand')}</SortableTh>
                    <SortableTh col="price" className="text-right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.products.price')}</SortableTh>
                    <SortableTh col="stock_quantity" className="text-right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.products.stock')}</SortableTh>
                    <SortableTh col="platform_created_at" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}>{t('crm.products.added')}</SortableTh>
                    <th className="px-4 py-3 font-medium text-gray-500 text-center">{t('crm.products.status')}</th>
                  </tr>
                </thead>
                <tbody>
                  {products.map((product) => (
                    <tr
                      key={product.id}
                      onClick={() => navigate(`/crm/products/${product.id}`)}
                      className="border-b border-gray-50 hover:bg-gray-50 cursor-pointer transition-colors"
                    >
                      <td className="px-4 py-3">
                        {product.images?.[0] ? (
                          <img
                            src={product.images[0]}
                            alt=""
                            className="w-10 h-10 rounded-lg object-cover bg-gray-100"
                            loading="lazy"
                          />
                        ) : (
                          <div className="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
                            <Package size={16} className="text-gray-400" />
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900 line-clamp-1 max-w-xs">{product.name}</div>
                      </td>
                      <td className="px-4 py-3 text-gray-600 font-mono text-xs">{product.sku?.split('|')[1] || product.sku || '-'}</td>
                      <td className="px-4 py-3">
                        <div className="text-gray-600 text-xs">{product.category || '-'}</div>
                        {product.category_path && product.category_path !== product.category && (
                          <div className="text-[11px] text-gray-400 mt-0.5 line-clamp-1">{product.category_path}</div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-gray-600">{product.brand || '-'}</td>
                      <td className="px-4 py-3 text-right">
                        {product.discounted_price && parseFloat(product.discounted_price) > 0 ? (
                          <div>
                            <span className="text-gray-400 line-through text-xs">{formatPrice(product.price, product.currency)}</span>
                            <br />
                            <span className="font-medium text-green-700">{formatPrice(product.discounted_price, product.currency)}</span>
                          </div>
                        ) : (
                          <span className="font-medium text-gray-900">{formatPrice(product.price, product.currency)}</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <span className={clsx(
                          'font-medium',
                          product.stock_quantity > 0 ? 'text-gray-900' : 'text-red-600'
                        )}>
                          {product.stock_quantity}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                        {product.platform_created_at
                          ? new Date(product.platform_created_at).toLocaleDateString(locale)
                          : '-'}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className={clsx(
                          'inline-flex px-2 py-0.5 text-xs font-medium rounded-full',
                          product.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
                        )}>
                          {product.is_active ? t('common.active') : t('common.inactive')}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {loadingMore && (
              <div className="py-4 text-center text-sm text-gray-500">{t('crm.products.loadingMore')}</div>
            )}
            <div ref={sentinelRef} />
          </>
        )}
      </div>
    </div>
  )
}

function StatCard({ label, value, color, locale }: { label: string; value: number; color?: string; locale: string }) {
  const colorMap: Record<string, string> = {
    green: 'text-green-700 bg-green-50',
    red: 'text-red-700 bg-red-50',
    yellow: 'text-yellow-700 bg-yellow-50',
    blue: 'text-blue-700 bg-blue-50',
    purple: 'text-purple-700 bg-purple-50',
  }
  const cls = color ? colorMap[color] || '' : ''

  return (
    <div className={clsx('rounded-xl border border-gray-200 p-4', cls || 'bg-white')}>
      <div className="text-2xl font-semibold">{value.toLocaleString(locale)}</div>
      <div className="text-xs text-gray-500 mt-1">{label}</div>
    </div>
  )
}
