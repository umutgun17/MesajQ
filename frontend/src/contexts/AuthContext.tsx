import { createContext, useContext, useState, useEffect, useCallback, type ReactNode } from 'react'
import i18n from '@/i18n'
import api from '@/lib/api'
import type { User } from '@/types'

interface AuthContextType {
  user: User | null
  isLoading: boolean
  login: (email: string, password: string) => Promise<void>
  signup: (payload: SignupPayload) => Promise<void>
  logout: () => Promise<void>
  refreshUser: () => Promise<void>
  hasPermission: (page: string) => boolean
  hasProduct: (product: string) => boolean
  canUseAi: boolean
  // Süper adminin "kullanıcı gözünden gör" oturumu
  isImpersonating: boolean
  impersonate: (userId: number) => Promise<void>
  stopImpersonation: () => Promise<void>
}

export interface SignupPayload { company: string; name: string; email: string; phone?: string; password: string; platform?: string; terms: boolean; website?: string }

const AuthContext = createContext<AuthContextType | null>(null)

function applyTenantLanguage(u: User) {
  // User's own preference (set via language switcher) takes priority
  if (localStorage.getItem('app_language')) return
  // Otherwise use tenant's default language
  const tenantLang = (u.tenant?.settings as Record<string, string> | null)?.default_language
  if (tenantLang && (tenantLang === 'en' || tenantLang === 'tr')) {
    i18n.changeLanguage(tenantLang)
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isImpersonating, setIsImpersonating] = useState(() => !!localStorage.getItem('impersonator_token'))

  useEffect(() => {
    const token = localStorage.getItem('auth_token')
    if (token) {
      api.get('/auth/me')
        .then((res) => {
          setUser(res.data)
          applyTenantLanguage(res.data)
        })
        .catch(() => {
          localStorage.removeItem('auth_token')
          localStorage.removeItem('auth_user')
          localStorage.removeItem('selected_tenant_id')
        })
        .finally(() => setIsLoading(false))
    } else {
      setIsLoading(false)
    }
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const res = await api.post('/auth/login', { email, password })
    localStorage.setItem('auth_token', res.data.token)
    localStorage.setItem('auth_user', JSON.stringify(res.data.user))
    setUser(res.data.user)
    applyTenantLanguage(res.data.user)
  }, [])

  const signup = useCallback(async (payload: SignupPayload) => {
    const res = await api.post('/public/signup', payload)
    localStorage.setItem('auth_token', res.data.token)
    localStorage.setItem('auth_user', JSON.stringify(res.data.user))
    setUser(res.data.user)
    applyTenantLanguage(res.data.user)
  }, [])

  const refreshUser = useCallback(async () => {
    const res = await api.get('/auth/me')
    setUser(res.data)
    localStorage.setItem('auth_user', JSON.stringify(res.data))
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
      localStorage.removeItem('impersonator_token')
      localStorage.removeItem('impersonator_tenant_id')
      setIsImpersonating(false)
      setUser(null)
    }
  }, [])

  // Süper admin, hedef kullanıcının token'ına geçer; kendi token'ı saklanır
  const impersonate = useCallback(async (userId: number) => {
    const res = await api.post(`/users/${userId}/impersonate`)
    localStorage.setItem('impersonator_token', localStorage.getItem('auth_token') ?? '')
    const tenantId = localStorage.getItem('selected_tenant_id')
    if (tenantId) localStorage.setItem('impersonator_tenant_id', tenantId)
    localStorage.removeItem('selected_tenant_id')
    localStorage.setItem('auth_token', res.data.token)
    localStorage.setItem('auth_user', JSON.stringify(res.data.user))
    setUser(res.data.user)
    setIsImpersonating(true)
  }, [])

  const stopImpersonation = useCallback(async () => {
    // Impersonation token'ını sunucuda sil (süresi dolmuşsa sorun değil)
    try { await api.post('/auth/logout') } catch { /* yut */ }
    const original = localStorage.getItem('impersonator_token')
    localStorage.removeItem('impersonator_token')
    const tenantId = localStorage.getItem('impersonator_tenant_id')
    localStorage.removeItem('impersonator_tenant_id')
    setIsImpersonating(false)
    if (!original) {
      setUser(null)
      return
    }
    localStorage.setItem('auth_token', original)
    if (tenantId) localStorage.setItem('selected_tenant_id', tenantId)
    const res = await api.get('/auth/me')
    setUser(res.data)
    localStorage.setItem('auth_user', JSON.stringify(res.data))
  }, [])

  const hasPermission = useCallback((page: string): boolean => {
    if (!user) return false
    if (user.is_super_admin) return true
    return user.allowed_pages?.includes(page) ?? false
  }, [user])

  // 7 alt ürün ekseni: tenant aboneliği + rol (admin=tümü, agent=inbox)
  const hasProduct = useCallback((product: string): boolean => {
    if (!user) return false
    if (user.is_super_admin) return true
    // allowed_products henüz gelmemişse (eski oturum) sayfa yetkisine düşme
    if (!user.allowed_products) return true
    return user.allowed_products.includes(product)
  }, [user])

  const canUseAi = hasPermission('messages')

  return (
    <AuthContext.Provider value={{ user, isLoading, login, signup, logout, refreshUser, hasPermission, hasProduct, canUseAi, isImpersonating, impersonate, stopImpersonation }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
