import { useEffect, useState } from 'react'
import api from '@/lib/api'
import { Phone, Save, CheckCircle, Loader2, Eye, EyeOff } from 'lucide-react'

// Arama ürününün ayar sayfası: çağrı kayıtlarının çekildiği NetGSM bağlantısı.
// Kimlikler tenant'ın netgsm-call kanalında saklanır (backend: /channels/platform/netgsm).

interface CredField {
  key: string
  label: string
  type: string
  info?: string
  helpUrl?: string
}

export default function CallsSettingsPage() {
  const [fields, setFields] = useState<CredField[]>([])
  const [values, setValues] = useState<Record<string, string>>({})
  const [hasCredentials, setHasCredentials] = useState(false)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [showPw, setShowPw] = useState<Record<string, boolean>>({})

  useEffect(() => {
    api.get('/channels/platform/netgsm')
      .then((res) => {
        setFields(res.data.credential_fields || [])
        setHasCredentials(!!res.data.has_credentials)
      })
      .catch(() => setError('NetGSM platform bilgisi alınamadı.'))
      .finally(() => setLoading(false))
  }, [])

  const save = async () => {
    setSaving(true)
    setSaved(false)
    setError(null)
    try {
      await api.patch('/channels/platform/netgsm', { credentials: values })
      setSaved(true)
      setHasCredentials(true)
      setValues({})
      setTimeout(() => setSaved(false), 3000)
    } catch {
      setError('Kaydedilemedi. En az bir alan doldurun.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6 max-w-3xl">
      <h1 className="text-xl font-extrabold text-gray-900">Arama Ayarları</h1>

      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div className="flex items-start justify-between mb-1">
          <h2 className="font-semibold text-gray-900 flex items-center gap-2">
            <span className="w-9 h-9 rounded-xl bg-sky-100 text-sky-600 flex items-center justify-center">
              <Phone size={17} />
            </span>
            NetGSM Bağlantısı
          </h2>
          {hasCredentials && (
            <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-semibold">
              <CheckCircle size={12} /> Bağlı
            </span>
          )}
        </div>
        <p className="text-xs text-gray-500 mb-5 ml-11">
          Çağrı kayıtları ve santral verileri bu kimlikle NetGSM API'sinden çekilir. Bilgiler şifreli saklanır.
        </p>

        {loading ? (
          <div className="flex justify-center py-8">
            <div className="w-6 h-6 border-3 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : (
          <div className="space-y-4">
            {fields.map((f) => (
              <div key={f.key}>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  {f.label}
                  {hasCredentials && <span className="ml-2 text-xs font-normal text-gray-400">(dolu — değiştirmek için yazın)</span>}
                </label>
                <div className="relative">
                  <input
                    type={f.type === 'password' && !showPw[f.key] ? 'password' : 'text'}
                    value={values[f.key] ?? ''}
                    onChange={(e) => setValues((p) => ({ ...p, [f.key]: e.target.value }))}
                    placeholder={hasCredentials ? '••••••••' : ''}
                    className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 pr-10"
                  />
                  {f.type === 'password' && (
                    <button
                      type="button"
                      onClick={() => setShowPw((p) => ({ ...p, [f.key]: !p[f.key] }))}
                      className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600"
                    >
                      {showPw[f.key] ? <EyeOff size={16} /> : <Eye size={16} />}
                    </button>
                  )}
                </div>
                {f.info && <p className="mt-1 text-xs text-gray-500">{f.info}</p>}
              </div>
            ))}

            {error && <p className="text-sm text-red-600">{error}</p>}

            <div className="flex items-center gap-3 pt-1">
              <button
                onClick={save}
                disabled={saving || Object.values(values).every((v) => !v)}
                className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors"
              >
                {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} Kaydet
              </button>
              {saved && <span className="flex items-center gap-1 text-sm text-emerald-600"><CheckCircle size={16} /> Kaydedildi</span>}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
