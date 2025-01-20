<?php
require_once __DIR__ . '/../models/Analysis.php';
require_once __DIR__ . '/../services/AnthropicService.php';
require_once __DIR__ . '/../utils/Env.php';

class AnalysisService {
    private $analysis;
    private $anthropic;

    public function __construct() {
        $this->analysis = new Analysis();
        $apiKey = Env::get('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            throw new Exception('ANTHROPIC_API_KEY not found in environment variables');
        }
        $this->anthropic = new AnthropicService($apiKey);
    }

    public function analyzeSingleChat($chatUuid) {
        try {
            // Get chat messages
            $messages = $this->analysis->getChatMessages($chatUuid);
            
            if (empty($messages)) {
                return [
                    'success' => false,
                    'error' => 'No messages found for this chat'
                ];
            }

            // Check if there's at least one user message
            $hasUserMessage = false;
            foreach ($messages as $msg) {
                if ($msg['role'] === 'user') {
                    $hasUserMessage = true;
                    break;
                }
            }

            if (!$hasUserMessage) {
                return [
                    'success' => false,
                    'error' => 'Chat must have at least one user message to be analyzed'
                ];
            }
            
            // Prepare messages for analysis
            $conversation = "";
            foreach ($messages as $msg) {
                $role = $msg['role'] === 'user' ? 'Customer' : 'Assistant';
                $conversation .= "{$role}: {$msg['content']}\n\n";
            }

            // Create analysis prompt
            $prompt = "Analyze the following customer service conversation and provide your analysis in JSON format with the following structure:
{
    \"sentiment\": [number between 0-100],
    \"summary\": [brief summary in Czech language],
    \"findings\": [key findings and insights as a single Czech sentence]
}

The sentiment score should be 0-100 where:
- 0-30: Velmi negativní
- 31-45: Negativní
- 46-55: Neutrální
- 56-75: Pozitivní
- 76-100: Velmi pozitivní

Provide the summary and findings in Czech language. The findings should be a single coherent sentence that captures all important insights.

Conversation:
" . $conversation . "

Respond ONLY with the JSON object, no additional text.";

            // Get analysis from Anthropic
            $response = $this->anthropic->analyze($prompt);
            
            // Parse JSON response
            $analysis = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Error parsing Anthropic response: " . json_last_error_msg());
                error_log("Raw response: " . $response);
                return [
                    'success' => false,
                    'error' => 'Failed to parse analysis response'
                ];
            }

            // Get chat ID from UUID
            $stmt = Database::getInstance()->prepare("SELECT id FROM chats WHERE uuid = ?");
            $stmt->execute([$chatUuid]);
            $chat = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$chat) {
                return [
                    'success' => false,
                    'error' => 'Chat not found'
                ];
            }

            // Save analysis
            $this->analysis->create(
                $chat['id'],
                $analysis['sentiment'],
                $analysis['summary'],
                $analysis['findings']
            );

            return [
                'success' => true,
                'data' => [
                    'sentiment' => $analysis['sentiment'],
                    'summary' => $analysis['summary'],
                    'findings' => $analysis['findings']
                ]
            ];
        } catch (Exception $e) {
            error_log("Error analyzing chat {$chatUuid}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function analyzePendingChats() {
        try {
            $chats = $this->analysis->getChatsNeedingAnalysis();
            
            if (empty($chats)) {
                return [
                    'success' => true,
                    'message' => 'No chats need analysis at this time'
                ];
            }

            $chat = $chats[0]; // Get the first (and only) chat
            
            try {
                // Get chat messages
                $messages = $this->analysis->getChatMessages($chat['chat_uuid']);
                
                // Prepare messages for analysis
                $conversation = "";
                foreach ($messages as $msg) {
                    $role = $msg['role'] === 'user' ? 'Customer' : 'Assistant';
                    $conversation .= "{$role}: {$msg['content']}\n\n";
                }

                // Create analysis prompt
                $prompt = "Analyze the following customer service conversation and provide your analysis in JSON format with the following structure:
{
    \"sentiment\": [number between 0-100],
    \"summary\": [brief summary in Czech language],
    \"findings\": [key findings and insights as a single Czech sentence]
}

The sentiment score should be 0-100 where:
- 0-30: Velmi negativní
- 31-45: Negativní
- 46-55: Neutrální
- 56-75: Pozitivní
- 76-100: Velmi pozitivní

Provide the summary and findings in Czech language. The findings should be a single coherent sentence that captures all important insights.

Conversation:
" . $conversation . "

Respond ONLY with the JSON object, no additional text.";

                // Get analysis from Anthropic
                $response = $this->anthropic->analyze($prompt);
                
                // Parse JSON response
                $analysis = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("Error parsing Anthropic response: " . json_last_error_msg());
                    error_log("Raw response: " . $response);
                    return [
                        'success' => false,
                        'error' => 'Failed to parse analysis response'
                    ];
                }

                // Save analysis
                $this->analysis->create(
                    $chat['chat_id'],
                    $analysis['sentiment'],
                    $analysis['summary'],
                    $analysis['findings']
                );

                return [
                    'success' => true,
                    'data' => [
                        'chat_uuid' => $chat['chat_uuid'],
                        'sentiment' => $analysis['sentiment'],
                        'summary' => $analysis['summary'],
                        'findings' => $analysis['findings']
                    ]
                ];
            } catch (Exception $e) {
                error_log("Error analyzing chat {$chat['chat_uuid']}: " . $e->getMessage());
                return [
                    'success' => false,
                    'error' => "Error analyzing chat {$chat['chat_uuid']}: " . $e->getMessage()
                ];
            }
        } catch (Exception $e) {
            error_log("Error in analysis service: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getChatDetail($chatUuid) {
        try {
            return $this->analysis->getChatDetail($chatUuid);
        } catch (Exception $e) {
            error_log("Error getting chat detail: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function findAuditByCode($code) {
        try {
            return $this->analysis->findAuditByCode($code);
        } catch (Exception $e) {
            error_log("Error finding audit by code: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 