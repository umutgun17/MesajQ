import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { useTranslation } from 'react-i18next'
import { Eye, EyeOff } from 'lucide-react'
import { Link } from 'react-router-dom'
import BrandMark from '@/components/saas/BrandMark'

export default function LoginPage() {
  const { t } = useTranslation()
  const { user, login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  if (user) return <Navigate to="/" replace />

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError('')
    setLoading(true)

    try {
      await login(email, password)
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      setError(axiosErr.response?.data?.message || t('login.failed'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-[var(--app-bg)] px-4">
      <div className="w-full max-w-sm">
        {/* Marka kilidi — ikon renkli kalır, word-mark koyu temada beyaza döner */}
        <div className="flex items-center justify-center mb-8"><BrandMark size="lg" /></div>

        {/* Form kartı */}
        <form onSubmit={handleSubmit} className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4">
          {error && (
            <div className="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg">
              {error}
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">{t('login.email')}</label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full px-4 py-2.5 bg-[var(--app-bg)] border border-transparent rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white transition-colors"
              placeholder={t('login.emailPlaceholder')}
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">{t('login.password')}</label>
            <div className="relative">
              <input
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full px-4 py-2.5 bg-[var(--app-bg)] border border-transparent rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:bg-white transition-colors pr-10"
                placeholder="••••••••"
                required
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
              >
                {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </div>
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full py-2.5 bg-[var(--primary)] hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-lg transition-colors"
          >
            {loading ? t('login.submitting') : t('login.submit')}
          </button>
          <div className="flex items-center justify-between text-xs pt-1">
            <Link to="/sifremi-unuttum" className="text-gray-500 hover:text-gray-800">Şifremi unuttum</Link>
            <Link to="/kayit" className="font-semibold text-[var(--primary-strong)]">Ücretsiz hesap aç</Link>
          </div>
        </form>
      </div>
    </div>
  )
}
