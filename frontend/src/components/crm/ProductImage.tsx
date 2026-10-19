import { useCallback, useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, Package, X } from 'lucide-react'
import clsx from 'clsx'

/*
 * CRM ürün görselleri (Siparişler + Gecikmeler ortak).
 * Ticimax görsel boyutları: /buyuk/ ~2 MB (yalnız büyütmede), /thumb/ 400×600 ~40 KB (liste).
 * Kutular 2:3 = thumb oranı, object-contain → kırpma yok. thumb açılmazsa büyük görsele düşer.
 */
export const thumbUrl = (u: string) => u.replace(/\/UrunResimleri\/buyuk\//i, '/UrunResimleri/thumb/')

export interface LightboxItem {
  image_url: string | null
  product_name: string | null
  variant_info?: string | null
  quantity?: number | null
}

export function ProductThumb({ url, onClick, className, title }: { url: string | null; onClick?: () => void; className: string; title?: string }) {
  const [src, setSrc] = useState(url ? thumbUrl(url) : null)
  const [broken, setBroken] = useState(false)
  useEffect(() => { setSrc(url ? thumbUrl(url) : null); setBroken(false) }, [url])
  const onError = () => {
    if (url && src !== url) setSrc(url)
    else setBroken(true)
  }
  if (!src || broken) {
    return <div className={clsx(className, 'rounded-lg bg-gray-100 flex items-center justify-center shrink-0')}><Package size={16} className="text-gray-300" /></div>
  }
  return (
    <button type="button" onClick={onClick} title={title || 'Büyüt'} className={clsx(className, 'rounded-lg border border-gray-100 bg-white shrink-0 overflow-hidden flex items-center justify-center cursor-zoom-in hover:border-orange-300')}>
      <img src={src} alt="" loading="lazy" decoding="async" onError={onError} className="max-w-full max-h-full w-auto h-auto object-contain" />
    </button>
  )
}

/* Ürün görseli büyütme penceresi: önce (önbellekteki) thumb, büyük görsel inince ona geçer.
 * Esc / dışarı tıklama kapatır, ← → siparişin diğer ürünleri. */
export function ImageLightbox({ items, index, onIndex, onClose }: { items: LightboxItem[]; index: number; onIndex: (i: number) => void; onClose: () => void }) {
  const it = items[index]
  const full = it?.image_url || ''
  const [loadedSrc, setLoadedSrc] = useState<string | null>(null)
  const multi = items.length > 1
  const go = useCallback((d: number) => onIndex((index + d + items.length) % items.length), [index, items.length, onIndex])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
      else if (e.key === 'ArrowRight' && multi) go(1)
      else if (e.key === 'ArrowLeft' && multi) go(-1)
    }
    document.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = prev }
  }, [onClose, go, multi])

  if (!it) return null
  const showFull = loadedSrc === full

  return (
    <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4" onClick={onClose}>
      <button onClick={onClose} className="absolute top-4 right-4 w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center" aria-label="Kapat">
        <X size={22} />
      </button>
      {multi && (
        <>
          <button onClick={(e) => { e.stopPropagation(); go(-1) }} className="absolute left-3 sm:left-6 w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center" aria-label="Önceki">
            <ChevronLeft size={26} />
          </button>
          <button onClick={(e) => { e.stopPropagation(); go(1) }} className="absolute right-3 sm:right-6 w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center" aria-label="Sonraki">
            <ChevronRight size={26} />
          </button>
        </>
      )}
      <div className="flex flex-col items-center max-w-[min(92vw,900px)]" onClick={(e) => e.stopPropagation()}>
        {/* Kutu görselin kendisi: sabit beyaz çerçeve yok, oran görselden gelir.
            Büyük görsel inene kadar aynı oranlı thumb gösterilir, sonra yerine geçer. */}
        <div className="relative">
          <img
            key={(showFull ? 'f' : 't') + full}
            src={showFull ? full : thumbUrl(full)}
            alt={it.product_name || ''}
            className="block w-auto h-auto rounded-lg object-contain"
            style={{ height: 'min(80vh, 1000px)', maxWidth: '92vw' }}
          />
          {!showFull && <img src={full} alt="" onLoad={() => setLoadedSrc(full)} className="hidden" />}
          {!showFull && <div className="absolute bottom-3 right-3 text-[11px] px-2 py-1 rounded bg-black/50 text-white">Yükleniyor…</div>}
        </div>
        <div className="mt-3 text-center text-white">
          <div className="text-sm font-medium line-clamp-2">{it.product_name}</div>
          <div className="text-xs text-white/70 mt-0.5">
            {[it.variant_info, it.quantity ? `${it.quantity} adet` : null, multi ? `${index + 1} / ${items.length}` : null].filter(Boolean).join(' · ')}
          </div>
        </div>
      </div>
    </div>
  )
}
