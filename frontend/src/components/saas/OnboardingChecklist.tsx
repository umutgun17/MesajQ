import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2, Circle, X } from 'lucide-react'
import { billingApi, type OnboardingStep } from '@/lib/billingApi'
import { useAuth } from '@/contexts/AuthContext'

/** Ana sayfa kurulum listesi: adımlar tamamlanınca ya da kullanıcı kapatınca gizlenir. */
export default function OnboardingChecklist() {
  const { user } = useAuth()
  const [steps, setSteps] = useState<OnboardingStep[] | null>(null)
  const [hidden, setHidden] = useState(false)
  useEffect(() => {
    if (!user || user.is_super_admin || user.onboarded_at) return
    billingApi.onboarding().then((d) => { setSteps(d.steps); if (d.done) setHidden(true) }).catch(() => {})
  }, [user])
  if (!steps || hidden || user?.onboarded_at) return null
  const done = steps.filter((s) => s.done).length
  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-base font-extrabold text-gray-900">Kurulum: {done}/{steps.length} tamamlandı</h2>
          <p className="text-xs text-gray-500 mt-0.5">Birkaç adımda hazırsınız. Takıldığınız yerde destek ekibi yanınızda.</p>
        </div>
        <button onClick={() => { setHidden(true); billingApi.dismissOnboarding().catch(() => {}) }} className="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400" title="Listeyi kapat"><X size={16} /></button>
      </div>
      <div className="mt-3 h-1.5 rounded-full bg-gray-100 overflow-hidden"><div className="h-full bg-[var(--primary)] rounded-full transition-all" style={{ width: `${(done / steps.length) * 100}%` }} /></div>
      <ul className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-2">
        {steps.map((s) => (
          <li key={s.key}>
            <Link to={s.to} className={`flex items-start gap-3 rounded-lg px-3 py-2.5 transition-colors ${s.done ? 'opacity-60' : 'hover:bg-[var(--app-bg)]'}`}>
              {s.done ? <CheckCircle2 size={18} className="text-emerald-500 shrink-0 mt-0.5" /> : <Circle size={18} className="text-gray-300 shrink-0 mt-0.5" />}
              <span><span className={`block text-sm font-semibold ${s.done ? 'line-through text-gray-500' : 'text-gray-900'}`}>{s.title}</span><span className="block text-xs text-gray-500">{s.desc}</span></span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}
