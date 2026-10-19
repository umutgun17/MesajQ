// Tenant'a özgü mağaza bilgileri — marka/domain sabitleri koda gömülmez.
// storefront_url tenants.settings'ten gelir (login/me payload'ındaki tenant.settings).

interface UserLike {
  tenant?: { settings?: Record<string, unknown> | null } | null
}

/** Tenant'ın mağaza kök URL'i (sondaki / temizlenir); ayarsızsa boş string. */
export function storefrontUrl(user: UserLike | null | undefined): string {
  const raw = user?.tenant?.settings?.storefront_url
  return typeof raw === 'string' ? raw.replace(/\/+$/, '') : ''
}

/**
 * CRM menü markası: tenant'ın e-ticaret platformuna göre 'ikas' | 'Ticimax'.
 * Süper adminde (tenant yok) TenantSwitcher'ın localStorage'a yazdığı
 * seçili tenant platformu kullanılır; o da yoksa nötr 'Mağaza'.
 */
export function crmBrand(user: UserLike | null | undefined): string {
  // Tenant kullanıcısı: kendi firmasının platformu
  const p = user?.tenant?.settings?.ecommerce_platform as string | undefined
  if (p === 'ikas') return 'ikas'
  if (p === 'ticimax' || user?.tenant) return 'Ticimax'

  // Süper admin: firma seçiliyse o firmanın platformu, seçili değilse 'Tüm'
  if (!localStorage.getItem('selected_tenant_id')) return 'Tüm'
  const selP = localStorage.getItem('selected_tenant_platform')
  if (selP === 'ikas') return 'ikas'
  if (selP === 'ticimax') return 'Ticimax'

  return 'Mağaza'
}
