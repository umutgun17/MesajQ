import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import api from '@/lib/api'
import { Building2 } from 'lucide-react'

interface TenantOption {
  id: number
  name: string
  slug: string
  is_active: boolean
}

export default function TenantSwitcher() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [tenants, setTenants] = useState<TenantOption[]>([])
  const [selectedId, setSelectedId] = useState<string>(
    localStorage.getItem('selected_tenant_id') || ''
  )

  useEffect(() => {
    if (!user?.is_super_admin) return

    api.get('/tenants').then((res) => {
      const list: TenantOption[] = res.data
      setTenants(list)

      // Clear stale selection (tenant deleted or inactive)
      const stored = localStorage.getItem('selected_tenant_id')
      if (stored) {
        const match = list.find((tn) => String(tn.id) === stored)
        if (!match || !match.is_active) {
          localStorage.removeItem('selected_tenant_id')
          localStorage.removeItem('selected_tenant_platform')
          setSelectedId('')
        } else {
          // Menü markası (ikas/Ticimax CRM) için seçili tenant'ın platformunu tazele
          const platform = ((match as { settings?: Record<string, unknown> }).settings?.ecommerce_platform as string) || ''
          if (platform) localStorage.setItem('selected_tenant_platform', platform)
        }
      }
    }).catch(() => {
      // silently fail
    })
  }, [user])

  if (!user?.is_super_admin || tenants.length === 0) {
    return null
  }

  const handleChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const value = e.target.value
    setSelectedId(value)

    if (value) {
      localStorage.setItem('selected_tenant_id', value)
      const sel = tenants.find((tn) => String(tn.id) === value)
      const platform = ((sel as { settings?: Record<string, unknown> } | undefined)?.settings?.ecommerce_platform as string) || ''
      if (platform) localStorage.setItem('selected_tenant_platform', platform)
      else localStorage.removeItem('selected_tenant_platform')
    } else {
      localStorage.removeItem('selected_tenant_id')
      localStorage.removeItem('selected_tenant_platform')
    }

    // Reload to re-fetch all data with new tenant scope
    window.location.reload()
  }

  return (
    <div className="hidden md:flex items-center gap-1.5">
      <Building2 size={13} className="text-gray-400" />
      <select
        value={selectedId}
        onChange={handleChange}
        title={t('tenant.company')}
        className="max-w-[150px] px-2 py-1.5 bg-gray-100 text-gray-700 text-xs font-medium border border-transparent rounded-lg focus:outline-none focus:ring-1 focus:ring-[var(--primary)] appearance-none cursor-pointer hover:bg-gray-200 transition-colors"
      >
        <option value="">{t('tenant.allCompanies')}</option>
        {tenants.map((tn) => (
          <option key={tn.id} value={String(tn.id)} disabled={!tn.is_active}>
            {tn.name}{!tn.is_active ? ` (${t('tenant.inactive')})` : ''}
          </option>
        ))}
      </select>
    </div>
  )
}
