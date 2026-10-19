import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import api from '@/lib/api'
import { Bot, Save, CheckCircle } from 'lucide-react'

// Mesaj AI'ının davranış ayarları — Genel Ayarlar'dan taşındı.
// Yalnız bu sayfanın anahtarları gönderilir; backend mevcut settings ile merge eder.
const KEYS = ['ai_model', 'auto_reply', 'auto_reply_delay_min', 'auto_reply_delay_max', 'ai_system_prompt'] as const

export default function AiReplySettingsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [settings, setSettings] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    api.get('/settings')
      .then((res) => setSettings(res.data.settings || {}))
      .catch(() => {
        if (user?.tenant?.settings) setSettings(user.tenant.settings as Record<string, string>)
      })
  }, [user])

  const updateSetting = (key: string, value: string) => {
    setSettings((prev) => ({ ...prev, [key]: value }))
  }

  const handleSave = async () => {
    setSaving(true)
    setSaved(false)
    try {
      const payload: Record<string, string> = {}
      for (const key of KEYS) {
        if (settings[key] !== undefined) payload[key] = settings[key]
      }
      await api.put('/settings', { settings: payload })
      setSaved(true)
      setTimeout(() => setSaved(false), 3000)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-extrabold text-gray-900">{t('nav.aiReplySettings')}</h1>

      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 className="font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <Bot size={18} /> {t('settings.aiReplySection')}
        </h2>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.aiModel')}</label>
            <select
              value={settings.ai_model || 'claude-sonnet-4-5-20250929'}
              onChange={(e) => updateSetting('ai_model', e.target.value)}
              className="w-full max-w-xs px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="claude-sonnet-4-5-20250929">Claude Sonnet 4.5</option>
              <option value="claude-haiku-4-5-20251001">Claude Haiku 4.5</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.autoReply')}</label>
            <select
              value={settings.auto_reply || 'disabled'}
              onChange={(e) => updateSetting('auto_reply', e.target.value)}
              className="w-full max-w-xs px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="disabled">{t('settings.autoReplyOff')}</option>
              <option value="auto">{t('settings.autoReplySend')}</option>
            </select>
            <p className="mt-1 text-xs text-gray-500">
              {t('settings.autoReplyDesc')}
            </p>
          </div>
          {settings.auto_reply === 'auto' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.autoReplyDelay')}</label>
              <div className="flex items-center gap-2">
                <input
                  type="number"
                  min={1}
                  max={30}
                  value={settings.auto_reply_delay_min || '3'}
                  onChange={(e) => updateSetting('auto_reply_delay_min', e.target.value)}
                  className="w-20 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <span className="text-gray-400">{t('settings.to')}</span>
                <input
                  type="number"
                  min={1}
                  max={60}
                  value={settings.auto_reply_delay_max || '10'}
                  onChange={(e) => updateSetting('auto_reply_delay_max', e.target.value)}
                  className="w-20 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <span className="text-sm text-gray-500">{t('settings.minutesBetween')}</span>
              </div>
              <p className="mt-1 text-xs text-gray-500">
                {t('settings.autoReplyDelayDesc')}
              </p>
            </div>
          )}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('settings.aiSystemPrompt')}</label>
            <textarea
              value={settings.ai_system_prompt || ''}
              onChange={(e) => updateSetting('ai_system_prompt', e.target.value)}
              placeholder={t('settings.aiSystemPromptPlaceholder')}
              rows={5}
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
            <p className="mt-1 text-xs text-gray-500">
              {t('settings.aiSystemPromptDesc')}
            </p>
          </div>
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
    </div>
  )
}
