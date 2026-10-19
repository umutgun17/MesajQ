import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import type { Product } from '@/types'
import { ArrowLeft, Package, ExternalLink, Copy, Check } from 'lucide-react'
import clsx from 'clsx'

export default function CrmProductDetailPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [product, setProduct] = useState<Product | null>(null)
  const [loading, setLoading] = useState(true)
  const [selectedImage, setSelectedImage] = useState(0)
  const [copiedField, setCopiedField] = useState<string | null>(null)

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  useEffect(() => {
    api.get<Product>(`/crm/products/${id}`).then((res) => {
      setProduct(res.data)
      setLoading(false)
    }).catch(() => {
      navigate('/crm/products')
    })
  }, [id, navigate])

  const formatPrice = (price: string | null, currency?: string) => {
    if (!price) return '-'
    const num = parseFloat(price)
    return new Intl.NumberFormat(locale, { style: 'currency', currency: currency || 'TRY' }).format(num)
  }

  const copyToClipboard = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    setTimeout(() => setCopiedField(null), 2000)
  }

  if (loading) {
    return <div className="p-12 text-center text-gray-500">{t('common.loading')}</div>
  }

  if (!product) return null

  return (
    <div className="space-y-6">
      {/* Back Button */}
      <button
        onClick={() => navigate('/crm/products')}
        className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 transition-colors"
      >
        <ArrowLeft size={16} />
        {t('crm.products.backToProducts')}
      </button>

      {/* Header */}
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center">
            <Package className="text-indigo-600" size={24} />
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-gray-900">{product.name}</h1>
            <div className="flex items-center gap-3 mt-1">
              <span className={clsx(
                'inline-flex px-2 py-0.5 text-xs font-medium rounded-full',
                product.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
              )}>
                {product.is_active ? t('common.active') : t('common.inactive')}
              </span>
              {product.channel && (
                <span className="text-xs text-gray-500">{product.channel.name}</span>
              )}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {product.storefront_url && (
            <a
              href={product.storefront_url}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-2 px-3 py-2 text-sm bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"
            >
              <ExternalLink size={14} />
              {t('crm.products.viewOnSite')}
            </a>
          )}
          {product.admin_url && (
            <a
              href={product.admin_url}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-2 px-3 py-2 text-sm bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-lg hover:bg-indigo-100 transition-colors"
            >
              <ExternalLink size={14} />
              {t('crm.products.viewOnTicimax')}
            </a>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left: Images */}
        <div className="lg:col-span-1">
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 space-y-3">
            {product.images && product.images.length > 0 ? (
              <>
                <div className="aspect-square rounded-lg overflow-hidden bg-gray-100">
                  <img
                    src={product.images[selectedImage] || product.images[0]}
                    alt={product.name}
                    className="w-full h-full object-contain"
                  />
                </div>
                {product.images.length > 1 && (
                  <div className="flex gap-2 overflow-x-auto pb-1">
                    {product.images.map((img, i) => (
                      <button
                        key={i}
                        onClick={() => setSelectedImage(i)}
                        className={clsx(
                          'w-14 h-14 rounded-lg overflow-hidden border-2 shrink-0 transition-colors',
                          i === selectedImage ? 'border-indigo-500' : 'border-gray-200 hover:border-gray-300'
                        )}
                      >
                        <img src={img} alt="" className="w-full h-full object-cover" loading="lazy" />
                      </button>
                    ))}
                  </div>
                )}
              </>
            ) : (
              <div className="aspect-square rounded-lg bg-gray-100 flex items-center justify-center">
                <Package size={48} className="text-gray-300" />
              </div>
            )}
          </div>
        </div>

        {/* Right: Details */}
        <div className="lg:col-span-2 space-y-6">
          {/* Product Info */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 className="text-sm font-semibold text-gray-900 mb-4">{t('crm.products.productInfo')}</h2>
            <div className="grid grid-cols-2 gap-4 text-sm">
              <InfoRow label={t('crm.products.sku')} value={product.variants?.[0]?.sku ?? null} copyable onCopy={copyToClipboard} copiedField={copiedField} copyTitle={t('crm.products.copy')} />
              <InfoRow label={t('crm.products.supplierCode')} value={product.sku} copyable onCopy={copyToClipboard} copiedField={copiedField} copyTitle={t('crm.products.copy')} />
              <InfoRow label={t('crm.products.barcode')} value={product.barcode} copyable onCopy={copyToClipboard} copiedField={copiedField} copyTitle={t('crm.products.copy')} />
              <InfoRow label={t('crm.products.brand')} value={product.brand} />
              <InfoRow label={t('crm.products.category')} value={product.category} />
              {product.category_path && (
                <div className="col-span-2">
                  <InfoRow label={t('crm.products.categoryPath')} value={product.category_path} />
                </div>
              )}
              {product.google_product_category && (
                <div className="col-span-2">
                  <InfoRow label={t('crm.products.googleCategory')} value={product.google_product_category} />
                </div>
              )}
              <InfoRow label={t('crm.products.addedDate')} value={product.platform_created_at ? new Date(product.platform_created_at).toLocaleDateString(locale, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : null} />
              <InfoRow label={t('crm.products.publishDate')} value={product.platform_updated_at ? new Date(product.platform_updated_at).toLocaleDateString(locale, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : null} />
            </div>
          </div>

          {/* Specifications */}
          {product.specifications && Object.keys(product.specifications).length > 0 && (
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <h2 className="text-sm font-semibold text-gray-900 mb-4">
                {t('crm.products.specifications')} <span className="text-gray-400 font-normal">({Object.keys(product.specifications).length})</span>
              </h2>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-2.5 text-sm">
                {Object.entries(product.specifications).map(([key, val]) => (
                  <div key={key} className="flex items-baseline gap-1.5 min-w-0">
                    <span className="text-xs text-gray-500 shrink-0">{key}:</span>
                    <span className="font-medium text-gray-900 truncate">{val}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Pricing */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 className="text-sm font-semibold text-gray-900 mb-4">{t('crm.products.pricingInfo')}</h2>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <div className="text-xs text-gray-500 mb-1">{t('crm.products.salePrice')}</div>
                <div className="font-semibold text-gray-900 text-lg">{formatPrice(product.price, product.currency)}</div>
              </div>
              <div>
                <div className="text-xs text-gray-500 mb-1">{t('crm.products.discountedPrice')}</div>
                <div className={clsx(
                  'font-semibold text-lg',
                  product.discounted_price && parseFloat(product.discounted_price) > 0 ? 'text-green-700' : 'text-gray-400'
                )}>
                  {formatPrice(product.discounted_price, product.currency)}
                </div>
              </div>
              <div>
                <div className="text-xs text-gray-500 mb-1">{t('crm.products.costPrice')}</div>
                <div className="font-medium text-gray-700">{formatPrice(product.cost_price, product.currency)}</div>
              </div>
              <div>
                <div className="text-xs text-gray-500 mb-1">{t('crm.products.taxRate')}</div>
                <div className="font-medium text-gray-700">
                  {product.tax_rate ? `%${product.tax_rate}` : '-'}
                </div>
              </div>
            </div>
          </div>

          {/* Stock */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 className="text-sm font-semibold text-gray-900 mb-4">{t('crm.products.stockInfo')}</h2>
            <div className="flex items-center gap-4">
              <div className={clsx(
                'text-3xl font-bold',
                product.stock_quantity > 0 ? 'text-gray-900' : 'text-red-600'
              )}>
                {product.stock_quantity}
              </div>
              <span className="text-sm text-gray-500">{t('crm.products.totalStockQty')}</span>
            </div>
          </div>

          {/* Description */}
          {product.description && (
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <h2 className="text-sm font-semibold text-gray-900 mb-3">{t('crm.products.description')}</h2>
              <div
                className="text-sm text-gray-600 prose prose-sm max-w-none"
                dangerouslySetInnerHTML={{ __html: product.description }}
              />
            </div>
          )}

          {/* Variants */}
          {product.variants && product.variants.length > 0 && (
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
              <div className="p-5 pb-3">
                <h2 className="text-sm font-semibold text-gray-900">
                  {t('crm.products.variantsSection')} <span className="text-gray-400 font-normal">({product.variants.length})</span>
                </h2>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-y border-gray-100 text-left">
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.products.attributes')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.products.sku')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.products.barcode')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500 text-right">{t('crm.products.price')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500 text-right">{t('crm.products.stock')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500 text-center">{t('crm.products.status')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {product.variants.map((v) => (
                      <tr key={v.id} className="border-b border-gray-50">
                        <td className="px-5 py-2.5">
                          {Object.entries(v.attributes || {}).map(([key, val]) => (
                            <span key={key} className="inline-flex items-center gap-1 mr-2 text-xs">
                              <span className="text-gray-400">{key}:</span>
                              <span className="font-medium text-gray-700">{val}</span>
                            </span>
                          ))}
                          {(!v.attributes || Object.keys(v.attributes).length === 0) && (
                            <span className="text-gray-400">-</span>
                          )}
                        </td>
                        <td className="px-5 py-2.5 font-mono text-xs text-gray-600">{v.sku || '-'}</td>
                        <td className="px-5 py-2.5 font-mono text-xs text-gray-600">{v.barcode || '-'}</td>
                        <td className="px-5 py-2.5 text-right">
                          {v.discounted_price && parseFloat(v.discounted_price) > 0 ? (
                            <div>
                              <span className="text-gray-400 line-through text-xs">{formatPrice(v.price, product.currency)}</span>
                              {' '}
                              <span className="font-medium text-green-700">{formatPrice(v.discounted_price, product.currency)}</span>
                            </div>
                          ) : (
                            <span className="font-medium">{formatPrice(v.price, product.currency)}</span>
                          )}
                        </td>
                        <td className="px-5 py-2.5 text-right">
                          <span className={clsx('font-medium', v.stock_quantity > 0 ? 'text-gray-900' : 'text-red-600')}>
                            {v.stock_quantity}
                          </span>
                        </td>
                        <td className="px-5 py-2.5 text-center">
                          <span className={clsx(
                            'inline-flex px-2 py-0.5 text-xs rounded-full',
                            v.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
                          )}>
                            {v.is_active ? t('common.active') : t('common.inactive')}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

function InfoRow({
  label,
  value,
  copyable,
  onCopy,
  copiedField,
  copyTitle,
}: {
  label: string
  value: string | null
  copyable?: boolean
  onCopy?: (text: string, field: string) => void
  copiedField?: string | null
  copyTitle?: string
}) {
  return (
    <div>
      <div className="text-xs text-gray-500 mb-0.5">{label}</div>
      <div className="flex items-center gap-1.5">
        <span className="text-gray-900 font-medium">{value || '-'}</span>
        {copyable && value && onCopy && (
          <button
            onClick={() => onCopy(value, label)}
            className="text-gray-400 hover:text-gray-600 transition-colors"
            title={copyTitle}
          >
            {copiedField === label ? <Check size={12} className="text-green-500" /> : <Copy size={12} />}
          </button>
        )}
      </div>
    </div>
  )
}
