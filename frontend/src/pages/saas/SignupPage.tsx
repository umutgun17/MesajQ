import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { Eye, EyeOff, Check } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { billingApi, type PlanInfo } from '@/lib/billingApi'
import { BRAND } from '@/lib/brand'
import AuthShell, { inputCls, btnCls, errorsOf } from './AuthShell'

export default function SignupPage() {
  const { user, signup } = useAuth()
  const [form, setForm] = useState({ company: '', name: '', email: '', phone: '', password: '', platform: '', terms: false, website: '' })
  const [show, setShow] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [plans, setPlans] = useState<PlanInfo[]>([])
  const [trialDays, setTrialDays] = useState(14)

  useEffect(() => {
    billingApi.publicPlans().then((d) => { setPlans(d.plans); setTrialDays(d.trial_days) }).catch(() => {})
  }, [])

  if (user) return <Navigate to="/" replace />

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.type === 'checkbox' ? (e.target as HTMLInputElement).checked : e.target.value }))

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(''); setLoading(true)
    try {
      await signup({ ...form, platform: form.platform || undefined, phone: form.phone || undefined })
    } catch (err) {
      setError(errorsOf(err))
    } finally { setLoading(false) }
  }

  const trialPlan = plans.find((p) => p.highlight) ?? plans[0]

  return (
    <AuthShell
      wide
      title={`${trialDays} gün ücretsiz deneyin`}
      subtitle={`Kart bilgisi gerekmez. ${trialPlan ? trialPlan.name + ' paketinin' : 'Tüm'} özellikleri deneme boyunca açık.`}
      footer={<>Zaten hesabınız var mı? <Link to="/login" className="font-semibold text-[var(--primary-strong)]">Giriş yapın</Link></>}
    >
      {trialPlan && (
        <ul className="mb-4 grid grid-cols-1 gap-1 text-[13px] text-gray-600">
          {trialPlan.features.slice(0, 4).map((f) => (
            <li key={f} className="flex items-start gap-2"><Check size={15} className="text-emerald-500 mt-0.5 shrink-0" />{f}</li>
          ))}
        </ul>
      )}
      <form onSubmit={submit} className="space-y-3">
        {error && <div className="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg">{error}</div>}
        <input type="text" name="website" value={form.website} onChange={set('website')} className="hidden" tabIndex={-1} autoComplete="off" />
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1.5">Firma / mağaza adı</label>
          <input className={inputCls} value={form.company} onChange={set('company')} placeholder="Örn. Moda Dünyası" required minLength={2} />
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Adınız soyadınız</label>
            <input className={inputCls} value={form.name} onChange={set('name')} required minLength={2} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Telefon <span className="text-gray-400 font-normal">(isteğe bağlı)</span></label>
            <input className={inputCls} value={form.phone} onChange={set('phone')} placeholder="05xx xxx xx xx" />
          </div>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1.5">İş e-postanız</label>
          <input type="email" className={inputCls} value={form.email} onChange={set('email')} placeholder="ad@firma.com" required />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1.5">Şifre <span className="text-gray-400 font-normal">(en az 10 karakter, harf + rakam)</span></label>
          <div className="relative">
            <input type={show ? 'text' : 'password'} className={inputCls + ' pr-10'} value={form.password} onChange={set('password')} required minLength={10} />
            <button type="button" onClick={() => setShow(!show)} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">{show ? <EyeOff size={18} /> : <Eye size={18} />}</button>
          </div>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1.5">E-ticaret altyapınız</label>
          <select className={inputCls} value={form.platform} onChange={set('platform')}>
            <option value="">Seçin (isteğe bağlı)</option>
            <option value="ticimax">Ticimax</option>
            <option value="ikas">ikas</option>
            <option value="other">Diğer</option>
          </select>
        </div>
        <label className="flex items-start gap-2 text-xs text-gray-600 pt-1">
          <input type="checkbox" checked={form.terms} onChange={set('terms')} className="mt-0.5" required />
          <span>
            <a href={BRAND.siteUrl + 'kullanim-sartlari'} target="_blank" rel="noreferrer" className="underline">Kullanım Şartları</a>'nı ve{' '}
            <a href={BRAND.siteUrl + 'kvkk'} target="_blank" rel="noreferrer" className="underline">KVKK Aydınlatma Metni</a>'ni okudum, kabul ediyorum.
          </span>
        </label>
        <button type="submit" disabled={loading} className={btnCls}>{loading ? 'Hesap oluşturuluyor…' : 'Ücretsiz başla'}</button>
      </form>
    </AuthShell>
  )
}
