import { useTranslation } from 'react-i18next'

export default function LanguageSwitcher() {
  const { i18n } = useTranslation()

  const toggle = () => {
    const next = i18n.language === 'en' ? 'tr' : 'en'
    i18n.changeLanguage(next)
    localStorage.setItem('app_language', next)
  }

  return (
    <button
      onClick={toggle}
      className="flex items-center gap-1 px-2 py-1 text-xs font-semibold text-gray-400 hover:text-gray-700 rounded-md hover:bg-gray-100 transition-colors"
      title={i18n.language === 'en' ? 'Türkçe\'ye geç' : 'Switch to English'}
    >
      <span className={i18n.language === 'en' ? 'text-gray-900' : ''}>EN</span>
      <span className="text-gray-300">/</span>
      <span className={i18n.language === 'tr' ? 'text-gray-900' : ''}>TR</span>
    </button>
  )
}
