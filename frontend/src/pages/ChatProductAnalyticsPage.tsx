import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import api from '@/lib/api'
import {
  Eye, MousePointerClick, Wallet, Percent, ShoppingBag, RefreshCw,
  SearchX, ChevronDown, ArrowUpDown, Info, TrendingDown,
} from 'lucide-react'

/* AI Chatbot — ÜRÜN PERFORMANSI (bkz docs/ai-chatbot.md).
   Huni: bot kaç kart gösterdi → kaçı tıklandı → kaçı siparişe döndü.

   Gösterim chat_messages.payload->products'tan, tıklama/sipariş search_events'ten
   gelir (widget postEvent'te sid=oturum anahtarı yollar, atıf cron'u conversion
   yazar). Ciro ATIF EDİLEN SİPARİŞİN TOPLAMIDIR — yalnız tıklanan ürünün değil. */

type Product = {
  id: number
  name: string
  url: string | null
  image: string | null
  price: number
  in_stock: boolean | null
  impressions: number
  sessions: number
  avg_position: number | null
  clicks: number
  click_sessions: number
  orders: number
  revenue: number
  ctr: number
}

type Data = {
  range_days: number
  includes_test: boolean
  summary: {
    impressions: number; distinct_products: number; clicks: number; ctr: number
    orders: number; revenue: number
    sessions: number; sessions_with_products: number; click_sessions: number
  }
  products: Product[]
  queries: { q: string; searches: number; clicks: number; avg_results: number }[]
  zero_result_queries: { q: string; cnt: number }[]
  orders: {
    order_no: string | null; amount: number; order_date: string
    product_id: number | null; product_name: string | null; product_url: string | null
    raw_query: string | null; searched_at: string | null; chat_session_id: number
  }[]
}

const num = (n: number) => (n ?? 0).toLocaleString('tr-TR')
const money = (n: number) =>
  (n ?? 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺'
const pct = (n: number) => '%' + (Math.round((n ?? 0) * 10) / 10).toLocaleString('tr-TR')
const dt = (s: string | null) => (s ? s.slice(8, 10) + '.' + s.slice(5, 7) + ' ' + s.slice(11, 16) : '')
const thumb = (u: string | null) => (u ? u.replace('/buyuk/', '/thumb/') : null)

function StatCard({ icon, label, value, sub, tone = 'indigo' }: {
  icon: ReactNode; label: string; value: string; sub?: string; tone?: string
}) {
  const tones: Record<string, string> = {
    indigo: 'bg-indigo-50 text-indigo-600', amber: 'bg-amber-50 text-amber-600',
    sky: 'bg-sky-50 text-sky-600', emerald: 'bg-emerald-50 text-emerald-600', rose: 'bg-rose-50 text-rose-600',
  }
  return (
    <div className="bg-white border border-gray-200 rounded-xl p-4 flex items-start gap-3">
      <div className={`w-11 h-11 rounded-2xl flex items-center justify-center flex-none ${tones[tone]}`}>{icon}</div>
      <div className="min-w-0">
        <div className="text-xs text-gray-500 font-medium">{label}</div>
        <div className="text-xl font-extrabold text-gray-900 leading-tight mt-0.5">{value}</div>
        {sub && <div className="text-[11px] text-gray-400 mt-0.5">{sub}</div>}
      </div>
    </div>
  )
}

type SortKey = 'revenue' | 'orders' | 'clicks' | 'ctr' | 'impressions'

const COLS: { key: SortKey; label: string; hint: string }[] = [
  { key: 'impressions', label: 'Gösterim', hint: 'Kart sohbette kaç kez gösterildi' },
  { key: 'clicks', label: 'Tıklama', hint: 'Karta kaç kez tıklandı' },
  { key: 'ctr', label: 'CTR', hint: 'Tıklama / gösterim' },
  { key: 'orders', label: 'Sipariş', hint: 'Tıklamadan 24 saat içinde gelen sipariş' },
  { key: 'revenue', label: 'Ciro', hint: 'Atfedilen siparişlerin TOPLAM tutarı (yalnız bu ürünün değil)' },
]

export default function ChatProductAnalyticsPage() {
  const [data, setData] = useState<Data | null>(null)
  const [loading, setLoading] = useState(true)
  const [range, setRange] = useState(30)
  const [includeTest, setIncludeTest] = useState(false)
  const [sort, setSort] = useState<SortKey>('revenue')
  const [zeroOpen, setZeroOpen] = useState(false)
  const [salesOpen, setSalesOpen] = useState(true)

  const load = useCallback(() => {
    setLoading(true)
    api.get<Data>('/chat/product-analytics', { params: { range, test: includeTest ? 1 : 0 } })
      .then((r) => setData(r.data))
      .catch(() => setData(null))
      .finally(() => setLoading(false))
  }, [range, includeTest])

  useEffect(() => { load() }, [load])

  const s = data?.summary
  const products = useMemo(() => {
    const list = [...(data?.products ?? [])]
    list.sort((a, b) => (b[sort] ?? 0) - (a[sort] ?? 0) || b.impressions - a.impressions)
    return list
  }, [data, sort])

  // Huni: öneri gören oturum → tıklayan oturum
  const clickRate = s && s.sessions_with_products > 0
    ? (s.click_sessions * 100) / s.sessions_with_products : 0

  return (
    <div className="space-y-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-2xl font-extrabold text-gray-900">Chatbot Ürün Performansı</h1>
          <p className="text-xs text-gray-500 mt-0.5">
            Botun önerdiği ürünler: kaç kez gösterildi, kaçı tıklandı, kaçı siparişe döndü.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <label className="flex items-center gap-1.5 text-xs text-gray-500 mr-1 cursor-pointer select-none">
            <input type="checkbox" checked={includeTest} onChange={(e) => setIncludeTest(e.target.checked)}
              className="rounded border-gray-300" />
            Test trafiği
          </label>
          {[7, 30, 90].map((r) => (
            <button key={r} onClick={() => setRange(r)}
              className={`text-xs font-semibold px-3 py-1.5 rounded-lg border ${range === r
                ? 'bg-indigo-600 border-indigo-600 text-white'
                : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'}`}>
              {r} gün
            </button>
          ))}
          <button onClick={load} title="Yenile"
            className="p-2 rounded-lg border border-gray-200 bg-white text-gray-500 hover:bg-gray-50">
            <RefreshCw size={15} className={loading ? 'animate-spin' : ''} />
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <StatCard icon={<Eye size={18} />} label="Ürün gösterimi" value={loading ? '…' : num(s?.impressions ?? 0)}
          sub={`${num(s?.distinct_products ?? 0)} farklı ürün`} />
        <StatCard icon={<MousePointerClick size={18} />} tone="sky" label="Tıklama"
          value={loading ? '…' : num(s?.clicks ?? 0)} sub={`${num(s?.click_sessions ?? 0)} sohbetten`} />
        <StatCard icon={<Percent size={18} />} tone="amber" label="CTR"
          value={loading ? '…' : pct(s?.ctr ?? 0)} sub="tıklama / gösterim" />
        <StatCard icon={<ShoppingBag size={18} />} tone="rose" label="Sipariş"
          value={loading ? '…' : num(s?.orders ?? 0)} sub="tıklamadan 24 saat içinde" />
        <StatCard icon={<Wallet size={18} />} tone="emerald" label="Sohbetten ciro"
          value={loading ? '…' : money(s?.revenue ?? 0)} sub="atfedilen sipariş tutarı" />
      </div>

      {/* Huni — üç adım tek satırda */}
      <div className="bg-white border border-gray-200 rounded-xl p-4">
        <div className="text-sm font-semibold text-gray-800 mb-3">Öneri hunisi</div>
        <div className="grid grid-cols-3 gap-3 text-center">
          {[
            { n: s?.sessions ?? 0, l: 'Sohbet', d: 'Toplam oturum' },
            { n: s?.sessions_with_products ?? 0, l: 'Ürün önerilen', d: 'Bot kart gösterdi' },
            { n: s?.click_sessions ?? 0, l: 'Karta tıklayan', d: pct(clickRate) + ' (öneri görenlerin)' },
          ].map((x, i) => (
            <div key={i} className="rounded-lg bg-gray-50 border border-gray-100 py-3">
              <div className="text-2xl font-extrabold text-gray-900 tabular-nums">{loading ? '…' : num(x.n)}</div>
              <div className="text-xs font-semibold text-gray-700 mt-0.5">{x.l}</div>
              <div className="text-[11px] text-gray-400">{x.d}</div>
            </div>
          ))}
        </div>
      </div>

      {/* Ürün tablosu */}
      <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <div className="px-4 py-3 border-b border-gray-100 flex items-center gap-2 flex-wrap">
          <span className="font-semibold text-gray-800 text-sm">Önerilen ürünler</span>
          <span className="text-[11px] text-gray-400">— sütun başlığına tıklayarak sırala</span>
          <span className="ml-auto flex items-center gap-1 text-[11px] text-gray-400">
            <Info size={12} /> Ciro, atfedilen siparişin tamamıdır
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm min-w-[720px]">
            <thead>
              <tr className="text-[11px] uppercase tracking-wide text-gray-400 bg-gray-50/70">
                <th className="text-left font-semibold px-4 py-2">Ürün</th>
                <th className="text-right font-semibold px-2 py-2" title="Kartın ortalama sırası">Sıra</th>
                {COLS.map((c) => (
                  <th key={c.key} title={c.hint}
                    className="text-right font-semibold px-3 py-2 cursor-pointer hover:text-gray-700 select-none"
                    onClick={() => setSort(c.key)}>
                    <span className={`inline-flex items-center gap-1 ${sort === c.key ? 'text-indigo-600' : ''}`}>
                      {c.label}<ArrowUpDown size={11} />
                    </span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {!loading && products.length === 0 && (
                <tr><td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-400">
                  Bu aralıkta veri yok. Widget kapalıysa (kota dolu) hiç gösterim oluşmaz.
                </td></tr>
              )}
              {products.map((p) => (
                <tr key={p.id} className="hover:bg-gray-50/60">
                  <td className="px-4 py-2">
                    <div className="flex items-center gap-2.5 min-w-0">
                      {thumb(p.image)
                        ? <img src={thumb(p.image)!} alt="" loading="lazy"
                            className="w-8 h-12 object-cover rounded flex-none bg-gray-100"
                            onError={(e) => { (e.target as HTMLImageElement).style.visibility = 'hidden' }} />
                        : <div className="w-8 h-12 rounded flex-none bg-gray-100" />}
                      <div className="min-w-0">
                        <div className="text-gray-800 truncate max-w-[300px]">
                          {p.url
                            ? <a href={p.url} target="_blank" rel="noreferrer" className="hover:text-indigo-600 hover:underline">{p.name}</a>
                            : p.name}
                        </div>
                        <div className="text-[11px] text-gray-400">
                          {money(p.price)}
                          {p.in_stock === false && <span className="ml-2 text-rose-500 font-semibold">tükendi</span>}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td className="px-2 py-2 text-right text-[11px] text-gray-400 tabular-nums">
                    {p.avg_position != null ? `${p.avg_position}.` : '—'}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums text-gray-600">{num(p.impressions)}</td>
                  <td className="px-3 py-2 text-right tabular-nums text-gray-900 font-semibold">{num(p.clicks)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    <span className={p.ctr >= 10 ? 'text-emerald-600 font-semibold' : 'text-gray-500'}>{pct(p.ctr)}</span>
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums text-gray-600">{p.orders || '—'}</td>
                  <td className="px-3 py-2 text-right tabular-nums font-semibold text-emerald-700">
                    {p.revenue > 0 ? money(p.revenue) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="grid lg:grid-cols-2 gap-4">
        {/* Sohbet aramaları */}
        <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
          <div className="px-4 py-3 border-b border-gray-100 font-semibold text-gray-800 text-sm">
            Botun yaptığı aramalar
            <span className="text-[11px] font-normal text-gray-400 ml-2">— müşteri ne istedi, kaç sonuç çıktı</span>
          </div>
          <div className="divide-y divide-gray-50 max-h-[420px] overflow-y-auto">
            {(data?.queries ?? []).length === 0 && (
              <div className="px-4 py-8 text-center text-sm text-gray-400">Henüz arama yok.</div>
            )}
            {(data?.queries ?? []).map((q, i) => (
              <div key={i} className="px-4 py-2 flex items-center gap-3 text-sm">
                <span className="text-gray-800 truncate flex-grow">{q.q}</span>
                <span className="text-[11px] text-gray-400 flex-none tabular-nums" title="Ortalama sonuç sayısı">
                  ~{num(q.avg_results)} sonuç
                </span>
                <span className="text-[11px] text-sky-600 font-semibold flex-none tabular-nums" title="Tıklama">
                  {num(q.clicks)} tık
                </span>
                <span className="font-semibold text-gray-900 flex-none tabular-nums w-8 text-right">{num(q.searches)}</span>
              </div>
            ))}
          </div>
        </div>

        {/* Katalog boşluğu */}
        <div className="bg-white border border-gray-200 rounded-xl overflow-hidden self-start">
          <button onClick={() => setZeroOpen((v) => !v)}
            className={`w-full px-4 py-3 font-semibold text-gray-800 text-sm flex items-center gap-2 hover:bg-gray-50 ${zeroOpen ? 'border-b border-gray-100' : ''}`}>
            <SearchX size={15} className="text-rose-500" /> Sonuç çıkmayan istekler
            <span className="text-[11px] font-normal text-gray-400 truncate">— katalog boşluğu</span>
            <span className="text-[11px] font-semibold text-rose-600 ml-auto flex-none">
              {num((data?.zero_result_queries ?? []).length)}
            </span>
            <ChevronDown size={16} className={`text-gray-400 flex-none transition-transform ${zeroOpen ? 'rotate-180' : ''}`} />
          </button>
          {zeroOpen && (
            <div className="divide-y divide-gray-50">
              {(data?.zero_result_queries ?? []).length === 0 && (
                <div className="px-4 py-8 text-center text-sm text-gray-400">
                  Her istek karşılık buldu — katalog boşluğu görünmüyor.
                </div>
              )}
              {(data?.zero_result_queries ?? []).map((z, i) => (
                <div key={i} className="px-4 py-2 flex items-center gap-3 text-sm">
                  <TrendingDown size={13} className="text-rose-400 flex-none" />
                  <span className="text-gray-800 truncate flex-grow">{z.q}</span>
                  <span className="font-semibold text-gray-900 tabular-nums flex-none">{num(z.cnt)}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Sohbetten gelen satışlar */}
      <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <button onClick={() => setSalesOpen((v) => !v)}
          className={`w-full px-4 py-3 font-semibold text-gray-800 text-sm flex items-center gap-2 hover:bg-gray-50 ${salesOpen ? 'border-b border-gray-100' : ''}`}>
          <Wallet size={15} className="text-emerald-500" /> Sohbetten gelen satışlar
          <span className="text-[11px] font-normal text-gray-400 truncate">— hangi istekten hangi ürün alındı</span>
          <span className="text-[11px] font-semibold text-emerald-600 ml-auto flex-none">
            {num(s?.orders ?? 0)} sipariş · {money(s?.revenue ?? 0)}
          </span>
          <ChevronDown size={16} className={`text-gray-400 flex-none transition-transform ${salesOpen ? 'rotate-180' : ''}`} />
        </button>
        {salesOpen && (
          <div className="divide-y divide-gray-50">
            {(data?.orders ?? []).length === 0 && (
              <div className="px-4 py-8 text-center text-sm text-gray-400">
                Henüz sohbete atfedilen sipariş yok. Atıf saatlik cron ile işlenir.
              </div>
            )}
            {(data?.orders ?? []).map((o, i) => (
              <div key={i} className="px-4 py-2 flex items-center gap-3 text-xs">
                <div className="min-w-0 flex-grow">
                  <div className="text-gray-800 truncate">
                    {o.product_url
                      ? <a href={o.product_url} target="_blank" rel="noreferrer" className="hover:text-indigo-600 hover:underline">{o.product_name}</a>
                      : (o.product_name ?? '—')}
                  </div>
                  <div className="text-[11px] text-gray-400 truncate">
                    {o.raw_query && <span className="text-indigo-500 font-medium">"{o.raw_query}"</span>}
                    {o.searched_at && <> · {dt(o.searched_at)}</>}
                    {o.order_no && <> · sipariş #{o.order_no}</>}
                  </div>
                </div>
                <span className="text-[11px] text-gray-400 flex-none hidden sm:inline">{dt(o.order_date)}</span>
                <span className="font-semibold text-emerald-700 tabular-nums flex-none">{money(o.amount)}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
