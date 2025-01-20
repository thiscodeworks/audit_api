<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../utils/Env.php';

use Postmark\PostmarkClient;

class PostmarkService {
    private $client;
    private $fromEmail;
    
    public function __construct() {
        $apiToken = getenv('POSTMARK_API_TOKEN');
        if (!$apiToken) {
            throw new Exception('POSTMARK_API_TOKEN not found in environment variables');
        }
        
        $this->fromEmail = getenv('POSTMARK_FROM_EMAIL');
        if (!$this->fromEmail) {
            throw new Exception('POSTMARK_FROM_EMAIL not found in environment variables');
        }
        
        $this->client = new PostmarkClient($apiToken);
    }
    
    public function sendEmail($to, $subject, $htmlBody, $textBody = null) {
        try {
            if (!$textBody) {
                $textBody = strip_tags($htmlBody);
            }
            
            $response = $this->client->sendEmail(
                $this->fromEmail,
                $to,
                $subject,
                $htmlBody,
                $textBody
            );
            
            return [
                'success' => true,
                'message_id' => $response->MessageID
            ];
        } catch (Exception $e) {
            error_log("Error sending email via Postmark: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function sendTemplate($to, $templateAlias, $templateModel, $subject = null) {
        try {
            $response = $this->client->sendEmailWithTemplate(
                $this->fromEmail,
                $to,
                $templateAlias,
                $templateModel,
                true, // inlineCss
                null, // tag
                null, // trackOpens
                $subject
            );
            
            return [
                'success' => true,
                'message_id' => $response->MessageID
            ];
        } catch (Exception $e) {
            error_log("Error sending template email via Postmark: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function sendBatch($emails) {
        try {
            $batch = array_map(function($email) {
                return [
                    'From' => $this->fromEmail,
                    'To' => $email['to'],
                    'Subject' => $email['subject'],
                    'HtmlBody' => $email['htmlBody'],
                    'TextBody' => isset($email['textBody']) ? $email['textBody'] : strip_tags($email['htmlBody']),
                    'MessageStream' => 'outbound'
                ];
            }, $emails);
            
            $response = $this->client->sendEmailBatch($batch);
            
            return [
                'success' => true,
                'responses' => $response
            ];
        } catch (Exception $e) {
            error_log("Error sending batch emails via Postmark: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 