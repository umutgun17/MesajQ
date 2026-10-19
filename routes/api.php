<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\MetaOAuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CrmCustomerController;
use App\Http\Controllers\Api\CrmOrderController;
use App\Http\Controllers\Api\CrmProductController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailMessageController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NewConversationController;
use App\Http\Controllers\Api\ReportMessageController;
use App\Http\Controllers\Api\QuickReplyController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// ── Public ──
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// ── Meta Webhook (public, no auth) — throttle: yetkisiz kuyruk taşırma/DoS önlemi ──
Route::get('/webhook/meta', [WebhookController::class, 'verify'])->middleware('throttle:60,1');
Route::post('/webhook/meta', [WebhookController::class, 'handle'])->middleware('throttle:300,1');

// ── Meta OAuth (callback is public — uses encrypted state for auth) ──
Route::get('/meta/oauth/callback', [MetaOAuthController::class, 'callback'])->middleware('throttle:30,1');

// Widget routes are registered in bootstrap/app.php under /w/ prefix (outside api/ to avoid HandleCors)

// ── SaaS: kayıt, şifre, faturalama, kurulum ──
require __DIR__.'/saas.php';

// ── Authenticated ──
Route::middleware(['auth:sanctum', 'tenant', 'subscription', 'throttle:120,1', 'track-activity'])->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/auth/crm-sso', [AuthController::class, 'crmSso']);

    // Tenants (super admin only — controller-level isSuperAdmin check + route-level role:admin)
    Route::middleware('role:admin')->group(function () {
        Route::get('/tenants', [TenantController::class, 'index']);
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::patch('/tenants/{tenant}', [TenantController::class, 'update']);
        Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy']);
    });

    // Dashboard
    Route::middleware('permission:dashboard')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/penalties', [DashboardController::class, 'penalties']);
    });

    // Conversations (Messages)
    Route::middleware('permission:messages')->group(function () {
        Route::get('/conversations/counts', [ConversationController::class, 'counts']);
        Route::get('/conversations/agents', [ConversationController::class, 'agents']);

        // Yeni konuşma başlatma (WhatsApp şablon / e-posta / SMS)
        Route::get('/conversations/start/options', [NewConversationController::class, 'options']);
        Route::get('/conversations/start/customers', [NewConversationController::class, 'searchCustomers']);
        Route::post('/conversations/start', [NewConversationController::class, 'start']);

        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
        Route::post('/conversations/{conversation}/mark-read', [ConversationController::class, 'markAsRead']);
        Route::post('/conversations/{conversation}/assign', [ConversationController::class, 'assign']);

        // Messages
        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
        Route::post('/conversations/{conversation}/whatsapp-template', [MessageController::class, 'sendWhatsAppTemplate']);
        Route::post('/messages/{messageId}/penalty', [MessageController::class, 'togglePenalty']);
        Route::get('/messages/{message}/media', [MessageController::class, 'media'])->whereNumber('message');

        // Hazır cevaplar (her temsilci ekleyip düzenleyebilir)
        Route::get('/quick-replies', [QuickReplyController::class, 'index']);
        Route::post('/quick-replies', [QuickReplyController::class, 'store']);
        Route::patch('/quick-replies/{quickReply}', [QuickReplyController::class, 'update']);
        Route::delete('/quick-replies/{quickReply}', [QuickReplyController::class, 'destroy']);
        Route::post('/quick-replies/{quickReply}/used', [QuickReplyController::class, 'markUsed']);
    });

    // Email messages
    Route::middleware('permission:email_archive')->group(function () {
        Route::get('/email-messages', [EmailMessageController::class, 'index']);
        Route::get('/email-messages/counts', [EmailMessageController::class, 'counts']);
        Route::patch('/email-messages/{emailMessage}/reclassify', [EmailMessageController::class, 'reclassify']);
    });

    // Comments
    Route::middleware('permission:comments')->group(function () {
        Route::get('/conversations/{conversation}/comments', [CommentController::class, 'index']);
        Route::post('/comments/{comment}/reply', [CommentController::class, 'reply']);
        Route::patch('/comments/{comment}/status', [CommentController::class, 'updateStatus']);
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);
    });

    // Channels (read-only: tüm kullanıcılar erişebilir)
    Route::get('/channels', [ChannelController::class, 'index']);

    // Channels (yönetim: settings izni gerekli)
    Route::middleware('permission:settings')->group(function () {
        Route::get('/channels/platform/{platform}', [ChannelController::class, 'showPlatform']);
        Route::get('/channels/{channel}', [ChannelController::class, 'show']);
        Route::patch('/channels/platform/{platform}', [ChannelController::class, 'updatePlatformCredentials']);
        Route::patch('/channels/{channel}', [ChannelController::class, 'update']);

        // Meta OAuth — authenticated redirect (generates OAuth URL)
        Route::get('/meta/oauth/redirect', [MetaOAuthController::class, 'redirect']);
        // Çok-sayfa seçimi: bekleyen bağlantının sayfa listesi + seçileni bağla
        Route::get('/meta/oauth/pending/{key}', [MetaOAuthController::class, 'pendingPages']);
        Route::post('/meta/oauth/pending/{key}', [MetaOAuthController::class, 'selectPage']);
    });

    // Users
    Route::middleware('permission:settings')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        // Kullanıcı aktivitesi (giriş + sayfa izi) — yalnızca yönetici
        Route::middleware('role:admin')->group(function () {
            Route::get('/users/activity/summary', [\App\Http\Controllers\Api\UserActivityController::class, 'summary']);
            Route::get('/users/{user}/activity', [\App\Http\Controllers\Api\UserActivityController::class, 'show']);
        });
        Route::get('/users/{user}', [UserController::class, 'show']);

        Route::middleware('role:admin')->group(function () {
            Route::post('/users', [UserController::class, 'store']);
            Route::patch('/users/{user}', [UserController::class, 'update']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            // Kullanıcı gözünden gör (metod içinde süper admin şartı aranır)
            Route::post('/users/{user}/impersonate', [AuthController::class, 'impersonate']);
        });
    });

    // Knowledge Base
    Route::middleware('permission:settings')->group(function () {
        Route::get('/knowledge-base', [KnowledgeBaseController::class, 'index']);
        Route::get('/knowledge-base/categories', [KnowledgeBaseController::class, 'categories']);
        Route::get('/knowledge-base/{knowledgeBase}', [KnowledgeBaseController::class, 'show']);
        Route::post('/knowledge-base', [KnowledgeBaseController::class, 'store']);
        Route::patch('/knowledge-base/{knowledgeBase}', [KnowledgeBaseController::class, 'update']);
        Route::delete('/knowledge-base/{knowledgeBase}', [KnowledgeBaseController::class, 'destroy']);
    });

    // AI Management
    Route::middleware('permission:settings')->group(function () {
        Route::get('/ai/training-data', [AiController::class, 'trainingData']);
        Route::get('/ai/training-data/counts', [AiController::class, 'trainingDataCounts']);
        Route::get('/ai/response-logs', [AiController::class, 'responseLogs']);
        Route::get('/ai/edit-history', [AiController::class, 'editHistory']);
        Route::get('/ai/edit-history/stats', [AiController::class, 'editHistoryStats']);
        Route::post('/ai/training-data', [AiController::class, 'storeTrainingData']);
        Route::patch('/ai/training-data/{trainingData}', [AiController::class, 'updateTrainingData']);
        Route::delete('/ai/training-data/{trainingData}', [AiController::class, 'destroyTrainingData']);
    });

    // AI Response generation + logging (mesaj izni olan herkes)
    Route::middleware('permission:messages')->group(function () {
        Route::post('/ai/generate', [AiController::class, 'generate'])->middleware('throttle:ai-generate');
        Route::post('/ai/log-response', [AiController::class, 'logResponse']);
    });

    // Settings
    Route::middleware('permission:settings')->group(function () {
        Route::get('/settings', [SettingsController::class, 'show']);
        Route::get('/settings/ga4/status', [SettingsController::class, 'ga4Status']);
        Route::put('/settings', [SettingsController::class, 'update']);
        Route::post('/settings/ga4', [SettingsController::class, 'saveGa4']);

    });

    Route::middleware('permission:crm')->group(function () {
        Route::get('/crm/products/stats', [CrmProductController::class, 'stats']);
        Route::get('/crm/products/brands', [CrmProductController::class, 'brands']);
        Route::get('/crm/products/categories', [CrmProductController::class, 'categories']);
        Route::get('/crm/products', [CrmProductController::class, 'index']);
        Route::get('/crm/products/{id}', [CrmProductController::class, 'show']);

        // CRM Orders
        Route::get('/crm/orders/stats', [CrmOrderController::class, 'stats']);
        Route::get('/crm/orders/tab-counts', [CrmOrderController::class, 'tabCounts']);
        Route::get('/crm/orders/cargo-companies', [CrmOrderController::class, 'cargoCompanies']);
        Route::get('/crm/orders/statuses', [CrmOrderController::class, 'statuses']);
        Route::get('/crm/orders/sources', [CrmOrderController::class, 'sources']);
        Route::get('/crm/orders', [CrmOrderController::class, 'index']);
        Route::get('/crm/orders/{id}', [CrmOrderController::class, 'show']);

        // CRM Customers
        Route::get('/crm/customers/stats', [CrmCustomerController::class, 'stats']);
        Route::get('/crm/customers/cities', [CrmCustomerController::class, 'cities']);
        Route::get('/crm/customers/member-types', [CrmCustomerController::class, 'memberTypes']);
        Route::get('/crm/customers', [CrmCustomerController::class, 'index']);
        Route::get('/crm/customers/{id}', [CrmCustomerController::class, 'show']);

    });

    // Reports
    Route::middleware('permission:reports')->group(function () {
        Route::get('/reports/messages/stats', [ReportMessageController::class, 'stats']);
        Route::get('/reports/messages/categories', [ReportMessageController::class, 'categories']);
        Route::get('/reports/messages/channels', [ReportMessageController::class, 'channels']);
        Route::get('/reports/messages/trend', [ReportMessageController::class, 'trend']);
        Route::get('/reports/messages/by-category', [ReportMessageController::class, 'byCategory']);
    });

    Route::middleware('permission:chatbot')->group(function () {

        // ── AI Chatbot paneli ──
        Route::get('/chat/analytics', [\App\Http\Controllers\Api\ChatbotAdminController::class, 'analytics']);
        Route::get('/chat/sessions/{id}/messages', [\App\Http\Controllers\Api\ChatbotAdminController::class, 'sessionMessages'])->whereNumber('id');
        // Ayrı "Chatbot Konuşmaları" sayfası + kademeli açılım kontrolü (4 Eyl)
        Route::get('/chat/conversations', [\App\Http\Controllers\Api\ChatbotAdminController::class, 'conversations']);
        Route::get('/chat/product-analytics', [\App\Http\Controllers\Api\ChatbotAdminController::class, 'productAnalytics']);
        Route::get('/chat/rollout', [\App\Http\Controllers\Api\ChatbotAdminController::class, 'rollout']);
        Route::post('/chat/rollout/reset', [\App\Http\Controllers\Api\ChatbotAdminController::class, 'resetRollout']);

        // ── Akıllı Arama (Smart Search) paneli ──
        // Ürün Öneri paneli 27 Eyl'de CRO ürününe taşındı (permission:cro grubu)

        // Akıllı Arama paneli 10 Eyl'de CRO ürününe taşındı (permission:cro grubu)

    });
});
