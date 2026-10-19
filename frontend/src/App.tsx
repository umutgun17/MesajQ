import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider } from '@/contexts/AuthContext'
import AppLayout from '@/components/layout/AppLayout'
import LoginPage from '@/pages/LoginPage'
import SignupPage from '@/pages/saas/SignupPage'
import ForgotPasswordPage from '@/pages/saas/ForgotPasswordPage'
import ResetPasswordPage from '@/pages/saas/ResetPasswordPage'
import BillingPage from '@/pages/saas/BillingPage'
import AdminSubscriptionsPage from '@/pages/saas/AdminSubscriptionsPage'
import HomePage from '@/pages/HomePage'
import DashboardPage from '@/pages/DashboardPage'
import MessagesPage from '@/pages/MessagesPage'
import CommentsPage from '@/pages/CommentsPage'
import EmailArchivePage from '@/pages/EmailArchivePage'
import ChannelsPage from '@/pages/ChannelsPage'
import KnowledgeBasePage from '@/pages/KnowledgeBasePage'
import AiPage from '@/pages/AiPage'
import AiReplySettingsPage from '@/pages/AiReplySettingsPage'
import MessagesMetaSettingsPage from '@/pages/MessagesMetaSettingsPage'
import UsersPage from '@/pages/UsersPage'
import UserActivityPage from '@/pages/UserActivityPage'
import SettingsPage from '@/pages/SettingsPage'
import ProfilePage from '@/pages/ProfilePage'
import ChatbotSettingsPage from '@/pages/ChatbotSettingsPage'
import ChatConversationsPage from '@/pages/ChatConversationsPage'
import ChatProductAnalyticsPage from '@/pages/ChatProductAnalyticsPage'
import ReportsMessageAnalysisPage from '@/pages/reports/ReportsMessageAnalysisPage'

export default function App() {
  return (
    <BrowserRouter basename={import.meta.env.VITE_ROUTER_BASE || '/'}>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/kayit" element={<SignupPage />} />
          <Route path="/sifremi-unuttum" element={<ForgotPasswordPage />} />
          <Route path="/sifre-sifirla" element={<ResetPasswordPage />} />
          <Route element={<AppLayout />}>
            <Route path="/" element={<HomePage />} />
            <Route path="/messages" element={<MessagesPage />} />
            <Route path="/messages/dashboard" element={<DashboardPage />} />
            <Route path="/comments" element={<CommentsPage />} />
            <Route path="/email-archive" element={<EmailArchivePage />} />
            <Route path="/chatbot" element={<ChatbotSettingsPage />} />
            <Route path="/chatbot/konusmalar" element={<ChatConversationsPage />} />
            <Route path="/chatbot/urun-performansi" element={<ChatProductAnalyticsPage />} />
            <Route path="/reports/messages" element={<ReportsMessageAnalysisPage />} />
            <Route path="/settings/knowledge-base" element={<KnowledgeBasePage />} />
            <Route path="/settings/ai" element={<AiPage />} />
            <Route path="/settings/ai-reply" element={<AiReplySettingsPage />} />
            <Route path="/settings/meta-app" element={<MessagesMetaSettingsPage />} />
            <Route path="/ads/meta-app" element={<Navigate to="/settings/meta-app" replace />} />
            <Route path="/billing" element={<BillingPage />} />
            <Route path="/admin/subscriptions" element={<AdminSubscriptionsPage />} />
            <Route path="/settings/channels" element={<ChannelsPage />} />
            <Route path="/settings/users" element={<UsersPage />} />
            <Route path="/settings/users/activity" element={<UserActivityPage />} />
            <Route path="/settings/general" element={<SettingsPage />} />
            <Route path="/profile" element={<ProfilePage />} />
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}
