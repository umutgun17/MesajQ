import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { Globe, Save, CheckCircle, Eye, EyeOff } from 'lucide-react'

// Mesaj ürününün Meta (Facebook/Instagram) uygulama ayarları — Genel Ayarlar'dan taşındı.
// Aynı tenant.settings anahtarları: meta_app_id / meta_app_secret / meta_verify_token

const inputCls = 'w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500'

export default function MessagesMetaSettingsPage() {
  const { t } = useTranslation()
  const [values, setValues] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [showSecret, setShowSecret] = useState(false)
  const [showVerify, setShowVerify] = useState(false)

  useEffect(() => {
    api.get('/settings').then((res) => {
      const s = res.data.settings || {}
      setValues({
        meta_app_id: s.meta_app_id || '',
        meta_app_secret: s.meta_app_secret || '',
        meta_verify_token: s.meta_verify_token || '',
      })
    }).catch(() => {})
  }, [])

  const set = (k: string, v: string) => setValues((p) => ({ ...p, [k]: v }))

  const save = async () => {
    setSaving(true)
    setSaved(false)
    try {
      await api.put('/settings', { settings: values })
      setSaved(true)
      setTimeout(() => setSaved(false), 3000)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6 max-w-3xl">
      <h1 className="text-xl font-extrabold text-gray-900">Meta Ayarları</h1>

      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 className="font-semibold text-gray-900 mb-1 flex items-center gap-2">
          <span className="w-9 h-9 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center">
            <Globe size={17} />
          </span>
          {t('settings.metaSettings')}
        </h2>
        <p className="text-xs text-gray-500 mb-5 ml-11">
          Facebook/Instagram mesaj ve yorum webhook'larının çalıştığı Meta uygulaması kimlikleri.
        </p>

        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.metaAppId')}</label>
            <input
              type="text"
              value={values.meta_app_id || ''}
              onChange={(e) => set('meta_app_id', e.target.value)}
              placeholder="123456789..."
              className={inputCls + ' max-w-md'}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.metaAppSecret')}</label>
            <div className="relative w-full max-w-md">
              <input
                type={showSecret ? 'text' : 'password'}
                value={values.meta_app_secret || ''}
                onChange={(e) => set('meta_app_secret', e.target.value)}
                placeholder="App Secret..."
                className={inputCls + ' pr-10'}
              />
              <button type="button" onClick={() => setShowSecret(!showSecret)} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600">
                {showSecret ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
            <p className="mt-1 text-xs text-gray-500">{t('settings.metaAppSecretDesc')}</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.webhookVerifyToken')}</label>
            <div className="relative w-full max-w-md">
              <input
                type={showVerify ? 'text' : 'password'}
                value={values.meta_verify_token || ''}
                onChange={(e) => set('meta_verify_token', e.target.value)}
                placeholder="Verify token..."
                className={inputCls + ' pr-10'}
              />
              <button type="button" onClick={() => setShowVerify(!showVerify)} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600">
                {showVerify ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
            <p className="mt-1 text-xs text-gray-500">{t('settings.webhookVerifyTokenDesc')}</p>
          </div>

          <div className="mt-2 flex items-center gap-3">
            <button
              onClick={save}
              disabled={saving}
              className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors"
            >
              <Save size={16} />
              {saving ? t('common.saving') : t('common.save')}
            </button>
            {saved && (
              <span className="flex items-center gap-1 text-sm text-green-600">
                <CheckCircle size={16} /> {t('common.saved')}
              </span>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
