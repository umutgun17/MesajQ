// ── Auth & Users ──

export interface Tenant {
  id: number
  name: string
  slug: string
  settings: Record<string, unknown> | null
}

export interface User {
  id: number
  name: string
  email: string
  role: 'admin' | 'agent'
  status: 'active' | 'inactive'
  avatar: string | null
  last_login_at: string | null
  is_super_admin: boolean
  tenant: Tenant | null
  allowed_pages: string[]
  allowed_products?: string[]
  // Temsilci ürün→sayfa yetki matrisi; null = varsayılan (Mesaj operasyonel sayfaları)
  page_permissions?: Record<string, string[]> | null
  return_role?: 'none' | 'staff' | 'manager'
  can_staff_approve_returns?: boolean
  can_manager_approve_returns?: boolean
  // Personel notu ekleme — iade ekranını görebilen herkeste açık (salt görüntüleyen personel dâhil)
  can_add_return_notes?: boolean
  // Kusur bildirimi onay/red yetkisi (firma yöneticisi + süper admin)
  is_admin?: boolean
  // SaaS
  is_owner?: boolean
  email_verified?: boolean
  subscription?: import('@/lib/billingApi').SubscriptionInfo | null
  onboarded_at?: string | null
}

// ── Channels ──

export interface Channel {
  id: number
  type: string
  name: string
  category: string
  is_active: boolean
  has_credentials?: boolean
  last_synced_at: string | null
  settings: Record<string, unknown> | null
}

// ── Credential Fields ──

export interface CredentialField {
  key: string
  label: string
  type: 'text' | 'password' | 'url'
  info?: string
  helpUrl?: string
}

// ── Customers ──

export interface Customer {
  id: number
  name: string
  email: string | null
  phone: string | null
  avatar: string | null
  platform_type: string | null
  platform_id: string | null
  metadata?: Record<string, unknown> | null
}

// ── Conversations ──

export interface Conversation {
  id: number
  tenant_id: number
  channel_id: number
  customer_id: number
  type: 'message' | 'comment'
  status: 'open' | 'pending' | 'resolved' | 'closed'
  is_urgent: boolean
  is_read: boolean
  unread_count: number
  last_message_at: string | null
  assigned_to: number | null
  metadata: Record<string, unknown> | null
  customer: Customer | null
  channel: Pick<Channel, 'id' | 'name' | 'type' | 'category'> | null
  assigned_user: { id: number; name: string; avatar: string | null } | null
  context: ConversationContext | null
  latest_message: Pick<Message, 'id' | 'content' | 'sender_type' | 'created_at'> | null
  ai_suggestion?: string | null
  ai_suggestion_confidence?: number | null
  messages?: Message[]
  comments?: Comment[]
}

export interface ConversationContext {
  id: number
  context_type: 'product' | 'order' | 'ticket'
  data: Record<string, unknown>
}

// ── Messages ──

export interface Message {
  id: number | string // otomasyon mesajları sanaldır: 'mkt_<marketing_message_id>'
  conversation_id: number
  sender_type: 'customer' | 'agent' | 'system' | 'automation'
  sender_id: number | null
  content: string
  is_ai_generated: boolean
  ai_original_content: string | null
  penalty_points: number
  penalized_by: number | null
  penalized_by_user?: { id: number; name: string } | null
  metadata: Record<string, unknown> | null
  created_at: string
  sender: { id: number; name: string; avatar: string | null } | null
}

// ── Comments ──

export interface Comment {
  id: number
  conversation_id: number
  post_title: string
  post_url: string | null
  content: string
  status: 'pending' | 'replied' | 'hidden'
  created_at: string
  replies: CommentReply[]
}

export interface CommentReply {
  id: number
  comment_id: number
  user_id: number
  content: string
  is_ai_generated: boolean
  ai_original_content: string | null
  platform_reply_id?: string | null
  created_at: string
  user: { id: number; name: string; avatar: string | null }
}

// ── Dashboard ──

export interface DashboardData {
  overview: {
    total_open: number
    total_pending: number
    total_urgent: number
    total_unread: number
  }
  channels: ChannelStat[]
  recent_conversations: Conversation[]
  ai_performance: {
    total_agent_messages: number
    total_ai_messages: number
    auto_sent: number
    directly_approved: number
    edited_and_sent: number
    ai_usage_rate: number
    approval_rate: number
    avg_reply_time_seconds: number | null
  }
  message_trend: { date: string; count: number }[]
  hourly_distribution: { hour: number; count: number }[]
  agent_performance: AgentPerformance[]
}

export interface AgentPerformance {
  user: {
    id: number
    name: string
    avatar: string | null
    role: 'admin' | 'agent'
    tenant_name?: string | null
  }
  total_messages: number
  conversations_answered: number
  ai_used: number
  directly_approved: number
  edited_and_sent: number
  manual: number
  total_handling_time_seconds: number | null
  avg_reply_time_seconds: number | null
  speed_per_hour: number
  penalty_points: number
  performance_score: number | null
}

export interface ChannelStat {
  channel: Pick<Channel, 'id' | 'name' | 'type' | 'category'>
  total_conversations: number
  answered: number
  unanswered: number
  avg_response_time_seconds: number | null
  ai_used_count: number
  ai_approved_count: number
}

// ── View Counts ──

export interface ViewCounts {
  total: number
  unread: number
  waiting: number
  answered: number
  urgent: number
}

// ── CRM Products ──

export interface Product {
  id: number
  tenant_id: number
  channel_id: number
  platform_product_id: string
  name: string
  description: string | null
  sku: string | null
  barcode: string | null
  brand: string | null
  category: string | null
  category_path: string | null
  price: string | null
  discounted_price: string | null
  cost_price: string | null
  currency: string
  tax_rate: string | null
  stock_quantity: number
  images: string[]
  url: string | null
  storefront_url?: string | null
  admin_url?: string | null
  is_active: boolean
  metadata: Record<string, unknown>
  specifications: Record<string, string> | null
  google_product_category: string | null
  variants?: ProductVariant[]
  review_summary?: ProductReviewSummary | null
  reviews?: ProductReview[]
  channel?: Pick<Channel, 'id' | 'name' | 'type'>
  platform_created_at: string | null
  platform_updated_at: string | null
  created_at: string
  updated_at: string
}

export interface ProductVariant {
  id: number
  product_id: number
  platform_variant_id: string
  sku: string | null
  barcode: string | null
  price: string | null
  discounted_price: string | null
  cost_price: string | null
  stock_quantity: number
  attributes: Record<string, string>
  is_active: boolean
}

export interface ProductReviewSummary {
  id: number
  product_id: number
  trendyol_content_id: number
  avg_rate: string
  rate_count: number
  comment_count: number
  rate_five: number
  rate_four: number
  rate_three: number
  rate_two: number
  rate_one: number
  last_synced_at: string | null
}

export interface ProductReview {
  id: number
  product_id: number
  trendyol_content_id: number
  platform_review_id: string
  rate: number
  user_full_name: string | null
  comment: string | null
  comment_date_text: string | null
  reviewed_at: string | null
  images: ReviewImage[]
  like_count: number
  seller_reply: string | null
  created_at: string
}

export interface ReviewImage {
  id?: number
  url: string
  thumbnailUrl: string
  mediaType?: string
  height?: number
  weight?: number
}

export interface ProductStats {
  total: number
  active: number
  inactive: number
  out_of_stock: number
  total_variants: number
  brands_count: number
}

// ── CRM Customers ──

export interface CrmCustomer {
  id: number
  name: string
  surname: string | null
  email: string | null
  phone: string | null
  gender: number | null
  birth_date: string | null
  city: string | null
  district: string | null
  member_type: string | null
  is_active: boolean
  mail_consent: boolean
  sms_consent: boolean
  total_orders: number
  total_spent: number
  loyalty_points: number
  platform_created_at: string | null
  last_login_at: string | null
  full_name?: string
  channel?: Pick<Channel, 'id' | 'name' | 'type'> | null
  conversations?: Conversation[]
  conversations_count?: number
}

export interface CustomerStats {
  total: number
  active: number
  gender_distribution: { gender: number; count: number }[]
  top_cities: { city: string; count: number }[]
  total_orders: number
  total_revenue: number
}

// ── CRM Orders ──

export interface CrmOrder {
  id: number
  tenant_id: number
  channel_id: number
  customer_id: number | null
  platform_order_id: string
  platform_customer_id: string | null
  order_no: string | null
  order_code: string | null
  status: number
  status_label: string | null
  subtotal: string
  discount_amount: string
  shipping_cost: string
  gift_card_amount: string
  points_discount: string
  tax_amount: string
  total_amount: string
  paid_amount: string
  currency: string
  customer_name: string | null
  customer_email: string | null
  cargo_company: string | null
  cargo_tracking_no: string | null
  cargo_tracking_url: string | null
  payment_type: number | null
  payment_status: number | null
  order_source: string | null
  billing_address: Record<string, string | null> | null
  shipping_address: Record<string, string | null> | null
  ip_address: string | null
  items_count: number
  order_date: string | null
  status_updated_at: string | null
  platform_updated_at: string | null
  payments: OrderPayment[] | null
  metadata: Record<string, unknown> | null
  admin_url?: string | null
  channel?: Pick<Channel, 'id' | 'name' | 'type'> | null
  customer?: CrmCustomer | null
  items?: OrderItem[]
  created_at: string
  updated_at: string
}

export interface OrderPayment {
  id: number
  type: number
  amount: number
  currency: string
  date: string | null
  approved: boolean
  installments: number
  pos_reference: string | null
  order_reference: string | null
  note: string | null
  bank_commission: number
  cod_amount: number
}

export interface OrderItem {
  id: number
  order_id: number
  platform_product_id: string | null
  platform_variant_id: string | null
  product_name: string | null
  image_url: string | null
  variant_info: string | null
  sku: string | null
  barcode: string | null
  quantity: number
  unit_price: string
  total_price: string
  discount_amount: string
  tax_rate: string
  tax_amount: string
  status: number
  status_label: string | null
  product_id: number | null
  product_variant_id: number | null
}

export interface OrderStats {
  total: number
  total_revenue: number
  paid_count: number
  unpaid_count: number
  last_30_days: number
  last_30_revenue: number
  avg_basket: number
}

export interface OrderSource {
  order_source: string
  order_count: number
}

export interface OrderStatusCount {
  status: number
  status_label: string | null
  count: number
  revenue: number
}

// ── CRM Favorites ──

export interface ProductFavorite {
  id: number
  platform_favorite_id: number
  platform_customer_id: number
  platform_product_id: number
  product_name: string
  product_sku: string | null
  product_image: string | null
  product_url: string | null
  product_price: number
  stock_quantity: number
  variant_count: number
  currency: string
  favorited_at: string
  customer_id: number | null
  product_id: number | null
  customer?: CrmCustomer
  product?: { id: number; stock_quantity: number } | null
}

export interface ProductFavoriteGrouped {
  platform_product_id: number
  product_name: string
  product_sku: string | null
  product_image: string | null
  product_url: string | null
  stock_quantity: number
  product_stock: number | null
  favorite_count: number
  last_favorited_at: string
  product_id: number | null
}

export interface FavoriteStats {
  total_favorites: number
  unique_products: number
  unique_customers: number
  today: number
  last_7_days: number
  last_30_days: number
  in_stock_pct: number
  out_of_stock_count: number
}

export interface FavoriteChartPoint {
  date: string
  total: number
  unique_products: number
  unique_customers: number
}

// ── GA4 Analytics ──

export interface GA4SiteOverview {
  sessions: number
  users: number
  new_users: number
  page_views: number
  purchases: number
  revenue: number
  engagement_rate: number
  avg_session_duration: number
  conversion_rate: number
}

export interface GA4SiteTrendPoint {
  date: string
  sessions: number
  users: number
  page_views: number
  purchases: number
  revenue: number
}

export interface GA4ProductRow {
  item_name: string
  item_id: string
  views: number
  list_views: number
  list_clicks: number
  ctr: number
  add_to_carts: number
  atc_rate: number
  purchases: number
  units_sold: number
  conversion_rate: number
  cart_to_purchase: number
  revenue: number
  avg_price: number
  revenue_per_view: number
}

export interface GA4SummaryStats {
  total_views: number
  total_add_to_carts: number
  total_purchases: number
  total_revenue: number
  conversion_rate: number
  cart_abandonment_rate: number
}

export interface GA4ChartPoint {
  date: string
  views: number
  add_to_carts: number
  purchases: number
  revenue: number
}

// ── GA4 Traffic ──

export interface GA4TrafficRow {
  source_medium: string
  sessions: number
  users: number
  new_users: number
  purchases: number
  revenue: number
  engagement_rate: number
  conversion_rate: number
}

export interface GA4NewVsReturning {
  type: 'new' | 'returning'
  sessions: number
  users: number
  purchases: number
  revenue: number
}

// ── GA4 Funnel ──

export interface GA4FunnelData {
  sessions: number
  views: number
  add_to_carts: number
  checkouts: number
  purchases: number
  revenue: number
}

// ── GA4 Device & City ──

export interface GA4DeviceRow {
  device: string
  sessions: number
  users: number
  purchases: number
  revenue: number
  engagement_rate: number
  conversion_rate: number
}

export interface GA4CityRow {
  city: string
  sessions: number
  users: number
  purchases: number
  revenue: number
}

// ── Product Feed ──

export interface ProductFeedSettings {
  feed_enabled: boolean
  default_gender: string
  default_age_group: string
  default_google_category: string
  feed_title: string
  exclude_out_of_stock: boolean
  storefront_base_url: string
}

export interface ProductFeedStats {
  total_products: number
  active_products: number
  excluded_products: number
  ai_optimized: number
  not_optimized: number
  variants_count: number
  last_generated_at: string | null
}

export interface ProductFeedOverride {
  id: number
  product_id: number
  ai_title: string | null
  ai_description: string | null
  google_category: string | null
  gender: string | null
  age_group: string | null
  material: string | null
  custom_label_0: string | null
  custom_label_1: string | null
  is_excluded: boolean
  ai_optimized_at: string | null
  product: Pick<Product, 'id' | 'name' | 'sku' | 'brand' | 'images' | 'price'>
}

// ── Feed Management ──

export type FeedMappingSourceType = 'product' | 'variant' | 'ai_content' | 'external' | 'static' | 'combined'
export type FeedTransformationType = 'find_replace' | 'prefix' | 'suffix' | 'uppercase' | 'lowercase' | 'titlecase' | 'truncate' | 'default_value' | 'number_format' | 'regex_replace' | 'stock_to_availability' | 'map_value'

export interface FeedProfile {
  id: number
  tenant_id: number
  name: string
  slug: string
  feed_token: string
  template: string
  output_format: 'rss' | 'atom'
  xml_root_tag: string
  xml_item_tag: string
  xml_namespace: string | null
  xml_namespace_prefix: string | null
  variant_mode: 'variant' | 'product'
  currency: string
  storefront_base_url: string | null
  exclude_out_of_stock: boolean
  exclude_inactive: boolean
  is_active: boolean
  settings: Record<string, unknown> | null
  last_generated_at: string | null
  product_count: number
  mappings_count?: number
  feed_url?: string
  mappings?: FeedMapping[]
  created_at: string
  updated_at: string
}

export interface FeedSource {
  id: number
  tenant_id: number
  name: string
  type: 'xml_url' | 'xml_file' | 'csv_url' | 'csv_file'
  url: string | null
  file_path: string | null
  join_field_internal: 'sku' | 'barcode' | 'platform_product_id'
  join_field_external: string
  item_xpath: string
  parsed_fields: FeedSourceField[] | null
  last_fetched_at: string | null
  last_fetch_status: string | null
  last_fetch_error: string | null
  fetch_interval_hours: number
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface FeedSourceField {
  name: string
  xpath: string
  sample_values: string[]
}

export interface FeedMapping {
  id: number
  feed_profile_id: number
  xml_tag: string
  source_type: FeedMappingSourceType
  source_field: string | null
  source_id: number | null
  static_value: string | null
  combine_pattern: string | null
  is_required: boolean
  include_prefix: boolean
  sort_order: number
  is_active: boolean
  transformations: FeedTransformation[]
}

export interface FeedTransformation {
  id: number
  feed_mapping_id: number
  type: FeedTransformationType
  config: Record<string, unknown>
  sort_order: number
  is_active: boolean
}

export interface FeedAiContent {
  id: number
  product_id: number
  feed_profile_id: number | null
  title: string | null
  description: string | null
  extra_fields: Record<string, string> | null
  prompt_used: string | null
  model_used: string | null
  is_excluded: boolean
  generated_at: string | null
  product: Pick<Product, 'id' | 'name' | 'sku' | 'brand' | 'images' | 'price'>
}

export interface AvailableField {
  field: string
  label: string
}

export interface AvailableFields {
  product: AvailableField[]
  variant: AvailableField[]
  ai_content: AvailableField[]
}

export interface FeedTemplate {
  key: string
  name: string
  description: string
  output_format: string
  mapping_count: number
}

export interface FeedPreviewField {
  xml_tag: string
  raw_value: string | null
  transformed_value: string | null
}

export interface FeedPreviewItem {
  product_id: number
  product_name: string
  fields: FeedPreviewField[]
}

// ── Reports ──

export interface MessageReportStats {
  total_customer_messages: number
  period_customer_messages: number
  satisfaction_rate: number
  complaint_rate: number
  avg_daily: number
}

export interface MessageCategory {
  key: string
  label: string
  color: string
  count: number
  percentage: number
}

export interface MessageCategoriesResponse {
  categories: MessageCategory[]
  total: number
}

export interface MessageChannelBreakdown {
  channel_id: number
  channel_name: string
  channel_type: string
  message_count: number
  percentage: number
}

export interface MessageTrendPoint {
  date: string
  count: number
}

export interface MessageReportItem {
  id: number
  content: string
  created_at: string
  conversation_id: number
  conversation: {
    id: number
    channel: { name: string; type: string } | null
  } | null
  customer_name: string | null
}

export interface ReturnReportStats {
  total_orders: number
  total_returns: number
  return_rate_pct: number
  revenue_lost: number
  returned_items: number
  pending_items: number
  cancelled_items: number
}

export interface ReturnTopProduct {
  platform_product_id: string
  product_name: string
  image_url: string | null
  product_id: number | null
  total_item_count: number
  return_item_count: number
  return_revenue: number
  return_rate_pct: number
}

export interface ReturnTrendPoint {
  month: string
  total_returns: number
  revenue_lost: number
}

export interface ReturnBySource {
  order_source: string
  total_count: number
  return_count: number
  return_rate_pct: number
  return_revenue: number
}

// ── Pagination ──

export interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}
