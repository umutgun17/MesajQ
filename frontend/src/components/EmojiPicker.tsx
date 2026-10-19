import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import clsx from 'clsx'

interface Props {
  onPick: (emoji: string) => void
  onClose: () => void
}

const RECENT_KEY = 'yunbi_recent_emojis'
const RECENT_MAX = 16

// Kategori ikonu = listedeki ilk emoji. Kategori adları i18n'den (emoji.categories.*).
const CATEGORIES: { key: string; emojis: string[] }[] = [
  {
    key: 'smileys',
    emojis: [
      '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '😊', '😇', '🙂', '😉',
      '😌', '😍', '🥰', '😘', '😗', '😙', '😚', '😋', '😛', '😜', '🤪', '😝',
      '🤗', '🤭', '🤔', '🤨', '😐', '😶', '😏', '😒', '🙄', '😬', '😮', '😯',
      '😲', '🥱', '😴', '🤤', '😪', '😵', '🤯', '🥳', '😎', '🤓', '🧐', '🥺',
      '😳', '😢', '😭', '😤', '😠', '😡', '🤬', '😱', '😨', '😰', '😥', '😓',
      '🤒', '🤕', '🤢', '🤧', '😷', '🥵', '🥶', '🥴', '😈', '🤠', '🤑', '🤡',
    ],
  },
  {
    key: 'gestures',
    emojis: [
      '👍', '👎', '👌', '🤌', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆',
      '👇', '☝️', '✋', '🤚', '🖐️', '👋', '🤏', '💪', '🙏', '👏', '🙌', '🤲',
      '🤝', '✍️', '💅', '🤳', '👀', '👁️', '🙈', '🙉', '🙊', '💁‍♀️', '💁‍♂️', '🙋‍♀️',
      '🙋‍♂️', '🤷‍♀️', '🤷‍♂️', '🙆‍♀️', '🙆‍♂️', '🙅‍♀️', '🙅‍♂️', '🧏‍♀️', '🧏‍♂️', '💃', '🕺', '👩‍💻',
    ],
  },
  {
    key: 'hearts',
    emojis: [
      '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔', '❣️', '💕',
      '💞', '💓', '💗', '💖', '💘', '💝', '💟', '♥️', '😻', '💑', '💏', '🫶',
    ],
  },
  {
    key: 'celebration',
    emojis: [
      '🎉', '🎊', '🎁', '🎈', '🎂', '🥂', '🍾', '✨', '⭐', '🌟', '💫', '🔥',
      '💯', '🏆', '🥇', '🎖️', '🎗️', '🌈', '☀️', '🌸', '🌺', '🌹', '🌷', '💐',
      '✅', '❌', '⚠️', '❗', '❓', '💬', '💤', '💥', '💦', '🎵', '🎶', '📣',
    ],
  },
  {
    key: 'objects',
    emojis: [
      '🛍️', '🛒', '📦', '🚚', '✈️', '💳', '💰', '💸', '🏷️', '🧾', '⚖️', '📏',
      '👟', '👞', '👠', '👡', '👢', '🥾', '👜', '👝', '🎒', '👗', '👔', '👕',
      '👖', '🧥', '🧣', '🧤', '🧦', '🕶️', '⌚', '💍', '📞', '📱', '💻', '✉️',
      '📧', '📮', '⏰', '🕐', '📅', '📆', '📌', '📍', '🔒', '🔑', '🛠️', '🇹🇷',
    ],
  },
]

function loadRecent(): string[] {
  try {
    const raw = localStorage.getItem(RECENT_KEY)
    const arr = raw ? JSON.parse(raw) : []
    return Array.isArray(arr) ? arr.filter((e) => typeof e === 'string').slice(0, RECENT_MAX) : []
  } catch {
    return []
  }
}

export default function EmojiPicker({ onPick, onClose }: Props) {
  const { t } = useTranslation()
  const [recent, setRecent] = useState<string[]>(loadRecent)
  const [activeCategory, setActiveCategory] = useState(CATEGORIES[0].key)
  const panelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const clickHandler = (e: MouseEvent) => {
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) onClose()
    }
    const keyHandler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('mousedown', clickHandler)
    document.addEventListener('keydown', keyHandler)
    return () => {
      document.removeEventListener('mousedown', clickHandler)
      document.removeEventListener('keydown', keyHandler)
    }
  }, [onClose])

  // Seçim panelı kapatmaz — art arda birden fazla emoji eklenebilsin.
  const handlePick = (emoji: string) => {
    onPick(emoji)
    setRecent((prev) => {
      const next = [emoji, ...prev.filter((e) => e !== emoji)].slice(0, RECENT_MAX)
      try {
        localStorage.setItem(RECENT_KEY, JSON.stringify(next))
      } catch {
        // localStorage dolu/kapalıysa son-kullanılanlar sadece bu oturumda yaşar
      }
      return next
    })
  }

  const active = CATEGORIES.find((c) => c.key === activeCategory) ?? CATEGORIES[0]

  return (
    <div
      ref={panelRef}
      className="w-80 max-w-full bg-white border border-gray-200 rounded-xl shadow-xl flex flex-col overflow-hidden"
    >
      {/* Başlık + kategori sekmeleri */}
      <div className="flex items-center gap-1 px-2 py-1.5 border-b border-gray-100">
        {CATEGORIES.map((c) => (
          <button
            key={c.key}
            onClick={() => setActiveCategory(c.key)}
            title={t(`emoji.categories.${c.key}`)}
            className={clsx(
              'w-8 h-8 flex items-center justify-center text-base rounded-lg transition-colors',
              c.key === activeCategory ? 'bg-[var(--primary-soft)]' : 'hover:bg-gray-100',
            )}
          >
            {c.emojis[0]}
          </button>
        ))}
        <div className="flex-1" />
        <button onClick={onClose} className="p-1 hover:bg-gray-100 rounded-lg text-gray-500">
          <X size={15} />
        </button>
      </div>

      <div className="overflow-y-auto max-h-64 p-2">
        {recent.length > 0 && (
          <>
            <div className="px-1 pb-1 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
              {t('emoji.recent')}
            </div>
            <div className="grid grid-cols-8 gap-0.5 mb-2">
              {recent.map((e) => (
                <button
                  key={`r-${e}`}
                  onClick={() => handlePick(e)}
                  className="w-8 h-8 flex items-center justify-center text-xl rounded-lg hover:bg-[var(--app-bg)] transition-colors"
                >
                  {e}
                </button>
              ))}
            </div>
            <div className="px-1 pb-1 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
              {t(`emoji.categories.${active.key}`)}
            </div>
          </>
        )}
        <div className="grid grid-cols-8 gap-0.5">
          {active.emojis.map((e) => (
            <button
              key={e}
              onClick={() => handlePick(e)}
              className="w-8 h-8 flex items-center justify-center text-xl rounded-lg hover:bg-[var(--app-bg)] transition-colors"
            >
              {e}
            </button>
          ))}
        </div>
      </div>
    </div>
  )
}
