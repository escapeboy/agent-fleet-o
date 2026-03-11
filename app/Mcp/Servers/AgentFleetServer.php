<?php

namespace App\Mcp\Servers;

use App\Mcp\Concerns\BootstrapsMcpAuth;
use App\Mcp\Tools\Admin\AdminBillingApplyCreditTool;
use App\Mcp\Tools\Admin\AdminBillingRefundTool;
use App\Mcp\Tools\Admin\AdminSecurityOverviewTool;
use App\Mcp\Tools\Admin\AdminTeamBillingDetailTool;
use App\Mcp\Tools\Admin\AdminTeamSuspendTool;
use App\Mcp\Tools\Admin\AdminUserRevokeSessionsTool;
use App\Mcp\Tools\Admin\AdminUserSendPasswordResetTool;
use App\Mcp\Tools\Chatbot\ChatbotAnalyticsSummaryTool;
use App\Mcp\Tools\Chatbot\ChatbotCreateTool;
use App\Mcp\Tools\Chatbot\ChatbotGetTool;
use App\Mcp\Tools\Chatbot\ChatbotListTool;
use App\Mcp\Tools\Chatbot\ChatbotSessionListTool;
use App\Mcp\Tools\Chatbot\ChatbotToggleStatusTool;
use App\Mcp\Tools\Chatbot\ChatbotUpdateTool;