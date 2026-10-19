import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { billingApi } from '@/lib/billingApi'
import AuthShell, { inputCls, btnCls, errorsOf } from './AuthShell'

export default function ResetPasswordPage() {
  const [params] = useSearchParams()
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [msg, setMsg] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const email = params.get('email') ?? ''
  const token = params.get('token') ?? ''
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(''); setLoading(true)
    try { const r = await billingApi.reset({ email, token, password, password_confirmation: confirm }); setMsg(r.message) } catch (err) { setError(errorsOf(err)) } finally { setLoading(false) }
  }
  return (
    <AuthShell title="Yeni şifre belirle" subtitle={email} footer={<Link to="/login" className="font-semibold text-[var(--primary-strong)]">Girişe dön</Link>}>
      {msg ? <div className="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm px-4 py-3 rounded-lg">{msg}</div> : (
        <form onSubmit={submit} className="space-y-3">
          {error && <div className="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg">{error}</div>}
          <input type="password" className={inputCls} value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Yeni şifre (min 10, harf + rakam)" required minLength={10} />
          <input type="password" className={inputCls} value={confirm} onChange={(e) => setConfirm(e.target.value)} placeholder="Yeni şifre (tekrar)" required minLength={10} />
          <button type="submit" disabled={loading || !token} className={btnCls}>{loading ? 'Kaydediliyor…' : 'Şifreyi güncelle'}</button>
        </form>
      )}
    </AuthShell>
  )
}
