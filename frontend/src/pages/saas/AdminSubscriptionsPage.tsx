import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { billingApi, fmtDate, fmtMoney, STATUS_LABEL, STATUS_TONE, type PlanInfo, type SubscriptionInfo } from '@/lib/billingApi'
import { useAuth } from '@/contexts/AuthContext'

type Row = SubscriptionInfo & { tenant_id: number; tenant: string; slug: string; owner_email: string | null; created_at: string | null }
type OpenInvoice = { id: number; number: string; tenant: string; tenant_id: number; total: number; currency: string; created_at: string | null }

/** Süper admin: tüm müşteri abonelikleri, havale faturaları, elle plan atama. */
export default function AdminSubscriptionsPage() {
  const { user } = useAuth()
  const [rows, setRows] = useState<Row[]>([])
  const [open, setOpen] = useState<OpenInvoice[]>([])
  const [plans, setPlans] = useState<PlanInfo[]>([])
  const [totals, setTotals] = useState<Record<string, number>>({})
  const [q, setQ] = useState('')
  const [busy, setBusy] = useState(0)

  const load = () => billingApi.admin.list().then((d) => { setRows(d.subscriptions); setOpen(d.open_bank_invoices); setPlans(d.plans); setTotals(d.totals) })
  useEffect(() => { load().catch(() => {}) }, [])
  if (!user?.is_super_admin) return <div className="p-10 text-center text-gray-400">Yetkisiz.</div>

  const activate = async (r: Row, invoiceId?: number) => {
    const plan = prompt('Plan kodu:\n' + plans.map((p) => `${p.code} — ${p.name}`).join('\n'), r.plan?.code ?? plans[0]?.code)
    if (!plan) return
    const days = Number(prompt('Kaç gün?', r.billing_cycle === 'yearly' ? '365' : '30') || 0)
    if (!days) return
    setBusy(r.tenant_id)
    try { await billingApi.admin.activate(r.tenant_id, { plan, cycle: days >= 360 ? 'yearly' : 'monthly', days, invoice_id: invoiceId }); await load() } finally { setBusy(0) }
  }
  const extend = async (r: Row) => { const d = Number(prompt('Deneme kaç gün uzasın?', '7') || 0); if (!d) return; setBusy(r.tenant_id); try { await billingApi.admin.extendTrial(r.tenant_id, d); await load() } finally { setBusy(0) } }
  const lock = async (r: Row) => { if (!confirm(`${r.tenant} kilitlensin mi?`)) return; setBusy(r.tenant_id); try { await billingApi.admin.lock(r.tenant_id); await load() } finally { setBusy(0) } }

  const filtered = rows.filter((r) => !q || r.tenant.toLowerCase().includes(q.toLowerCase()) || (r.owner_email ?? '').includes(q.toLowerCase()))

  return (
    <div className="max-w-6xl mx-auto space-y-6">
      <div><h1 className="text-2xl font-extrabold text-gray-900">Abonelikler</h1><p className="text-sm text-gray-500 mt-1">Müşteri hesapları, deneme süreleri, havale onayları.</p></div>
      <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
        {[['trialing', 'Deneme'], ['active', 'Aktif'], ['past_due', 'Ödeme bekliyor'], ['expired', 'Süresi dolmuş']].map(([k, l]) => (
          <div key={k} className="bg-white rounded-xl border border-gray-100 p-4"><p className="text-xs text-gray-400">{l}</p><p className="text-2xl font-black">{totals[k] ?? 0}</p></div>
        ))}
        <div className="bg-white rounded-xl border border-gray-100 p-4"><p className="text-xs text-gray-400">MRR (KDV hariç)</p><p className="text-2xl font-black">{fmtMoney(totals.mrr ?? 0)}</p></div>
      </div>

      {open.length > 0 && (
        <div className="bg-amber-50 border border-amber-200 rounded-xl p-4">
          <h2 className="text-sm font-bold text-amber-900 mb-2">Onay bekleyen havale faturaları</h2>
          {open.map((i) => {
            const row = rows.find((r) => r.tenant_id === i.tenant_id)
            return (
              <div key={i.id} className="flex flex-wrap items-center gap-3 text-sm py-1.5 border-t border-amber-100">
                <span className="font-mono text-xs">{i.number}</span><span className="font-semibold">{i.tenant}</span><span>{fmtMoney(i.total, i.currency)}</span><span className="text-xs text-amber-700">{fmtDate(i.created_at)}</span>
                {row && <button onClick={() => activate(row, i.id)} className="ml-auto px-3 py-1 rounded-lg bg-amber-600 text-white text-xs font-semibold">Ödendi → aktive et</button>}
              </div>
            )
          })}
        </div>
      )}

      <div className="bg-white rounded-xl border border-gray-100 p-4">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Firma / e-posta ara" className="w-full sm:w-72 px-3 py-2 bg-[var(--app-bg)] rounded-lg text-sm mb-3" />
        <table className="w-full text-sm">
          <thead><tr className="text-left text-xs text-gray-400"><th className="py-1.5">Firma</th><th>Sahip</th><th>Plan</th><th>Durum</th><th>Bitiş</th><th>Ödeme</th><th></th></tr></thead>
          <tbody>
            {filtered.map((r) => (
              <tr key={r.tenant_id} className={clsx('border-t border-gray-100', busy === r.tenant_id && 'opacity-50')}>
                <td className="py-2"><p className="font-semibold">{r.tenant}</p><p className="text-[11px] text-gray-400">{r.slug} · {fmtDate(r.created_at)}</p></td>
                <td className="text-xs text-gray-600">{r.owner_email}</td>
                <td>{r.plan?.name ?? '—'}<span className="block text-[11px] text-gray-400">{r.billing_cycle === 'yearly' ? 'yıllık' : 'aylık'}</span></td>
                <td><span className={clsx('px-2 py-0.5 rounded-full text-[11px] font-bold', STATUS_TONE[r.status])}>{STATUS_LABEL[r.status]}</span>{r.cancel_at_period_end && <span className="block text-[10px] text-amber-600">dönem sonu iptal</span>}</td>
                <td className="text-xs">{fmtDate(r.status === 'trialing' ? r.trial_ends_at : r.current_period_end)}{r.days_left !== null && <span className="text-gray-400"> ({r.days_left} g)</span>}</td>
                <td className="text-xs">{r.has_card ? `•••• ${r.card?.last4}` : r.gateway === 'manual' ? 'Havale' : '—'}</td>
                <td className="text-right whitespace-nowrap">
                  <button onClick={() => activate(r)} className="text-xs font-semibold text-[var(--primary-strong)] mr-2">Plan ata</button>
                  {r.status === 'trialing' && <button onClick={() => extend(r)} className="text-xs font-semibold text-sky-600 mr-2">Uzat</button>}
                  {r.grants_access && <button onClick={() => lock(r)} className="text-xs font-semibold text-red-500">Kilitle</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
