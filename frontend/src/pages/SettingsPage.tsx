import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import api from '@/lib/api'
import i18n from '@/i18n'
import { Settings, Save, CheckCircle, Eye, EyeOff, Key } from 'lucide-react'

export default function SettingsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [tenantSettings, setTenantSettings] = useState<Record<string, string | boolean>>({})
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [tenantInfo, setTenantInfo] = useState<{ name: string; slug: string } | null>(null)
  const [showOpenAiKey, setShowOpenAiKey] = useState(false)
  const [showClaudeKey, setShowClaudeKey] = useState(false)

  // İade → /returns/settings, Meta → /messages/settings, GA4 → /ads/settings taşındı

  useEffect(() => {
    // Fetch settings from API (works for both normal user and super admin with X-Tenant-Id)
    api.get('/settings')
      .then((res) => {
        const s = res.data.settings || {}
        setTenantSettings(s)
        if (res.data.tenant) {
          setTenantInfo(res.data.tenant)
        }
      })
      .catch(() => {
        // Fallback to user.tenant.settings
        if (user?.tenant?.settings) {
          setTenantSettings(user.tenant.settings as Record<string, string | boolean>)
        }
        if (user?.tenant) {
          setTenantInfo({ name: user.tenant.name, slug: user.tenant.slug })
        }
      })
  }, [user])

  const handleSave = async () => {
    setSaving(true)
    setSaved(false)
    try {
      await api.put('/settings', { settings: tenantSettings })
      // Varsayılan dil kaydetmek bilinçli bir tercihtir: ANINDA uygula ve
      // kaydedenin kişisel dil tercihini de bu dile çek (aksi halde eski
      // localStorage tercihi kazanıp "kaydettim ama değişmedi" oluyordu).
      const lang = String(tenantSettings.default_language ?? '')
      if (lang === 'tr' || lang === 'en') {
        localStorage.setItem('app_language', lang)
        i18n.changeLanguage(lang)
      }
      setSaved(true)
      setTimeout(() => setSaved(false), 3000)
    } finally {
      setSaving(false)
    }
  }

  const updateSetting = (key: string, value: string | boolean) => {
    setTenantSettings((prev) => ({ ...prev, [key]: value }))
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-extrabold text-gray-900">{t('settings.title')}</h1>

      {/* Tenant Info */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 className="font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <Settings size={18} /> {t('settings.companyInfo')}
        </h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.companyName')}</label>
            <p className="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-600">{tenantInfo?.name ?? '-'}</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.slug')}</label>
            <p className="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-600">{tenantInfo?.slug ?? '-'}</p>
          </div>
        </div>
      </div>

      {/* General Settings */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 className="font-semibold text-gray-900 mb-4">{t('settings.generalSettings')}</h2>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.defaultLanguage')}</label>
            <select
              value={String(tenantSettings.default_language ?? 'en')}
              onChange={(e) => updateSetting('default_language', e.target.value)}
              className="w-full max-w-xs px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="en">English</option>
              <option value="tr">Türkçe</option>
            </select>
            <p className="mt-1 text-xs text-gray-500">
              {t('settings.defaultLanguageDesc')}
            </p>
          </div>
          {/* AI & otomatik yanıt ayarları → Mesaj ürünü (/settings/ai-reply) taşındı */}
        </div>

        <div className="mt-6 flex items-center gap-3">
          <button
            onClick={handleSave}
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

      {/* API Keys */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 className="font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <Key size={18} /> {t('settings.apiKeys')}
        </h2>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.claudeApiKey')}</label>
            <div className="relative w-full max-w-md">
              <input
                type={showClaudeKey ? 'text' : 'password'}
                value={String(tenantSettings.claude_api_key ?? '')}
                onChange={(e) => updateSetting('claude_api_key', e.target.value)}
                placeholder="sk-ant-..."
                className="w-full px-3 py-2 pr-10 border border-gray-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
              <button
                type="button"
                onClick={() => setShowClaudeKey(!showClaudeKey)}
                className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600"
              >
                {showClaudeKey ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
            <p className="mt-1 text-xs text-gray-500">
              {t('settings.claudeApiKeyDesc')}
            </p>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.openaiApiKey')}</label>
            <div className="relative w-full max-w-md">
              <input
                type={showOpenAiKey ? 'text' : 'password'}
                value={String(tenantSettings.openai_api_key ?? '')}
                onChange={(e) => updateSetting('openai_api_key', e.target.value)}
                placeholder="sk-proj-..."
                className="w-full px-3 py-2 pr-10 border border-gray-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
              <button
                type="button"
                onClick={() => setShowOpenAiKey(!showOpenAiKey)}
                className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600"
              >
                {showOpenAiKey ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
            <p className="mt-1 text-xs text-gray-500">
              {t('settings.openaiApiKeyDesc')}
            </p>
          </div>

          {/* Claude bakiye kapısı — hangi modüller kiracının anahtarını harcayabilir */}
          <div className="pt-4 border-t border-gray-100">
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.claudeUsage')}</label>
            <p className="mb-3 text-xs text-gray-500">{t('settings.claudeUsageDesc')}</p>
            <div className="space-y-2">
              <label className="flex items-start gap-2 text-sm text-gray-400">
                <input type="checkbox" checked disabled className="mt-0.5 rounded border-gray-300" />
                <span>{t('settings.claudeUsageChatbot')}</span>
              </label>
              <label className="flex items-start gap-2 text-sm text-gray-700 cursor-pointer">
                <input
                  type="checkbox"
                  checked={!!tenantSettings.ai_inbox_enabled}
                  onChange={(e) => updateSetting('ai_inbox_enabled', e.target.checked)}
                  className="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                />
                <span>
                  {t('settings.claudeUsageInbox')}
                  <span className="block text-xs text-gray-500">{t('settings.claudeUsageInboxDesc')}</span>
                </span>
              </label>
              <label className="flex items-start gap-2 text-sm text-gray-700 cursor-pointer">
                <input
                  type="checkbox"
                  checked={!!tenantSettings.ai_social_enabled}
                  onChange={(e) => updateSetting('ai_social_enabled', e.target.checked)}
                  className="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                />
                <span>{t('settings.claudeUsageSocial')}</span>
              </label>
              <label className="flex items-start gap-2 text-sm text-gray-700 cursor-pointer">
                <input
                  type="checkbox"
                  checked={!!tenantSettings.ai_calls_enabled}
                  onChange={(e) => updateSetting('ai_calls_enabled', e.target.checked)}
                  className="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                />
                <span>{t('settings.claudeUsageCalls')}</span>
              </label>
            </div>
          </div>

          <div className="mt-4 flex items-center gap-3">
            <button
              onClick={handleSave}
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
