import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import { storefrontUrl } from '@/lib/tenant'
import type { CrmOrder } from '@/types'
import { ArrowLeft, ExternalLink, Package, Truck, CreditCard, MapPin, User, Copy, Check } from 'lucide-react'
import clsx from 'clsx'

export default function CrmOrderDetailPage() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const storeUrl = storefrontUrl(user)
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [order, setOrder] = useState<CrmOrder | null>(null)
  const [loading, setLoading] = useState(true)
  const [copied, setCopied] = useState<string | null>(null)

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  useEffect(() => {
    api.get<CrmOrder>(`/crm/orders/${id}`).then((res) => {
      setOrder(res.data)
      setLoading(false)
    }).catch(() => {
      navigate('/crm/orders')
    })
  }, [id, navigate])

  const formatCurrency = (val: string | number) => {
    const num = typeof val === 'string' ? parseFloat(val) : val
    return new Intl.NumberFormat(locale, { style: 'currency', currency: 'TRY' }).format(num)
  }

  const formatDate = (date: string | null) => {
    if (!date) return '-'
    // Dates come as "Y-m-d H:i:s" without timezone (Turkey local)
    const d = new Date(date.replace(' ', 'T'))
    return d.toLocaleDateString(locale, {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    })
  }

  const copyToClipboard = (text: string, key: string) => {
    navigator.clipboard.writeText(text)
    setCopied(key)
    setTimeout(() => setCopied(null), 1500)
  }

  const getStatusColor = (status: number) => {
    switch (status) {
      case 0: return 'bg-gray-100 text-gray-600'
      case 4: return 'bg-yellow-100 text-yellow-700'
      case 5: return 'bg-blue-100 text-blue-700'
      case 6: return 'bg-purple-100 text-purple-700'
      case 7: return 'bg-green-100 text-green-700'
      case 8: return 'bg-red-100 text-red-700'
      case 11: return 'bg-orange-100 text-orange-700'
      case 12: return 'bg-orange-100 text-orange-700'
      case 13: return 'bg-red-100 text-red-600'
      case 20: return 'bg-indigo-100 text-indigo-700'
      case 21: return 'bg-teal-100 text-teal-700'
      default: return 'bg-gray-100 text-gray-600'
    }
  }

  const getStatusLabel = (status: number, fallbackLabel?: string | null) => {
    const key = `crm.orders.statuses.${status}`
    const translated = t(key)
    if (translated !== key) return translated
    return fallbackLabel || `${t('crm.orders.statusLabel')} ${status}`
  }

  if (loading) {
    return <div className="p-12 text-center text-gray-500">{t('common.loading')}</div>
  }

  if (!order) return null

  const formatAddress = (addr: Record<string, string | null> | null) => {
    if (!addr) return null
    const parts = [
      addr.address,
      [addr.neighborhood, addr.district, addr.city].filter(Boolean).join(', '),
      addr.postal_code,
      addr.country,
    ].filter(Boolean)
    return parts.join('\n')
  }

  return (
    <div className="space-y-6">
      {/* Back Button */}
      <button
        onClick={() => navigate('/crm/orders')}
        className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 transition-colors"
      >
        <ArrowLeft size={16} />
        {t('crm.orders.backToOrders')}
      </button>

      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-xl font-extrabold text-gray-900">
              {t('crm.orders.order')} #{order.order_no || order.platform_order_id}
            </h1>
            <span className={clsx(
              'inline-flex px-2.5 py-1 text-xs font-medium rounded-full',
              getStatusColor(order.status)
            )}>
              {getStatusLabel(order.status, order.status_label)}
            </span>
          </div>
          <div className="flex items-center gap-4 mt-1 text-sm text-gray-500">
            <span>{formatDate(order.order_date)}</span>
            {order.order_source && <span>{t('crm.orders.source')}: {order.order_source}</span>}
            {order.channel && <span>{order.channel.name}</span>}
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left column */}
        <div className="lg:col-span-2 space-y-6">
          {/* Order Items */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-5 pb-3">
              <h2 className="text-sm font-semibold text-gray-900 flex items-center gap-2">
                <Package size={16} className="text-gray-400" />
                {t('crm.orders.orderItems')}
                <span className="text-gray-400 font-normal">({order.items_count})</span>
              </h2>
            </div>
            {order.items && order.items.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-y border-gray-100 text-left">
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.orders.product')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.products.sku')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500 text-center">{t('crm.orders.quantity')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500 text-right">{t('crm.orders.unitPrice')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500 text-right">{t('crm.orders.total')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.orders.statusLabel')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {order.items.map((item) => (
                      <tr key={item.id} className="border-b border-gray-50">
                        <td className="px-5 py-2.5">
                          <div className="flex items-center gap-3">
                            {item.image_url ? (
                              <img
                                src={item.image_url}
                                alt={item.product_name || ''}
                                className="w-10 h-10 rounded-lg object-cover border border-gray-100 flex-shrink-0"
                                onError={(e) => { (e.target as HTMLImageElement).style.display = 'none' }}
                              />
                            ) : (
                              <div className="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                <Package size={16} className="text-gray-300" />
                              </div>
                            )}
                            <div>
                              <div className="font-medium text-gray-900">
                                {item.product_id ? (
                                  <Link
                                    to={`/crm/products/${item.product_id}`}
                                    className="text-indigo-600 hover:underline"
                                    onClick={(e) => e.stopPropagation()}
                                  >
                                    {item.product_name || '-'}
                                  </Link>
                                ) : (
                                  item.product_name || '-'
                                )}
                              </div>
                              {item.variant_info && (
                                <div className="text-[11px] text-gray-400">{item.variant_info}</div>
                              )}
                            </div>
                          </div>
                        </td>
                        <td className="px-5 py-2.5 text-xs text-gray-500">{item.sku || '-'}</td>
                        <td className="px-5 py-2.5 text-center text-gray-900">{item.quantity}</td>
                        <td className="px-5 py-2.5 text-right text-gray-600">{formatCurrency(item.unit_price)}</td>
                        <td className="px-5 py-2.5 text-right font-medium text-gray-900">{formatCurrency(item.total_price)}</td>
                        <td className="px-5 py-2.5">
                          {item.status_label ? (
                            <span className="text-xs text-gray-500">{item.status_label}</span>
                          ) : '-'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-8 text-center text-gray-400 text-sm">
                {t('crm.orders.orderItemsNotLoaded')}
              </div>
            )}
          </div>

          {/* Addresses */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {order.shipping_address && (
              <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h2 className="text-sm font-semibold text-gray-900 flex items-center gap-2 mb-3">
                  <Truck size={16} className="text-gray-400" />
                  {t('crm.orders.shippingAddress')}
                </h2>
                <div className="text-sm text-gray-700 space-y-1">
                  {order.shipping_address.name && (
                    <div className="font-medium">{order.shipping_address.name}</div>
                  )}
                  {order.shipping_address.phone && <div className="text-gray-500">{order.shipping_address.phone}</div>}
                  <div className="whitespace-pre-line text-gray-600">{formatAddress(order.shipping_address)}</div>
                </div>
              </div>
            )}
            {order.billing_address && (
              <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h2 className="text-sm font-semibold text-gray-900 flex items-center gap-2 mb-3">
                  <MapPin size={16} className="text-gray-400" />
                  {t('crm.orders.billingAddress')}
                </h2>
                <div className="text-sm text-gray-700 space-y-1">
                  {order.billing_address.name && (
                    <div className="font-medium">{order.billing_address.name}</div>
                  )}
                  {order.billing_address.phone && <div className="text-gray-500">{order.billing_address.phone}</div>}
                  <div className="whitespace-pre-line text-gray-600">{formatAddress(order.billing_address)}</div>
                  {order.billing_address.tax_office && (
                    <div className="text-xs text-gray-500 pt-1">
                      {t('crm.orders.taxOffice')}: {order.billing_address.tax_office} / {order.billing_address.tax_no || '-'}
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Right column */}
        <div className="space-y-6">
          {/* Amount Summary */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 className="text-sm font-semibold text-gray-900 flex items-center gap-2 mb-4">
              <CreditCard size={16} className="text-gray-400" />
              {t('crm.orders.amountSummary')}
            </h2>
            <div className="space-y-2 text-sm">
              <AmountRow label={t('crm.orders.subtotal')} value={formatCurrency(order.subtotal)} />
              {parseFloat(order.discount_amount) > 0 && (
                <AmountRow label={t('crm.orders.discount')} value={`-${formatCurrency(order.discount_amount)}`} className="text-red-600" />
              )}
              {parseFloat(order.gift_card_amount) > 0 && (
                <AmountRow label={t('crm.orders.giftCard')} value={`-${formatCurrency(order.gift_card_amount)}`} className="text-red-600" />
              )}
              {parseFloat(order.points_discount) > 0 && (
                <AmountRow label={t('crm.orders.pointsDiscount')} value={`-${formatCurrency(order.points_discount)}`} className="text-red-600" />
              )}
              <AmountRow label={t('crm.orders.shipping')} value={formatCurrency(order.shipping_cost)} />
              <AmountRow label={t('crm.orders.tax')} value={formatCurrency(order.tax_amount)} />
              <div className="border-t border-gray-100 pt-2 mt-2">
                <AmountRow label={t('crm.orders.total')} value={formatCurrency(order.total_amount)} className="font-semibold text-gray-900" bold />
              </div>
            </div>
          </div>

          {/* Customer Card */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 className="text-sm font-semibold text-gray-900 flex items-center gap-2 mb-3">
              <User size={16} className="text-gray-400" />
              {t('crm.orders.customer')}
            </h2>
            <div className="space-y-2 text-sm">
              <div className="font-medium text-gray-900">
                {order.customer_id ? (
                  <Link
                    to={`/crm/customers/${order.customer_id}`}
                    className="text-indigo-600 hover:underline"
                  >
                    {order.customer_name || '-'}
                  </Link>
                ) : (
                  order.customer_name || '-'
                )}
              </div>
              {(() => {
                const phone = order.shipping_address?.phone || order.billing_address?.phone
                return phone ? (
                  <div className="flex items-center gap-1.5 text-gray-600">
                    <span>{phone}</span>
                    <button
                      onClick={() => copyToClipboard(phone, 'phone')}
                      className="text-gray-400 hover:text-gray-600"
                    >
                      {copied === 'phone' ? <Check size={12} className="text-green-500" /> : <Copy size={12} />}
                    </button>
                  </div>
                ) : null
              })()}
              {order.customer_email && (
                <div className="flex items-center gap-1.5 text-gray-500">
                  <span>{order.customer_email}</span>
                  <button
                    onClick={() => copyToClipboard(order.customer_email!, 'email')}
                    className="text-gray-400 hover:text-gray-600"
                  >
                    {copied === 'email' ? <Check size={12} className="text-green-500" /> : <Copy size={12} />}
                  </button>
                </div>
              )}
              {order.customer && (
                <>
                  {order.customer.member_type && (
                    <div className="text-xs text-gray-400">{t('crm.orders.membership')}: {order.customer.member_type}</div>
                  )}
                  {order.customer.gender != null && order.customer.gender > 0 && (
                    <div className="text-xs text-gray-400">
                      {t('crm.customers.gender')}: {order.customer.gender === 1 ? t('crm.customers.male') : order.customer.gender === 2 ? t('crm.customers.female') : `${t('crm.orders.other')} (${order.customer.gender})`}
                    </div>
                  )}
                </>
              )}
              {order.ip_address && (
                <div className="text-xs text-gray-400">IP: {order.ip_address}</div>
              )}
              {order.customer_id && (
                <Link
                  to={`/crm/customers/${order.customer_id}`}
                  className="inline-flex items-center gap-1 text-xs text-indigo-600 hover:underline mt-1"
                >
                  <ExternalLink size={12} />
                  {t('crm.orders.customerDetail')}
                </Link>
              )}
            </div>
          </div>

          {/* Cargo Info */}
          {(order.cargo_company || order.cargo_tracking_no) && (
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <h2 className="text-sm font-semibold text-gray-900 flex items-center gap-2 mb-3">
                <Truck size={16} className="text-gray-400" />
                {t('crm.orders.cargoInfo')}
              </h2>
              <div className="space-y-2 text-sm">
                {order.cargo_company && (
                  <div className="text-gray-700">{order.cargo_company}</div>
                )}
                {order.cargo_tracking_no && (
                  <div className="flex items-center gap-2">
                    <span className="text-gray-500 font-mono text-xs">{order.cargo_tracking_no}</span>
                    <button
                      onClick={() => copyToClipboard(order.cargo_tracking_no!, 'cargo')}
                      className="text-gray-400 hover:text-gray-600"
                      title={t('crm.products.copy')}
                    >
                      {copied === 'cargo' ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
                    </button>
                  </div>
                )}
                {order.cargo_tracking_url && (
                  <a
                    href={order.cargo_tracking_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 text-indigo-600 hover:underline text-xs"
                  >
                    <ExternalLink size={12} />
                    {t('crm.orders.cargoTracking')}
                  </a>
                )}
              </div>
            </div>
          )}

          {/* Payment Info */}
          {order.payments && order.payments.length > 0 && (
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <h2 className="text-sm font-semibold text-gray-900 flex items-center gap-2 mb-4">
                <CreditCard size={16} className="text-gray-400" />
                {t('crm.orders.paymentInfo')}
              </h2>
              <div className="space-y-4">
                {order.payments.map((payment, idx) => (
                  <div key={payment.id || idx} className={clsx(
                    'space-y-2 text-sm',
                    idx > 0 && 'border-t border-gray-100 pt-3'
                  )}>
                    <InfoRow label={t('crm.orders.total')} value={formatCurrency(payment.amount)} />
                    <InfoRow label={t('crm.orders.date')} value={formatDate(payment.date)} />
                    <div className="flex items-center justify-between">
                      <span className="text-gray-500">{t('crm.orders.statusLabel')}</span>
                      <span className={clsx(
                        'inline-flex px-2 py-0.5 text-xs font-medium rounded-full',
                        payment.approved
                          ? 'bg-green-50 text-green-700'
                          : 'bg-red-50 text-red-600'
                      )}>
                        {payment.approved ? t('crm.orders.approved') : t('crm.orders.failed')}
                      </span>
                    </div>
                    {payment.installments > 1 && (
                      <InfoRow label={t('crm.orders.installments')} value={t('crm.orders.installmentsCount', { count: payment.installments })} />
                    )}
                    {payment.pos_reference && (
                      <div className="flex items-center justify-between">
                        <span className="text-gray-500">{t('crm.orders.posReference')}</span>
                        <div className="flex items-center gap-1.5">
                          <span className="text-gray-900 font-mono text-xs">{payment.pos_reference}</span>
                          <button
                            onClick={() => copyToClipboard(payment.pos_reference!, `pos-${idx}`)}
                            className="text-gray-400 hover:text-gray-600"
                          >
                            {copied === `pos-${idx}` ? <Check size={12} className="text-green-500" /> : <Copy size={12} />}
                          </button>
                        </div>
                      </div>
                    )}
                    {payment.order_reference && (
                      <div className="flex items-center justify-between">
                        <span className="text-gray-500">{t('crm.orders.orderRef')}</span>
                        <div className="flex items-center gap-1.5">
                          <span className="text-gray-900 font-mono text-xs truncate max-w-[160px]" title={payment.order_reference}>{payment.order_reference}</span>
                          <button
                            onClick={() => copyToClipboard(payment.order_reference!, `ref-${idx}`)}
                            className="text-gray-400 hover:text-gray-600"
                          >
                            {copied === `ref-${idx}` ? <Check size={12} className="text-green-500" /> : <Copy size={12} />}
                          </button>
                        </div>
                      </div>
                    )}
                    {payment.bank_commission > 0 && (
                      <InfoRow label={t('crm.orders.bankCommission')} value={formatCurrency(payment.bank_commission)} />
                    )}
                    {payment.cod_amount > 0 && (
                      <InfoRow label={t('crm.orders.codPayment')} value={formatCurrency(payment.cod_amount)} />
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Order Details */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 className="text-sm font-semibold text-gray-900 mb-3">{t('crm.orders.orderInfo')}</h2>
            <div className="space-y-2 text-sm">
              <div className="flex items-center justify-between">
                <span className="text-gray-500">{t('crm.orders.ticimaxId')}</span>
                {storeUrl ? (
                  <a
                    href={`${storeUrl}/Admin/SiparisDetay.aspx?SID=${order.platform_order_id}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 text-indigo-600 hover:underline font-medium"
                  >
                    #{order.platform_order_id}
                    <ExternalLink size={12} />
                  </a>
                ) : (
                  <span className="font-medium text-gray-900">#{order.platform_order_id}</span>
                )}
              </div>
              <InfoRow label={t('crm.orders.orderNo')} value={order.order_no || '-'} />
              {order.order_code && <InfoRow label={t('crm.orders.orderCode')} value={order.order_code} />}
              <InfoRow label={t('crm.orders.orderDate')} value={formatDate(order.order_date)} />
              <InfoRow label={t('crm.orders.statusUpdate')} value={formatDate(order.status_updated_at)} />
              {order.order_source && <InfoRow label={t('crm.orders.source')} value={order.order_source} />}
              <InfoRow label={t('crm.orders.currency')} value={order.currency} />
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-gray-500">{label}</span>
      <span className="text-gray-900 font-medium">{value}</span>
    </div>
  )
}

function AmountRow({ label, value, className, bold }: { label: string; value: string; className?: string; bold?: boolean }) {
  return (
    <div className="flex items-center justify-between">
      <span className={clsx('text-gray-500', bold && 'font-semibold text-gray-900')}>{label}</span>
      <span className={clsx('text-gray-700', className)}>{value}</span>
    </div>
  )
}
