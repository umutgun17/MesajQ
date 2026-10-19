import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import type { CrmCustomer, CrmOrder, Conversation, PaginatedResponse } from '@/types'
import { ArrowLeft, Mail, Phone, MapPin, ShoppingBag, CreditCard, Star } from 'lucide-react'
import clsx from 'clsx'
import { getChannelColor, getChannelLabel } from '@/lib/channelUtils'

export default function CrmCustomerDetailPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [customer, setCustomer] = useState<CrmCustomer | null>(null)
  const [orders, setOrders] = useState<CrmOrder[]>([])
  const [loading, setLoading] = useState(true)

  const locale = i18n.language === 'tr' ? 'tr-TR' : 'en-US'

  const genderLabels: Record<number, string> = {
    0: t('crm.customers.notSpecified'),
    1: t('crm.customers.male'),
    2: t('crm.customers.female'),
  }

  useEffect(() => {
    api.get<CrmCustomer>(`/crm/customers/${id}`).then((res) => {
      setCustomer(res.data)
      setLoading(false)
      // Fetch orders for this customer
      api.get<PaginatedResponse<CrmOrder>>('/crm/orders', {
        params: { customer_id: res.data.id, per_page: '10' }
      }).then((ordersRes) => {
        setOrders(ordersRes.data.data)
      }).catch(() => {})
    }).catch(() => {
      navigate('/crm/customers')
    })
  }, [id, navigate])

  const formatCurrency = (val: number) =>
    new Intl.NumberFormat(locale, { style: 'currency', currency: 'TRY' }).format(val)

  const formatDate = (date: string | null) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString(locale, {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    })
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

  if (!customer) return null

  return (
    <div className="space-y-6">
      {/* Back Button */}
      <button
        onClick={() => navigate('/crm/customers')}
        className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 transition-colors"
      >
        <ArrowLeft size={16} />
        {t('crm.customers.backToMembers')}
      </button>

      {/* Header */}
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-4">
          <div className="w-14 h-14 bg-indigo-100 rounded-xl flex items-center justify-center text-lg font-semibold text-indigo-700">
            {customer.name?.charAt(0).toUpperCase()}{customer.surname?.charAt(0).toUpperCase() || ''}
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-gray-900">
              {customer.name} {customer.surname || ''}
            </h1>
            <div className="flex items-center gap-3 mt-1">
              <span className={clsx(
                'inline-flex px-2 py-0.5 text-xs font-medium rounded-full',
                customer.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
              )}>
                {customer.is_active ? t('common.active') : t('common.inactive')}
              </span>
              {customer.member_type && (
                <span className="text-xs text-gray-500">{customer.member_type}</span>
              )}
              {customer.channel && (
                <span className="text-xs text-gray-500">{customer.channel.name}</span>
              )}
            </div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left column */}
        <div className="lg:col-span-1 space-y-6">
          {/* Contact Info */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 className="text-sm font-semibold text-gray-900 mb-4">{t('crm.customers.contactInfo')}</h2>
            <div className="space-y-3">
              {customer.email && (
                <div className="flex items-center gap-3 text-sm">
                  <Mail size={16} className="text-gray-400 shrink-0" />
                  <a href={`mailto:${customer.email}`} className="text-indigo-600 hover:underline">{customer.email}</a>
                </div>
              )}
              {customer.phone && (
                <div className="flex items-center gap-3 text-sm">
                  <Phone size={16} className="text-gray-400 shrink-0" />
                  <a href={`tel:${customer.phone}`} className="text-gray-700">{customer.phone}</a>
                </div>
              )}
              {(customer.city || customer.district) && (
                <div className="flex items-center gap-3 text-sm">
                  <MapPin size={16} className="text-gray-400 shrink-0" />
                  <span className="text-gray-700">
                    {[customer.district, customer.city].filter(Boolean).join(', ')}
                  </span>
                </div>
              )}
            </div>
          </div>

          {/* Member Details */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 className="text-sm font-semibold text-gray-900 mb-4">{t('crm.customers.memberInfo')}</h2>
            <div className="space-y-3 text-sm">
              <InfoRow label={t('crm.customers.gender')} value={customer.gender ? genderLabels[customer.gender] || '-' : '-'} />
              <InfoRow label={t('crm.customers.birthDate')} value={customer.birth_date ? new Date(customer.birth_date).toLocaleDateString(locale) : '-'} />
              <InfoRow label={t('crm.customers.membershipDate')} value={formatDate(customer.platform_created_at)} />
              <InfoRow label={t('crm.customers.lastLogin')} value={formatDate(customer.last_login_at)} />
              <InfoRow label={t('crm.customers.mailConsent')} value={customer.mail_consent ? t('crm.customers.yes') : t('crm.customers.no')} />
              <InfoRow label={t('crm.customers.smsConsent')} value={customer.sms_consent ? t('crm.customers.yes') : t('crm.customers.no')} />
            </div>
          </div>
        </div>

        {/* Right column */}
        <div className="lg:col-span-2 space-y-6">
          {/* Stats */}
          <div className="grid grid-cols-3 gap-4">
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <div className="flex items-center gap-2 text-gray-500 mb-2">
                <ShoppingBag size={16} />
                <span className="text-xs">{t('crm.customers.totalOrders')}</span>
              </div>
              <div className="text-2xl font-semibold text-gray-900">{customer.total_orders}</div>
            </div>
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <div className="flex items-center gap-2 text-gray-500 mb-2">
                <CreditCard size={16} />
                <span className="text-xs">{t('crm.customers.totalSpent')}</span>
              </div>
              <div className="text-2xl font-semibold text-gray-900">{formatCurrency(customer.total_spent)}</div>
            </div>
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
              <div className="flex items-center gap-2 text-gray-500 mb-2">
                <Star size={16} />
                <span className="text-xs">{t('crm.customers.loyaltyPoints')}</span>
              </div>
              <div className="text-2xl font-semibold text-gray-900">
                {customer.loyalty_points > 0 ? customer.loyalty_points.toLocaleString(locale, { maximumFractionDigits: 2 }) : '0'}
              </div>
            </div>
          </div>

          {/* Orders */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-5 pb-3 flex items-center justify-between">
              <h2 className="text-sm font-semibold text-gray-900">
                {t('crm.customers.ordersSection')}
                {orders.length > 0 && (
                  <span className="text-gray-400 font-normal"> ({orders.length})</span>
                )}
              </h2>
              {orders.length > 0 && (
                <Link
                  to={`/crm/orders?customer_id=${customer.id}`}
                  className="text-xs text-indigo-600 hover:underline"
                >
                  {t('crm.customers.viewAll')}
                </Link>
              )}
            </div>
            {orders.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-y border-gray-100 text-left">
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.orders.orderNo')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.orders.statusLabel')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500 text-right">{t('crm.customers.amount')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500 text-center">{t('crm.orders.items')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.orders.date')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {orders.map((order) => (
                      <tr
                        key={order.id}
                        onClick={() => navigate(`/crm/orders/${order.id}`)}
                        className="border-b border-gray-50 hover:bg-gray-50 cursor-pointer transition-colors"
                      >
                        <td className="px-5 py-2.5 font-medium text-gray-900">{order.order_no || '-'}</td>
                        <td className="px-5 py-2.5">
                          <span className={clsx(
                            'inline-flex px-2 py-0.5 text-xs font-medium rounded-full',
                            order.status <= 1 ? 'bg-blue-100 text-blue-700' :
                            order.status <= 3 ? 'bg-yellow-100 text-yellow-700' :
                            order.status === 4 ? 'bg-purple-100 text-purple-700' :
                            order.status === 5 ? 'bg-green-100 text-green-700' :
                            'bg-red-100 text-red-700'
                          )}>
                            {getStatusLabel(order.status, order.status_label)}
                          </span>
                        </td>
                        <td className="px-5 py-2.5 text-right font-medium text-gray-900">{formatCurrency(parseFloat(order.total_amount))}</td>
                        <td className="px-5 py-2.5 text-center text-gray-600">{order.items_count}</td>
                        <td className="px-5 py-2.5 text-xs text-gray-500 whitespace-nowrap">
                          {order.order_date ? new Date(order.order_date).toLocaleDateString(locale, {
                            day: '2-digit', month: '2-digit', year: 'numeric',
                          }) : '-'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-8 text-center text-gray-400 text-sm">
                {t('crm.customers.noOrdersForMember')}
              </div>
            )}
          </div>

          {/* Conversations */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-5 pb-3">
              <h2 className="text-sm font-semibold text-gray-900">
                {t('crm.customers.conversationHistory')}
                {customer.conversations && (
                  <span className="text-gray-400 font-normal"> ({customer.conversations.length})</span>
                )}
              </h2>
            </div>
            {customer.conversations && customer.conversations.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-y border-gray-100 text-left">
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.customers.channel')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.orders.statusLabel')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.customers.lastMessage')}</th>
                      <th className="px-5 py-2.5 font-medium text-gray-500">{t('crm.orders.date')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {customer.conversations.map((conv: Conversation) => (
                      <tr
                        key={conv.id}
                        onClick={() => navigate(`/messages?conversation=${conv.id}`)}
                        className="border-b border-gray-50 hover:bg-gray-50 cursor-pointer transition-colors"
                      >
                        <td className="px-5 py-2.5">
                          {conv.channel ? (
                            <span className={clsx(
                              'inline-flex px-2 py-0.5 text-xs font-medium rounded-full',
                              getChannelColor(conv.channel.type)
                            )}>
                              {getChannelLabel(conv.channel.type)}
                            </span>
                          ) : '-'}
                        </td>
                        <td className="px-5 py-2.5">
                          <span className={clsx(
                            'inline-flex px-2 py-0.5 text-xs font-medium rounded-full',
                            conv.status === 'open' && 'bg-blue-100 text-blue-700',
                            conv.status === 'pending' && 'bg-yellow-100 text-yellow-700',
                            conv.status === 'resolved' && 'bg-green-100 text-green-700',
                            conv.status === 'closed' && 'bg-gray-100 text-gray-500',
                          )}>
                            {conv.status === 'open' ? t('crm.customers.open') : conv.status === 'pending' ? t('crm.customers.waiting') : conv.status === 'resolved' ? t('crm.customers.resolved') : t('crm.customers.closed')}
                          </span>
                        </td>
                        <td className="px-5 py-2.5 text-gray-600 max-w-xs truncate">
                          {conv.latest_message?.content || '-'}
                        </td>
                        <td className="px-5 py-2.5 text-xs text-gray-500 whitespace-nowrap">
                          {conv.last_message_at ? new Date(conv.last_message_at).toLocaleDateString(locale, {
                            day: '2-digit', month: '2-digit', year: 'numeric',
                            hour: '2-digit', minute: '2-digit',
                          }) : '-'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-8 text-center text-gray-400 text-sm">
                {t('crm.customers.noConversationsForMember')}
              </div>
            )}
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
