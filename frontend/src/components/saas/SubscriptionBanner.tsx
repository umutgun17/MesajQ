import { Link } from 'react-router-dom'
import { AlertTriangle, Clock, MailWarning } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { billingApi } from '@/lib/billingApi'

/** Üst bant: deneme süresi, ödeme bekliyor, e-posta doğrulanmadı. */
export default function SubscriptionBanner() {
  const { user } = useAuth()
  if (!user || user.is_super_admin) return null
  const sub = user.subscription
  const items: { tone: string; icon: React.ReactNode; text: string; cta?: string }[] = []

  if (sub?.status === 'trialing' && sub.days_left !== null && sub.days_left <= 7) {
    items.push({ tone: 'bg-sky-600', icon: <Clock size={14} />, text: sub.days_left === 0 ? 'Deneme süreniz bugün bitiyor.' : `Deneme sürenizin bitmesine ${sub.days_left} gün kaldı.`, cta: 'Plan seç' })
  }
  if (sub?.status === 'past_due') items.push({ tone: 'bg-amber-600', icon: <AlertTriangle size={14} />, text: 'Son ödeme alınamadı. Hizmetin kesilmemesi için ödeme yönteminizi güncelleyin.', cta: 'Ödemeye git' })
  if (sub && !sub.grants_access) items.push({ tone: 'bg-red-600', icon: <AlertTriangle size={14} />, text: 'Aboneliğiniz sona erdi. Verileriniz korunuyor; devam etmek için plan seçin.', cta: 'Plan seç' })
  if (user.email_verified === false) items.push({ tone: 'bg-gray-700', icon: <MailWarning size={14} />, text: 'E-posta adresinizi doğrulayın (gelen kutunuzdaki bağlantı).', cta: 'Yeniden gönder' })

  if (items.length === 0) return null
  const it = items[0]
  return (
    <div className={`h-8 ${it.tone} text-white flex items-center gap-2 px-4 text-xs md:text-[13px]`}>
      {it.icon}<span className="truncate">{it.text}</span>
      {it.cta === 'Yeniden gönder'
        ? <button onClick={() => billingApi.resendVerification().catch(() => {})} className="ml-auto shrink-0 px-2.5 py-0.5 rounded-md bg-white/15 hover:bg-white/25 font-semibold">{it.cta}</button>
        : <Link to="/billing" className="ml-auto shrink-0 px-2.5 py-0.5 rounded-md bg-white/15 hover:bg-white/25 font-semibold">{it.cta}</Link>}
    </div>
  )
}
