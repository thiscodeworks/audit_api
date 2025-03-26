<?php

class GoogleChatService {
    private $webhookUrl;

    public function __construct() {
        $this->webhookUrl = 'https://chat.googleapis.com/v1/spaces/AAAAR_QIlNY/messages?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI&token=SKn431Gwkglvk4zWotnS0UFNJMgGom9NYuj5NyaVJHs';
    }

    public function sendAuditStartNotification($auditName, $organizationName, $userName, $userEmail) {
        $message = [
            'text' => "🎯 *New Audit Started*\n\n" .
                     "📊 Audit: $auditName\n" .
                     "🏢 Organization: $organizationName\n" .
                     "👤 User: $userName\n" .
                     "📧 Email: $userEmail"
        ];

        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'response' => $response,
            'httpCode' => $httpCode
        ];
    }
    
    public function sendCustomRequestNotification($title, $organizationName, $creatorName, $requestText, $uuid) {
        // Truncate request text if it's too long
        $maxLength = 300;
        $truncatedRequest = strlen($requestText) > $maxLength 
            ? substr($requestText, 0, $maxLength) . '...' 
            : $requestText;
            
        $appUrl = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
        $auditUrl = "{$appUrl}/audit/{$uuid}";
        
        $message = [
            'text' => "⚠️ *New Custom Request Needs Review*\n\n" .
                     "📝 Request: $title\n" .
                     "🏢 Organization: $organizationName\n" .
                     "👤 Created by: $creatorName\n" .
                     "🔍 Status: REQUESTED (requires configuration)\n\n" .
                     "📋 *Request Details:*\n$truncatedRequest\n\n" .
                     "🔗 *Review Request:* $auditUrl"
        ];

        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'response' => $response,
            'httpCode' => $httpCode
        ];
    }
} 