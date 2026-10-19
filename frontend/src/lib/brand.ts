// Marka sabitleri — derleme zamanında VITE_BRAND_* ile gelir (bkz. frontend/.env.production)
export const BRAND = {
  name: (import.meta.env.VITE_BRAND_NAME as string) || 'Panel',
  tagline: (import.meta.env.VITE_BRAND_TAGLINE as string) || '',
  domain: (import.meta.env.VITE_BRAND_DOMAIN as string) || '',
  siteUrl: (import.meta.env.VITE_BRAND_SITE as string) || '/',
  supportEmail: (import.meta.env.VITE_BRAND_SUPPORT as string) || '',
}
