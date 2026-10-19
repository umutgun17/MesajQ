import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { billingApi } from '@/lib/billingApi'
import AuthShell, { inputCls, btnCls, errorsOf } from './AuthShell'

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [msg, setMsg] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(''); setLoading(true)
    try { const r = await billingApi.forgot(email); setMsg(r.message) } catch (err) { setError(errorsOf(err)) } finally { setLoading(false) }
  }
  return (
    <AuthShell title="Şifremi unuttum" subtitle="E-posta adresinizi yazın, sıfırlama bağlantısı gönderelim." footer={<Link to="/login" className="font-semibold text-[var(--primary-strong)]">Girişe dön</Link>}>
      {msg ? <div className="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm px-4 py-3 rounded-lg">{msg}</div> : (
        <form onSubmit={submit} className="space-y-3">
          {error && <div className="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg">{error}</div>}
          <input type="email" className={inputCls} value={email} onChange={(e) => setEmail(e.target.value)} placeholder="ad@firma.com" required />
          <button type="submit" disabled={loading} className={btnCls}>{loading ? 'Gönderiliyor…' : 'Bağlantı gönder'}</button>
        </form>
      )}
    </AuthShell>
  )
}
