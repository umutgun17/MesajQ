import { useState } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { User, Save, Lock, CheckCircle, AlertCircle } from 'lucide-react'

export default function ProfilePage() {
  const { t } = useTranslation()
  const { user, refreshUser } = useAuth()

  // Profile form
  const [name, setName] = useState(user?.name ?? '')
  const [profileSaving, setProfileSaving] = useState(false)
  const [profileSuccess, setProfileSuccess] = useState(false)
  const [profileError, setProfileError] = useState('')

  // Password form
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [passwordSaving, setPasswordSaving] = useState(false)
  const [passwordSuccess, setPasswordSuccess] = useState(false)
  const [passwordError, setPasswordError] = useState('')

  const handleProfileSave = async () => {
    setProfileSaving(true)
    setProfileSuccess(false)
    setProfileError('')
    try {
      await api.put('/auth/profile', { name })
      await refreshUser()
      setProfileSuccess(true)
      setTimeout(() => setProfileSuccess(false), 3000)
    } catch (err: unknown) {
      const error = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
      const errors = error.response?.data?.errors
      if (errors) {
        setProfileError(Object.values(errors).flat().join(' '))
      } else {
        setProfileError(error.response?.data?.message ?? t('common.error'))
      }
    } finally {
      setProfileSaving(false)
    }
  }

  const handlePasswordChange = async () => {
    setPasswordSaving(true)
    setPasswordSuccess(false)
    setPasswordError('')
    try {
      await api.post('/auth/change-password', {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      })
      setPasswordSuccess(true)
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')
      setTimeout(() => setPasswordSuccess(false), 3000)
    } catch (err: unknown) {
      const error = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
      const errors = error.response?.data?.errors
      if (errors) {
        setPasswordError(Object.values(errors).flat().join(' '))
      } else {
        setPasswordError(error.response?.data?.message ?? t('common.error'))
      }
    } finally {
      setPasswordSaving(false)
    }
  }

  const roleLabels: Record<string, string> = {
    admin: t('roles.admin'),
    moderator: t('roles.moderator'),
    agent: t('roles.agent'),
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-extrabold text-gray-900">{t('profile.title')}</h1>

      {/* User Info Header */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div className="flex items-center gap-4">
          <div className="w-16 h-16 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center text-2xl font-semibold">
            {user?.name?.charAt(0).toUpperCase()}
          </div>
          <div>
            <h2 className="text-lg font-semibold text-gray-900">{user?.name}</h2>
            <p className="text-sm text-gray-500">{user?.email}</p>
            <span className="inline-block mt-1 px-2 py-0.5 bg-gray-100 text-gray-600 text-xs font-medium rounded capitalize">
              {roleLabels[user?.role ?? ''] ?? user?.role}
            </span>
          </div>
        </div>
      </div>

      {/* Profile Info */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 className="font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <User size={18} /> {t('profile.profileInfo')}
        </h2>
        <div className="space-y-4 max-w-md">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('profile.fullName')}</label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('profile.email')}</label>
            <p className="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-500">{user?.email}</p>
          </div>
        </div>
        <div className="mt-6 flex items-center gap-3">
          <button
            onClick={handleProfileSave}
            disabled={profileSaving}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors"
          >
            <Save size={16} />
            {profileSaving ? t('common.saving') : t('common.save')}
          </button>
          {profileSuccess && (
            <span className="flex items-center gap-1 text-sm text-green-600">
              <CheckCircle size={16} /> {t('common.saved')}
            </span>
          )}
          {profileError && (
            <span className="flex items-center gap-1 text-sm text-red-600">
              <AlertCircle size={16} /> {profileError}
            </span>
          )}
        </div>
      </div>

      {/* Change Password */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 className="font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <Lock size={18} /> {t('profile.changePassword')}
        </h2>
        <div className="space-y-4 max-w-md">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('profile.currentPassword')}</label>
            <input
              type="password"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('profile.newPassword')}</label>
            <input
              type="password"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('profile.confirmPassword')}</label>
            <input
              type="password"
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
        </div>
        <div className="mt-6 flex items-center gap-3">
          <button
            onClick={handlePasswordChange}
            disabled={passwordSaving || !currentPassword || !newPassword || !confirmPassword}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors"
          >
            <Lock size={16} />
            {passwordSaving ? t('profile.changingPassword') : t('profile.changePassword')}
          </button>
          {passwordSuccess && (
            <span className="flex items-center gap-1 text-sm text-green-600">
              <CheckCircle size={16} /> {t('profile.passwordChanged')}
            </span>
          )}
          {passwordError && (
            <span className="flex items-center gap-1 text-sm text-red-600">
              <AlertCircle size={16} /> {passwordError}
            </span>
          )}
        </div>
      </div>
    </div>
  )
}
