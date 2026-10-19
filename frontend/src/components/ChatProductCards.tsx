import { ExternalLink } from 'lucide-react'
import clsx from 'clsx'

/**
 * Botun sohbette gösterdiği ürün kartları — panelde de GÖRSELLİ gösterilir.
 * Müşteri neyi gördüyse personel de aynısını görsün diye hem AI Chatbot
 * "Konuşmalar" transkriptinde hem devir sonrası Mesajlar panelinde kullanılır.
 * Kaynak: chat_messages.payload.products → ChatbotService::chatProductCards().
 */

export type ChatProduct = {
  name: string
  url: string
  image?: string | null
  price?: number | string | null
  discounted_price?: number | string | null
  in_stock?: boolean
}

function money(v: number | string | null | undefined): string | null {
  if (v === null || v === undefined || v === '') return null
  const n = typeof v === 'number' ? v : parseFloat(String(v))
  if (!isFinite(n)) return null
  return n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺'
}

export default function ChatProductCards({ products, className }: {
  products: ChatProduct[]
  className?: string
}) {
  if (!products?.length) return null

  return (
    <div className={clsx('mt-2 grid gap-1.5 sm:grid-cols-2', className)}>
      {products.map((p, i) => {
        const list = money(p.price)
        const sale = money(p.discounted_price)
        const discounted = !!sale && !!list && sale !== list

        return (
          <a
            key={i}
            href={p.url || '#'}
            target="_blank"
            rel="noopener noreferrer"
            title={p.name}
            className="group flex items-center gap-2 rounded-lg border border-gray-200 bg-white p-1.5 no-underline transition-colors hover:border-indigo-300 hover:bg-indigo-50/60"
          >
            {p.image ? (
              <img
                src={p.image}
                alt=""
                loading="lazy"
                className="h-12 w-12 flex-none rounded-md object-cover bg-gray-100"
                onError={(e) => { (e.currentTarget as HTMLImageElement).style.visibility = 'hidden' }}
              />
            ) : (
              <span className="h-12 w-12 flex-none rounded-md bg-gray-100" />
            )}
            <span className="min-w-0 flex-1">
              <span className="block truncate text-[11px] font-medium leading-tight text-gray-800">{p.name}</span>
              <span className="mt-0.5 flex items-baseline gap-1">
                {discounted && <span className="text-[10px] text-gray-400 line-through">{list}</span>}
                <span className="text-[11px] font-semibold text-gray-900">{sale ?? list ?? ''}</span>
              </span>
              {p.in_stock === false && (
                <span className="text-[10px] font-medium text-amber-600">Stokta yok</span>
              )}
            </span>
            <ExternalLink size={11} className="flex-none text-gray-300 group-hover:text-indigo-500" />
          </a>
        )
      })}
    </div>
  )
}
