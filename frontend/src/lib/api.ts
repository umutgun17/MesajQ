import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

// Token + Tenant interceptor
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  const tenantId = localStorage.getItem('selected_tenant_id')
  if (tenantId) {
    config.headers['X-Tenant-Id'] = tenantId
  }

  return config
})

// 401 interceptor → logout, 422 tenant error → clear tenant selection
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Impersonation token'ı düştüyse (ör. 30 dk doldu) süper admin oturumuna geri dön
      const impersonator = localStorage.getItem('impersonator_token')
      if (impersonator) {
        localStorage.setItem('auth_token', impersonator)
        localStorage.removeItem('impersonator_token')
        const tenantId = localStorage.getItem('impersonator_tenant_id')
        if (tenantId) {
          localStorage.setItem('selected_tenant_id', tenantId)
          localStorage.removeItem('impersonator_tenant_id')
        }
        window.location.href = (import.meta.env.VITE_ROUTER_BASE || '') + '/'
        return Promise.reject(error)
      }
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
      localStorage.removeItem('selected_tenant_id')
      window.location.href = (import.meta.env.VITE_ROUTER_BASE || '') + '/login'
    }
    // Abonelik kapısı (402): faturalama sayfasına yönlendir (zaten oradaysa döngüye girme)
    if (error.response?.status === 402 && error.response?.data?.code === 'subscription_required') {
      const base = import.meta.env.VITE_ROUTER_BASE || ''
      if (!window.location.pathname.startsWith(base + '/billing')) {
        window.location.href = base + '/billing?locked=1'
      }
      return Promise.reject(error)
    }
    // Inactive/invalid tenant → clear selection and reload
    if (error.response?.status === 422 && localStorage.getItem('selected_tenant_id')) {
      const msg = error.response?.data?.message ?? ''
      if (msg.includes('tenant') || msg.includes('Tenant')) {
        localStorage.removeItem('selected_tenant_id')
        window.location.reload()
      }
    }
    return Promise.reject(error)
  }
)

export default api
