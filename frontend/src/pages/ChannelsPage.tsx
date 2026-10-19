import { useEffect, useState, useMemo, useRef, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import api from '@/lib/api'
import type { Channel, CredentialField } from '@/types'
import { platforms, subChannelLabels } from '@/lib/platformConfig'
import { formatRelativeTime } from '@/lib/channelUtils'
import {
  Facebook, ShoppingBag, Headphones, MessageCircle, Globe, Mail,
  CheckCircle, Clock, Eye, EyeOff, KeyRound, X, Save, Loader2,
  Info, ExternalLink, Link2, AlertCircle, Instagram, ChevronRight,
} from 'lucide-react'
import clsx from 'clsx'

const platformIcons: Record<string, React.ReactNode> = {
  meta: <Facebook size={22} />,
  trendyol: <ShoppingBag size={22} />,
  hepsiburada: <ShoppingBag size={22} />,
  ticimax: <Headphones size={22} />,
  whatsapp: <MessageCircle size={22} />,
  email: <Mail size={22} />,
}

function InfoTooltip({ field }: { field: CredentialField }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  if (!field.info) return null

  return (
    <div className="relative inline-block" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className="p-0.5 text-gray-400 hover:text-indigo-500 transition-colors"
        title={t('common.info')}
      >
        <Info size={14} />
      </button>
      {open && (
        <div className="absolute z-10 left-0 top-6 w-72 bg-gray-900 text-gray-100 text-xs rounded-lg shadow-lg p-3 leading-relaxed">
          <p>{field.info}</p>
          {field.helpUrl && (
            <a
              href={field.helpUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 mt-2 text-indigo-300 hover:text-indigo-200 underline"
            >
              <ExternalLink size={11} />
              {t('channels.goToPage')}
            </a>
          )}
        </div>
      )}
    </div>
  )
}

export default function ChannelsPage() {
  const { t } = useTranslation()
  const [channels, setChannels] = useState<Channel[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState<number | null>(null)
  const [searchParams, setSearchParams] = useSearchParams()

  // Credential modal state
  const [credPlatform, setCredPlatform] = useState<string | null>(null)
  const [credFields, setCredFields] = useState<CredentialField[]>([])
  const [credValues, setCredValues] = useState<Record<string, string>>({})
  const [credLoading, setCredLoading] = useState(false)
  const [credSaving, setCredSaving] = useState(false)
  const [credSaved, setCredSaved] = useState(false)
  const [credHasExisting, setCredHasExisting] = useState(false)

  // Meta OAuth state
  const [metaOAuthLoading, setMetaOAuthLoading] = useState(false)
  const [metaOAuthResult, setMetaOAuthResult] = useState<{ type: 'success' | 'error'; message: string } | null>(null)

  // Meta çok-sayfa seçimi (birden fazla hesaba yetki verildiğinde)
  const [pickKey, setPickKey] = useState<string | null>(null)
  const [pickPages, setPickPages] = useState<{ id: string; name: string; has_instagram: boolean }[]>([])
  const [pickSelecting, setPickSelecting] = useState<string | null>(null)

  useEffect(() => {
    api.get('/channels').then((res) => {
      setChannels(res.data)
      setLoading(false)
    })
  }, [])

  // Handle Meta OAuth callback query params
  useEffect(() => {
    const oauthStatus = searchParams.get('meta_oauth')
    if (!oauthStatus) return

    if (oauthStatus === 'success') {
      const pageName = searchParams.get('page_name') || 'Facebook'
      setMetaOAuthResult({ type: 'success', message: t('channels.oauthSuccess', { pageName }) })
      // Refresh channels to reflect new credentials
      api.get('/channels').then((res) => setChannels(res.data))
    } else if (oauthStatus === 'choose') {
      // Birden fazla hesaba yetki verildi: hangisinin bu firmaya bağlanacağını sor
      const key = searchParams.get('pick')
      if (key) {
        api.get(`/meta/oauth/pending/${key}`)
          .then((res) => { setPickKey(key); setPickPages(res.data.pages || []) })
          .catch((err) => setMetaOAuthResult({
            type: 'error',
            message: err?.response?.data?.message || t('channels.oauthUnknown'),
          }))
      }
    } else {
      const reason = searchParams.get('reason') || 'unknown'
      const reasonMessages: Record<string, string> = {
        denied: t('channels.oauthDenied'),
        missing_params: t('channels.oauthMissingParams'),
        invalid_state: t('channels.oauthInvalidState'),
        expired: t('channels.oauthExpired'),
        token_exchange: t('channels.oauthTokenExchange'),
        long_lived_token: t('channels.oauthLongLivedToken'),
        no_pages: t('channels.oauthNoPages'),
      }
      setMetaOAuthResult({ type: 'error', message: reasonMessages[reason] || t('channels.oauthUnknown') })
    }

    // Clean URL
    searchParams.delete('meta_oauth')
    searchParams.delete('page_name')
    searchParams.delete('reason')
    searchParams.delete('pick')
    setSearchParams(searchParams, { replace: true })
  }, [searchParams, setSearchParams, t])

  const selectMetaPage = useCallback(async (pageId: string) => {
    if (!pickKey) return
    setPickSelecting(pageId)
    try {
      const res = await api.post(`/meta/oauth/pending/${pickKey}`, { page_id: pageId })
      setPickKey(null)
      setPickPages([])
      setMetaOAuthResult({ type: 'success', message: t('channels.oauthSuccess', { pageName: res.data.page_name || 'Facebook' }) })
      api.get('/channels').then((r) => setChannels(r.data))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
        || t('channels.oauthUnknown')
      setMetaOAuthResult({ type: 'error', message: msg })
    } finally {
      setPickSelecting(null)
    }
  }, [pickKey, t])

  const startMetaOAuth = useCallback(async () => {
    setMetaOAuthLoading(true)
    setMetaOAuthResult(null)
    try {
      const res = await api.get('/meta/oauth/redirect')
      window.location.href = res.data.url
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
        || t('channels.oauthUrlError')
      setMetaOAuthResult({ type: 'error', message: msg })
      setMetaOAuthLoading(false)
    }
  }, [t])

  // Group channels by platform
  const grouped = useMemo(() => {
    const allPlatformTypes = new Set(platforms.flatMap((p) => p.channelTypes))
    const result = platforms.map((platform) => {
      const chs = channels.filter((ch) => platform.channelTypes.includes(ch.type))
      return { platform, channels: chs }
    })
    const ungrouped = channels.filter((ch) => !allPlatformTypes.has(ch.type))
    return { platforms: result, ungrouped }
  }, [channels])

  const toggleChannel = async (channel: Channel) => {
    setSaving(channel.id)
    try {
      const res = await api.patch(`/channels/${channel.id}`, {
        is_active: !channel.is_active,
      })
      setChannels((prev) => prev.map((ch) => (ch.id === channel.id ? { ...ch, ...res.data } : ch)))
    } finally {
      setSaving(null)
    }
  }

  const openCredModal = async (platformKey: string) => {
    setCredPlatform(platformKey)
    setCredLoading(true)
    setCredSaved(false)
    setCredValues({})
    try {
      const res = await api.get(`/channels/platform/${platformKey}`)
      setCredFields(res.data.credential_fields || [])
      setCredHasExisting(res.data.has_credentials)
    } catch {
      setCredFields([])
      setCredHasExisting(false)
    } finally {
      setCredLoading(false)
    }
  }

  const closeCredModal = () => {
    setCredPlatform(null)
    setCredFields([])
    setCredValues({})
    setCredSaved(false)
    setCredHasExisting(false)
  }

  const saveCredentials = async () => {
    if (!credPlatform) return
    setCredSaving(true)
    setCredSaved(false)
    try {
      await api.patch(`/channels/platform/${credPlatform}`, {
        credentials: credValues,
      })
      const res = await api.get('/channels')
      setChannels(res.data)
      setCredSaved(true)
      setCredValues({})
      setCredHasExisting(true)
      setTimeout(() => setCredSaved(false), 3000)
    } finally {
      setCredSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-20">
        <div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
      </div>
    )
  }

  const totalActive = channels.filter((c) => c.is_active).length
  const platformName = credPlatform ? platforms.find((p) => p.key === credPlatform)?.name : ''

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-extrabold text-gray-900">{t('channels.title')}</h1>
        <span className="text-sm text-gray-500">
          {totalActive} / {channels.length} {t('channels.active')}
        </span>
      </div>

      {/* Meta OAuth Result Banner */}
      {metaOAuthResult && (
        <div className={clsx(
          'flex items-center gap-3 px-4 py-3 rounded-lg text-sm',
          metaOAuthResult.type === 'success'
            ? 'bg-green-50 border border-green-200 text-green-800'
            : 'bg-red-50 border border-red-200 text-red-800'
        )}>
          {metaOAuthResult.type === 'success' ? <CheckCircle size={18} /> : <AlertCircle size={18} />}
          <span className="flex-1">{metaOAuthResult.message}</span>
          <button onClick={() => setMetaOAuthResult(null)} className="p-0.5 hover:opacity-70">
            <X size={16} />
          </button>
        </div>
      )}

      <div className="space-y-4">
        {grouped.platforms.map(({ platform, channels: chs }) => {
          if (chs.length === 0) return null
          const activeCount = chs.filter((c) => c.is_active).length
          const hasAnyCred = chs.some((c) => c.has_credentials)
          const lastSync = chs
            .map((c) => c.last_synced_at)
            .filter(Boolean)
            .sort()
            .pop()

          return (
            <div
              key={platform.key}
              className={clsx(
                'bg-white rounded-xl shadow-sm border-l-4 overflow-hidden',
                platform.borderColor
              )}
            >
              {/* Platform header */}
              <div className="px-5 py-4 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className={clsx('w-11 h-11 rounded-2xl flex items-center justify-center', platform.bg, platform.color)}>
                    {platformIcons[platform.key] || <Globe size={22} />}
                  </div>
                  <div>
                    <h2 className={clsx('font-semibold text-base', platform.color)}>{platform.name}</h2>
                    <div className="flex items-center gap-3 text-xs text-gray-500 mt-0.5">
                      <span>
                        {activeCount}/{chs.length} {t('channels.channelActive')}
                      </span>
                      {lastSync && (
                        <span className="flex items-center gap-1">
                          <Clock size={11} />
                          {t('channels.lastSync', { time: formatRelativeTime(lastSync) })}
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                <div className="flex items-center gap-3">
                  {hasAnyCred ? (
                    <span className="flex items-center gap-1 text-xs text-green-600 bg-green-50 px-2 py-1 rounded-md">
                      <Eye size={12} /> {t('channels.apiConnected')}
                    </span>
                  ) : (
                    <span className="flex items-center gap-1 text-xs text-orange-600 bg-orange-50 px-2 py-1 rounded-md">
                      <EyeOff size={12} /> {t('channels.apiMissing')}
                    </span>
                  )}
                  {platform.key === 'meta' ? (
                    <button
                      onClick={startMetaOAuth}
                      disabled={metaOAuthLoading}
                      className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 rounded-lg transition-colors"
                    >
                      {metaOAuthLoading ? <Loader2 size={14} className="animate-spin" /> : <Link2 size={14} />}
                      {metaOAuthLoading ? t('channels.redirecting') : hasAnyCred ? t('channels.reconnect') : t('channels.connectFacebook')}
                    </button>
                  ) : (
                    <button
                      onClick={() => openCredModal(platform.key)}
                      className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors"
                    >
                      <KeyRound size={14} />
                      {t('channels.apiSettings')}
                    </button>
                  )}
                </div>
              </div>

              {/* Sub-channels */}
              <div className="border-t border-gray-100 divide-y divide-gray-50">
                {chs.map((channel) => (
                  <div key={channel.id} className="px-5 py-3 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <span
                        className={clsx(
                          'w-2 h-2 rounded-full',
                          channel.is_active ? 'bg-green-500' : 'bg-gray-300'
                        )}
                      />
                      <div>
                        <span className="text-sm font-medium text-gray-800">
                          {subChannelLabels[channel.type] || channel.name}
                        </span>
                        <span className="ml-2 text-xs text-gray-400">{channel.type}</span>
                      </div>
                    </div>

                    <div className="flex items-center gap-3">
                      <span className={clsx('text-xs', channel.is_active ? 'text-green-600' : 'text-gray-400')}>
                        {channel.is_active ? t('common.active') : t('common.inactive')}
                      </span>
                      <button
                        onClick={() => toggleChannel(channel)}
                        disabled={saving === channel.id}
                        className={clsx(
                          'relative w-11 h-6 rounded-full transition-colors',
                          channel.is_active ? 'bg-green-500' : 'bg-gray-300',
                          saving === channel.id && 'opacity-50'
                        )}
                      >
                        <span
                          className={clsx(
                            'absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform',
                            channel.is_active ? 'left-5.5' : 'left-0.5'
                          )}
                        />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )
        })}

        {/* Ungrouped channels */}
        {grouped.ungrouped.length > 0 && (
          <div className="bg-white rounded-xl shadow-sm border-l-4 border-gray-300 overflow-hidden">
            <div className="px-5 py-4">
              <h2 className="font-semibold text-base text-gray-700">{t('channels.otherChannels')}</h2>
            </div>
            <div className="border-t border-gray-100 divide-y divide-gray-50">
              {grouped.ungrouped.map((channel) => (
                <div key={channel.id} className="px-5 py-3 flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <span
                      className={clsx(
                        'w-2 h-2 rounded-full',
                        channel.is_active ? 'bg-green-500' : 'bg-gray-300'
                      )}
                    />
                    <span className="text-sm font-medium text-gray-800">{channel.name}</span>
                    <span className="text-xs text-gray-400">{channel.type}</span>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className={clsx('text-xs', channel.is_active ? 'text-green-600' : 'text-gray-400')}>
                      {channel.is_active ? t('common.active') : t('common.inactive')}
                    </span>
                    <button
                      onClick={() => toggleChannel(channel)}
                      disabled={saving === channel.id}
                      className={clsx(
                        'relative w-11 h-6 rounded-full transition-colors',
                        channel.is_active ? 'bg-green-500' : 'bg-gray-300',
                        saving === channel.id && 'opacity-50'
                      )}
                    >
                      <span
                        className={clsx(
                          'absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform',
                          channel.is_active ? 'left-5.5' : 'left-0.5'
                        )}
                      />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Platform Credential Modal */}
      {/* Meta çok-sayfa seçimi modalı */}
      {pickKey && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 max-h-[90vh] flex flex-col">
            <div className="p-5 border-b border-gray-100">
              <h2 className="font-semibold text-gray-900">Bu firmaya hangi hesabı bağlayalım?</h2>
              <p className="text-xs text-gray-500 mt-1">
                Meta'da birden fazla sayfaya yetki verdiniz. Seçtiğiniz sayfa (ve bağlı Instagram hesabı)
                yalnızca bu firmaya bağlanır; diğerini başka bir firmayı seçip yeniden bağlayabilirsiniz.
              </p>
            </div>
            <div className="p-4 space-y-2 overflow-y-auto">
              {pickPages.map((p) => (
                <button
                  key={p.id}
                  onClick={() => selectMetaPage(p.id)}
                  disabled={pickSelecting !== null}
                  className="w-full flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:border-indigo-400 hover:bg-indigo-50/50 transition-colors text-left disabled:opacity-50"
                >
                  <span className="w-9 h-9 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0">
                    <Facebook size={18} />
                  </span>
                  <span className="flex-1 min-w-0">
                    <span className="block text-sm font-medium text-gray-900 truncate">{p.name}</span>
                    <span className="flex items-center gap-1 text-xs text-gray-500 mt-0.5">
                      {p.has_instagram
                        ? <><Instagram size={12} /> Instagram bağlı</>
                        : <>Instagram bağlı değil</>}
                    </span>
                  </span>
                  {pickSelecting === p.id
                    ? <Loader2 size={16} className="animate-spin text-indigo-600 shrink-0" />
                    : <ChevronRight size={16} className="text-gray-300 shrink-0" />}
                </button>
              ))}
            </div>
            <div className="p-4 border-t border-gray-100 flex justify-end">
              <button
                onClick={() => { setPickKey(null); setPickPages([]) }}
                disabled={pickSelecting !== null}
                className="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700 disabled:opacity-50"
              >
                {t('common.cancel', 'Vazgeç')}
              </button>
            </div>
          </div>
        </div>
      )}

      {credPlatform && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 max-h-[90vh] flex flex-col">
            <div className="flex items-center justify-between p-5 border-b border-gray-100">
              <div>
                <h2 className="font-semibold text-gray-900">{t('channels.apiSettings')}</h2>
                <p className="text-xs text-gray-500 mt-0.5">{platformName}</p>
              </div>
              <button onClick={closeCredModal} className="p-1 text-gray-400 hover:text-gray-600 rounded">
                <X size={18} />
              </button>
            </div>

            <div className="p-5 space-y-4 overflow-y-auto">
              {credLoading ? (
                <div className="flex justify-center py-8">
                  <Loader2 size={24} className="animate-spin text-indigo-600" />
                </div>
              ) : credFields.length === 0 ? (
                <p className="text-sm text-gray-500 text-center py-4">
                  {t('channels.credentialNotDefined')}
                </p>
              ) : (
                <>
                  {credHasExisting && (
                    <div className="bg-green-50 border border-green-200 rounded-lg px-3 py-2 text-xs text-green-700">
                      {t('channels.credentialsExist')}
                    </div>
                  )}

                  {credFields.map((field) => (
                    <div key={field.key}>
                      <div className="flex items-center gap-1.5 mb-1">
                        <label className="block text-sm font-medium text-gray-700">{field.label}</label>
                        <InfoTooltip field={field} />
                      </div>
                      <input
                        type={field.type === 'password' ? 'password' : 'text'}
                        value={credValues[field.key] || ''}
                        onChange={(e) => setCredValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
                        placeholder={credHasExisting ? t('channels.currentValueKept') : t('channels.enterValue')}
                        className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      />
                    </div>
                  ))}
                </>
              )}
            </div>

            <div className="flex items-center justify-end gap-3 p-5 border-t border-gray-100">
              {credSaved && (
                <span className="flex items-center gap-1 text-sm text-green-600">
                  <CheckCircle size={14} /> {t('common.saved')}
                </span>
              )}
              <button onClick={closeCredModal} className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                {t('common.close')}
              </button>
              {credFields.length > 0 && (
                <button
                  onClick={saveCredentials}
                  disabled={credSaving || Object.values(credValues).every((v) => !v)}
                  className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors"
                >
                  {credSaving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
                  {credSaving ? t('common.saving') : t('common.save')}
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
