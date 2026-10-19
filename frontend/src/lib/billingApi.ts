import api from '@/lib/api'

export interface PlanInfo {
  code: string
  name: string
  description: string | null
  products: string[]
  limits: Record<string, number>
  features: string[]
  price_monthly: number
  price_yearly: number
  currency: string
  trial_days: number
  highlight: boolean
}

export interface SubscriptionInfo {
  status: 'trialing' | 'active' | 'past_due' | 'canceled' | 'expired'
  grants_access: boolean
  billing_cycle: 'monthly' | 'yearly'
  plan: PlanInfo | null
  trial_ends_at: string | null
  current_period_start: string | null
  current_period_end: string | null
  cancel_at_period_end: boolean
  ends_at: string | null
  grace_ends_at: string | null
  days_left: number | null
  gateway: string | null
  has_card: boolean
  card: { last4: string | null; association: string | null; bank: string | null } | null
  price: number
  currency: string
  last_payment_at: string | null
}

export interface InvoiceInfo {
  id: number
  number: string
  status: 'open' | 'paid' | 'failed' | 'void'
  description: string | null
  subtotal: number
  tax_rate: number
  tax_amount: number
  total: number
  currency: string
  period_start: string | null
  period_end: string | null
  due_at: string | null
  paid_at: string | null
  gateway: string | null
  failure_reason: string | null
  created_at: string | null
}

export interface BillingProfile {
  type: 'company' | 'individual'
  legal_name: string
  tax_office?: string | null
  tax_number?: string | null
  email?: string | null
  phone?: string | null
  address: string
  district?: string | null
  city: string
  postal_code?: string | null
}

export interface BillingOverview {
  subscription: SubscriptionInfo | null
  plans: PlanInfo[]
  invoices: InvoiceInfo[]
  profile: (BillingProfile & { id: number }) | null
  usage: Record<string, { label: string; used: number; limit: number | null; ratio: number | null }>
  gateway_ready: boolean
  bank_transfer: { enabled: boolean; bank: string; iban: string; holder: string; note: string }
  can_manage: boolean
  email_verified: boolean
}

export interface OnboardingStep {
  key: string
  title: string
  desc: string
  to: string
  done: boolean
}

export const billingApi = {
  overview: () => api.get<BillingOverview>('/billing/overview').then((r) => r.data),
  saveProfile: (p: BillingProfile) => api.put('/billing/profile', p).then((r) => r.data),
  checkout: (plan: string, cycle: 'monthly' | 'yearly') =>
    api.post<{ payment_page_url: string; invoice: InvoiceInfo }>('/billing/checkout', { plan, cycle }).then((r) => r.data),
  bankTransfer: (plan: string, cycle: 'monthly' | 'yearly') =>
    api.post<{ invoice: InvoiceInfo; bank: BillingOverview['bank_transfer'] }>('/billing/bank-transfer', { plan, cycle }).then((r) => r.data),
  cancel: () => api.post<SubscriptionInfo>('/billing/cancel').then((r) => r.data),
  resume: () => api.post<SubscriptionInfo>('/billing/resume').then((r) => r.data),
  resendVerification: () => api.post('/auth/resend-verification').then((r) => r.data),
  onboarding: () => api.get<{ steps: OnboardingStep[]; done: boolean; slug: string; onboarded_at: string | null }>('/onboarding/status').then((r) => r.data),
  dismissOnboarding: () => api.post('/onboarding/dismiss').then((r) => r.data),
  publicPlans: () => api.get<{ plans: PlanInfo[]; trial_days: number; yearly_discount: number; tax_rate: number }>('/public/plans').then((r) => r.data),
  signup: (p: { company: string; name: string; email: string; phone?: string; password: string; platform?: string; terms: boolean; website?: string }) =>
    api.post<{ token: string; user: unknown }>('/public/signup', p).then((r) => r.data),
  forgot: (email: string) => api.post('/public/forgot-password', { email }).then((r) => r.data),
  reset: (p: { email: string; token: string; password: string; password_confirmation: string }) => api.post('/public/reset-password', p).then((r) => r.data),
  admin: {
    list: () => api.get('/admin/subscriptions').then((r) => r.data),
    activate: (tenantId: number, p: { plan: string; cycle: 'monthly' | 'yearly'; days: number; invoice_id?: number }) => api.post(`/admin/subscriptions/${tenantId}/activate`, p).then((r) => r.data),
    extendTrial: (tenantId: number, days: number) => api.post(`/admin/subscriptions/${tenantId}/extend-trial`, { days }).then((r) => r.data),
    lock: (tenantId: number) => api.post(`/admin/subscriptions/${tenantId}/lock`).then((r) => r.data),
  },
}

export const fmtMoney = (v: number, currency = 'TRY') =>
  new Intl.NumberFormat('tr-TR', { style: 'currency', currency, maximumFractionDigits: 0 }).format(v)
export const fmtDate = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString('tr-TR', { day: '2-digit', month: 'long', year: 'numeric' }) : '—')
export const STATUS_LABEL: Record<string, string> = {
  trialing: 'Deneme', active: 'Aktif', past_due: 'Ödeme bekliyor', canceled: 'İptal edildi', expired: 'Süresi doldu',
}
export const STATUS_TONE: Record<string, string> = {
  trialing: 'bg-sky-50 text-sky-700', active: 'bg-emerald-50 text-emerald-700', past_due: 'bg-amber-50 text-amber-700', canceled: 'bg-gray-100 text-gray-600', expired: 'bg-red-50 text-red-700',
}
