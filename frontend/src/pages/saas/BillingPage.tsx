import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Check, CreditCard, Landmark, AlertTriangle, RefreshCw } from 'lucide-react'
import clsx from 'clsx'
import { useAuth } from '@/contexts/AuthContext'
import { billingApi, fmtDate, fmtMoney, STATUS_LABEL, STATUS_TONE, type BillingOverview, type BillingProfile, type PlanInfo } from '@/lib/billingApi'
import { errorsOf, inputCls } from './AuthShell'

const card = 'bg-white rounded-xl shadow-sm border border-gray-100 p-5'
const emptyProfile: BillingProfile = { type: 'company', legal_name: '', tax_office: '', tax_number: '', email: '', phone: '', address: '', district: '', city: '', postal_code: '' }

export default function BillingPage() {
  const { refreshUser } = useAuth()
  const [params, setParams] = useSearchParams()
  const [data, setData] = useState<BillingOverview | null>(null)
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>('monthly')
  const [profile, setProfile] = useState<BillingProfile>(emptyProfile)
  const [profileOpen, setProfileOpen] = useState(false)
  const [busy, setBusy] = useState<string>('')
  const [flash, setFlash] = useState<{ tone: 'ok' | 'warn' | 'err'; text: string } | null>(null)
  const [bank, setBank] = useState<{ number: string; total: number; currency: string } | null>(null)

  const load = useCallback(async () => {
    const d = await billingApi.overview()
    setData(d)
    if (d.profile) setProfile({ ...emptyProfile, ...d.profile })
    if (d.subscription?.billing_cycle) setCycle(d.subscription.billing_cycle)
  }, [])

  useEffect(() => { load().catch(() => {}) }, [load])
  useEffect(() => {
    const p = params.get('payment')
    if (!p) return
    setFlash(p === 'success' ? { tone: 'ok', text: 'Ödemeniz alındı, aboneliğiniz aktif. Teşekkürler!' } : p === 'failed' ? { tone: 'err', text: 'Ödeme tamamlanamadı. Kart bilgilerinizi kontrol edip tekrar deneyin.' } : { tone: 'err', text: 'Ödeme doğrulanamadı. Destek ile iletişime geçin.' })
    params.delete('payment'); setParams(params, { replace: true })
    refreshUser().catch(() => {})
  }, [params, setParams, refreshUser])

  const saveProfile = async (e: FormEvent) => {
    e.preventDefault(); setBusy('profile')
    try { await billingApi.saveProfile(profile); await load(); setProfileOpen(false); setFlash({ tone: 'ok', text: 'Fatura bilgileri kaydedildi.' }) } catch (err) { setFlash({ tone: 'err', text: errorsOf(err) }) } finally { setBusy('') }
  }
  const checkout = async (plan: PlanInfo) => {
    if (!data?.profile) { setProfileOpen(true); setFlash({ tone: 'warn', text: 'Önce fatura bilgilerinizi girin.' }); return }
    setBusy(plan.code)
    try {
      const r = await billingApi.checkout(plan.code, cycle)
      window.location.href = r.payment_page_url
    } catch (err) {
      const e = err as { response?: { data?: { code?: string } } }
      if (e.response?.data?.code === 'profile_incomplete') setProfileOpen(true)
      setFlash({ tone: 'err', text: errorsOf(err) }); setBusy('')
    }
  }
  const bankTransfer = async (plan: PlanInfo) => {
    if (!data?.profile) { setProfileOpen(true); setFlash({ tone: 'warn', text: 'Önce fatura bilgilerinizi girin.' }); return }
    setBusy('bank-' + plan.code)
    try { const r = await billingApi.bankTransfer(plan.code, cycle); setBank({ number: r.invoice.number, total: r.invoice.total, currency: r.invoice.currency }); await load() } catch (err) { setFlash({ tone: 'err', text: errorsOf(err) }) } finally { setBusy('') }
  }
  const cancel = async () => {
    if (!confirm('Abonelik dönem sonunda kapatılacak. Devam edilsin mi?')) return
    setBusy('cancel'); try { await billingApi.cancel(); await load(); await refreshUser() } finally { setBusy('') }
  }
  const resume = async () => { setBusy('resume'); try { await billingApi.resume(); await load(); await refreshUser() } finally { setBusy('') } }

  if (!data) return <div className="p-10 text-center text-gray-400">Yükleniyor…</div>
  const sub = data.subscription
  const currentCode = sub?.plan?.code

  return (
    <div className="max-w-6xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-extrabold text-gray-900">Abonelik ve Faturalama</h1>
        <p className="text-sm text-gray-500 mt-1">Planınızı yönetin, ödeme yöntemi ekleyin, faturalarınızı görün.</p>
      </div>

      {flash && (
        <div className={clsx('rounded-lg px-4 py-3 text-sm border', flash.tone === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : flash.tone === 'warn' ? 'bg-amber-50 border-amber-200 text-amber-700' : 'bg-red-50 border-red-200 text-red-700')}>
          {flash.text}
        </div>
      )}

      {/* Mevcut durum */}
      <div className={card}>
        <div className="flex flex-wrap items-center gap-3">
          <span className={clsx('px-2.5 py-1 rounded-full text-xs font-bold', STATUS_TONE[sub?.status ?? 'expired'])}>{STATUS_LABEL[sub?.status ?? 'expired']}</span>
          <span className="text-lg font-extrabold text-gray-900">{sub?.plan?.name ?? 'Plan seçilmedi'}</span>
          {sub?.status === 'active' && <span className="text-sm text-gray-500">{fmtMoney(sub.price, sub.currency)} / {sub.billing_cycle === 'yearly' ? 'yıl' : 'ay'} + KDV</span>}
        </div>
        <div className="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
          {sub?.status === 'trialing' && <Info label="Deneme bitişi" value={`${fmtDate(sub.trial_ends_at)} (${sub.days_left} gün)`} />}
          {(sub?.status === 'active' || sub?.status === 'past_due') && <Info label="Dönem" value={`${fmtDate(sub.current_period_start)} – ${fmtDate(sub.current_period_end)}`} />}
          {sub?.status === 'past_due' && <Info label="Ödeme son günü" value={fmtDate(sub.grace_ends_at)} tone="warn" />}
          {sub?.status === 'canceled' && <Info label="Erişim bitişi" value={fmtDate(sub.ends_at)} />}
          {sub?.cancel_at_period_end && sub.status === 'active' && <Info label="İptal" value={`Dönem sonunda kapanacak (${fmtDate(sub.current_period_end)})`} tone="warn" />}
          <Info label="Ödeme yöntemi" value={sub?.has_card ? `${sub.card?.association ?? 'Kart'} •••• ${sub.card?.last4 ?? ''}` : sub?.gateway === 'manual' ? 'Havale / EFT' : 'Tanımlı değil'} />
        </div>
        {data.can_manage && sub && (sub.status === 'active' || sub.status === 'past_due') && (
          <div className="mt-4 flex gap-2">
            {sub.cancel_at_period_end
              ? <button onClick={resume} disabled={busy === 'resume'} className="text-sm font-semibold text-[var(--primary-strong)]">Aboneliği sürdür</button>
              : <button onClick={cancel} disabled={busy === 'cancel'} className="text-sm font-semibold text-gray-500 hover:text-red-600">Dönem sonunda iptal et</button>}
          </div>
        )}
        {!data.email_verified && (
          <div className="mt-4 flex items-center gap-2 text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
            <AlertTriangle size={14} /> E-posta adresiniz doğrulanmadı. Gelen kutunuzu kontrol edin.
            <button onClick={() => billingApi.resendVerification().then(() => setFlash({ tone: 'ok', text: 'Doğrulama e-postası gönderildi.' }))} className="ml-auto font-semibold underline">Yeniden gönder</button>
          </div>
        )}
      </div>

      {/* Kullanım */}
      {Object.keys(data.usage).length > 0 && (
        <div className={card}>
          <h2 className="text-sm font-bold text-gray-900 mb-3">Bu ayki kullanım</h2>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {Object.entries(data.usage).map(([k, u]) => (
              <div key={k}>
                <div className="flex justify-between text-xs text-gray-500"><span>{u.label}</span><span>{u.used.toLocaleString('tr-TR')}{u.limit ? ` / ${u.limit.toLocaleString('tr-TR')}` : ''}</span></div>
                <div className="mt-1 h-2 rounded-full bg-gray-100 overflow-hidden">
                  <div className={clsx('h-full rounded-full', (u.ratio ?? 0) >= 1 ? 'bg-red-500' : (u.ratio ?? 0) >= 0.8 ? 'bg-amber-400' : 'bg-[var(--primary)]')} style={{ width: `${Math.min(100, (u.ratio ?? 0) * 100)}%` }} />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Planlar */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-base font-bold text-gray-900">Planlar</h2>
          <div className="inline-flex rounded-lg bg-gray-100 p-0.5 text-xs font-semibold">
            <button onClick={() => setCycle('monthly')} className={clsx('px-3 py-1.5 rounded-md', cycle === 'monthly' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500')}>Aylık</button>
            <button onClick={() => setCycle('yearly')} className={clsx('px-3 py-1.5 rounded-md', cycle === 'yearly' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500')}>Yıllık <span className="text-emerald-600">−%25</span></button>
          </div>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
          {data.plans.map((p) => {
            const price = cycle === 'yearly' ? p.price_yearly / 12 : p.price_monthly
            const isCurrent = currentCode === p.code && sub?.status === 'active'
            return (
              <div key={p.code} className={clsx(card, 'flex flex-col', p.highlight && 'ring-2 ring-[var(--primary)]')}>
                {p.highlight && <span className="self-start text-[10px] font-bold uppercase tracking-wider text-[var(--primary-strong)] bg-[var(--primary-soft)] px-2 py-0.5 rounded-full mb-2">Önerilen</span>}
                <h3 className="text-lg font-extrabold text-gray-900">{p.name}</h3>
                <p className="text-xs text-gray-500 mt-1 min-h-[32px]">{p.description}</p>
                <div className="mt-3"><span className="text-2xl font-black text-gray-900">{fmtMoney(price, p.currency)}</span><span className="text-xs text-gray-500"> / ay + KDV</span>
                  {cycle === 'yearly' && <p className="text-[11px] text-gray-400">Yıllık {fmtMoney(p.price_yearly, p.currency)} tek ödeme</p>}
                </div>
                <ul className="mt-3 space-y-1.5 text-[13px] text-gray-600 flex-1">
                  {p.features.map((f) => <li key={f} className="flex gap-2"><Check size={14} className="text-emerald-500 mt-0.5 shrink-0" />{f}</li>)}
                </ul>
                {data.can_manage && (
                  <div className="mt-4 space-y-2">
                    <button onClick={() => checkout(p)} disabled={!!busy || isCurrent || !data.gateway_ready} title={!data.gateway_ready ? 'Kartla ödeme henüz aktif değil' : ''} className={clsx('w-full py-2 rounded-lg text-sm font-semibold flex items-center justify-center gap-2', isCurrent ? 'bg-gray-100 text-gray-400' : 'bg-[var(--primary)] text-white hover:opacity-90 disabled:opacity-50')}>
                      <CreditCard size={15} /> {isCurrent ? 'Mevcut plan' : busy === p.code ? 'Yönlendiriliyor…' : 'Kartla öde'}
                    </button>
                    {data.bank_transfer.enabled && !isCurrent && (
                      <button onClick={() => bankTransfer(p)} disabled={!!busy} className="w-full py-2 rounded-lg text-sm font-semibold border border-gray-200 text-gray-600 hover:bg-gray-50 flex items-center justify-center gap-2"><Landmark size={15} /> Havale / EFT</button>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      </div>

      {bank && (
        <div className="bg-amber-50 border border-amber-200 rounded-xl p-5 text-sm text-amber-900">
          <p className="font-bold">Havale talimatı — Fatura {bank.number}</p>
          <p className="mt-1">Tutar: <b>{fmtMoney(bank.total, bank.currency)}</b> (KDV dahil)</p>
          {data.bank_transfer.iban ? (
            <p className="mt-1">{data.bank_transfer.bank} · {data.bank_transfer.holder}<br /><span className="font-mono">{data.bank_transfer.iban}</span></p>
          ) : <p className="mt-1">Banka bilgisi için {`destek ekibiyle`} iletişime geçin.</p>}
          <p className="mt-1 text-xs">{data.bank_transfer.note}</p>
        </div>
      )}

      {/* Fatura bilgileri */}
      <div className={card}>
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-bold text-gray-900">Fatura bilgileri</h2>
          {data.can_manage && <button onClick={() => setProfileOpen(!profileOpen)} className="text-sm font-semibold text-[var(--primary-strong)]">{profileOpen ? 'Kapat' : data.profile ? 'Düzenle' : 'Ekle'}</button>}
        </div>
        {!profileOpen && data.profile && (
          <p className="text-sm text-gray-600 mt-2">{data.profile.legal_name} · {data.profile.tax_number ? `VKN/TCKN ${data.profile.tax_number} · ` : ''}{data.profile.address}, {data.profile.city}</p>
        )}
        {profileOpen && (
          <form onSubmit={saveProfile} className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <select className={inputCls} value={profile.type} onChange={(e) => setProfile({ ...profile, type: e.target.value as 'company' | 'individual' })}>
              <option value="company">Şirket (VKN)</option><option value="individual">Şahıs (TCKN)</option>
            </select>
            <input className={inputCls} placeholder="Unvan / ad soyad" value={profile.legal_name} onChange={(e) => setProfile({ ...profile, legal_name: e.target.value })} required />
            <input className={inputCls} placeholder="Vergi dairesi" value={profile.tax_office ?? ''} onChange={(e) => setProfile({ ...profile, tax_office: e.target.value })} />
            <input className={inputCls} placeholder={profile.type === 'company' ? 'Vergi numarası' : 'TC kimlik no'} value={profile.tax_number ?? ''} onChange={(e) => setProfile({ ...profile, tax_number: e.target.value })} required={profile.type === 'company'} />
            <input className={inputCls} placeholder="Fatura e-postası" type="email" value={profile.email ?? ''} onChange={(e) => setProfile({ ...profile, email: e.target.value })} />
            <input className={inputCls} placeholder="Telefon" value={profile.phone ?? ''} onChange={(e) => setProfile({ ...profile, phone: e.target.value })} />
            <input className={inputCls + ' sm:col-span-2'} placeholder="Adres" value={profile.address} onChange={(e) => setProfile({ ...profile, address: e.target.value })} required />
            <input className={inputCls} placeholder="İlçe" value={profile.district ?? ''} onChange={(e) => setProfile({ ...profile, district: e.target.value })} />
            <input className={inputCls} placeholder="İl" value={profile.city} onChange={(e) => setProfile({ ...profile, city: e.target.value })} required />
            <button type="submit" disabled={busy === 'profile'} className="sm:col-span-2 py-2.5 bg-[var(--primary)] text-white font-semibold rounded-lg">{busy === 'profile' ? 'Kaydediliyor…' : 'Kaydet'}</button>
          </form>
        )}
      </div>

      {/* Faturalar */}
      <div className={card}>
        <div className="flex items-center justify-between"><h2 className="text-sm font-bold text-gray-900">Faturalar</h2><button onClick={() => load()} className="text-gray-400 hover:text-gray-600"><RefreshCw size={15} /></button></div>
        {data.invoices.length === 0 ? <p className="text-sm text-gray-400 mt-2">Henüz fatura yok.</p> : (
          <table className="w-full text-sm mt-3">
            <thead><tr className="text-left text-xs text-gray-400"><th className="py-1.5">No</th><th>Açıklama</th><th>Dönem</th><th className="text-right">Tutar</th><th>Durum</th></tr></thead>
            <tbody>
              {data.invoices.map((i) => (
                <tr key={i.id} className="border-t border-gray-100">
                  <td className="py-2 font-mono text-xs">{i.number}</td>
                  <td className="text-gray-600">{i.description}</td>
                  <td className="text-gray-500 text-xs">{fmtDate(i.period_start)} – {fmtDate(i.period_end)}</td>
                  <td className="text-right font-semibold">{fmtMoney(i.total, i.currency)}</td>
                  <td><span className={clsx('px-2 py-0.5 rounded-full text-[11px] font-bold', i.status === 'paid' ? 'bg-emerald-50 text-emerald-700' : i.status === 'open' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500')}>{i.status === 'paid' ? 'Ödendi' : i.status === 'open' ? 'Bekliyor' : i.status === 'failed' ? 'Başarısız' : 'İptal'}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

function Info({ label, value, tone }: { label: string; value: string; tone?: 'warn' }) {
  return (
    <div className={clsx('rounded-lg px-3 py-2', tone === 'warn' ? 'bg-amber-50' : 'bg-[var(--app-bg)]')}>
      <p className="text-[11px] text-gray-400">{label}</p>
      <p className={clsx('font-semibold', tone === 'warn' ? 'text-amber-700' : 'text-gray-800')}>{value}</p>
    </div>
  )
}
