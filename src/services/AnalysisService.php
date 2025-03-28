<?php
require_once __DIR__ . '/../models/Analysis.php';
require_once __DIR__ . '/../services/AnthropicService.php';
require_once __DIR__ . '/../utils/Env.php';
require_once __DIR__ . '/../services/PusherService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class AnalysisService {
    private $analysis;
    private $anthropic;

    public function __construct() {
        $this->analysis = new Analysis();
        $this->anthropic = new AnthropicService();
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
    \"summary\": [comprehensive overall assessment in Czech language, 2-3 paragraphs],
    \"keyfindings\": [array of 3-5 key findings as bullet points in Czech language],
    \"tags\": [array of 3-7 single-word or short phrase tags that describe the conversation topics, issues, or themes, in Czech],
    \"topics\": [array of main conversation topics discussed, in Czech],
    \"customer_satisfaction\": [number between 0-100 estimating customer satisfaction level],
    \"agent_effectiveness\": [number between 0-100 rating how effective the agent was],
    \"improvement_suggestions\": [array of 1-3 specific improvement suggestions for the agent or service, in Czech],
    \"conversation_quality\": {
        \"clarity\": [number between 0-100 rating communication clarity],
        \"speed\": [number between 0-100 rating response speed/efficiency],
        \"solution\": [number between 0-100 rating effectiveness of solution provided]
    }
}

IMPORTANT INSTRUCTIONS:
- Do not ask any questions or provide explanations in your response.
- Do not ask for clarification on how to proceed. 
- Always generate the complete JSON response directly.
- Generate the full response regardless of the conversation length.
- Do not share your reasoning or thought process - ONLY output the final JSON.
- NEVER mention the amount of data, messages, or conversation length.
- Do not include any text outside the JSON structure.
q
The sentiment score should be 0-100 where:
- 0-30: Velmi negativní
- 31-45: Negativní
- 46-55: Neutrální
- 56-75: Pozitivní
- 76-100: Velmi pozitivní

For the summary, provide a comprehensive overall assessment of the conversation in 2-3 paragraphs in Czech language.

For the keyfindings, provide 3-5 specific, actionable insights as bullet points in Czech. Each finding should be a complete sentence that captures an important aspect of the conversation.

For the tags, provide 3-7 relevant tags as single words or short phrases that describe the main topics, issues, or themes in the conversation. Tags should be in Czech.

For topics, identify the main conversation topics or subjects discussed.

Customer satisfaction should estimate how satisfied the customer appears to be with the interaction.

Agent effectiveness should rate how well the agent handled the conversation.

Improvement suggestions should be specific, actionable recommendations to improve service.

Conversation quality metrics should rate different aspects of the conversation quality.

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
                
                // Get chat ID from UUID even if parsing failed
                $stmt = Database::getInstance()->prepare("SELECT id FROM chats WHERE uuid = ?");
                $stmt->execute([$chatUuid]);
                $chat = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$chat) {
                    return [
                        'success' => false,
                        'error' => 'Chat not found'
                    ];
                }
                
                // Create a placeholder analysis record with null values
                $this->analysis->create(
                    $chat['id'],
                    null,  // sentiment
                    null,  // summary
                    null,  // keyfindings
                    null,  // tags
                    null,  // topics
                    null,  // customer_satisfaction
                    null,  // agent_effectiveness
                    null,  // improvements
                    null   // conversation_quality
                );
                
                return [
                    'success' => false,
                    'error' => 'Failed to parse analysis response'.$response,
                    'message' => 'Analysis record created with null values'
                ];
            }

            // Convert JSON fields to strings if needed and extract new metrics
            $keyfindings = $analysis['keyfindings'];
            if (is_array($keyfindings)) {
                $keyfindings = implode("\n• ", $keyfindings);
                // Add a bullet to the first item
                $keyfindings = "• " . $keyfindings;
            }

            // Convert tags array to comma-separated string if needed
            $tags = $analysis['tags'];
            if (is_array($tags)) {
                $tags = implode(", ", $tags);
            }

            // Convert topics array to string if needed
            $topics = isset($analysis['topics']) ? $analysis['topics'] : [];
            if (is_array($topics)) {
                $topics = implode(", ", $topics);
            }

            // Convert improvement suggestions to string if needed
            $improvements = isset($analysis['improvement_suggestions']) ? $analysis['improvement_suggestions'] : [];
            if (is_array($improvements)) {
                $improvements = implode("\n• ", $improvements);
                // Add a bullet to the first item if not empty
                if (!empty($improvements)) {
                    $improvements = "• " . $improvements;
                }
            }

            // Extract other metrics
            $customerSatisfaction = isset($analysis['customer_satisfaction']) ? $analysis['customer_satisfaction'] : null;
            $agentEffectiveness = isset($analysis['agent_effectiveness']) ? $analysis['agent_effectiveness'] : null;
            $conversationQuality = isset($analysis['conversation_quality']) ? json_encode($analysis['conversation_quality']) : null;

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

            // Save analysis - note: we're not changing the database structure here,
            // just storing the additional data in the JSON response
            $this->analysis->create(
                $chat['id'],
                $analysis['sentiment'],
                $analysis['summary'],
                $keyfindings,
                $tags,
                $topics,
                $customerSatisfaction,
                $agentEffectiveness,
                $improvements,
                isset($analysis['conversation_quality']) ? json_encode($analysis['conversation_quality']) : null
            );

            // Create a comprehensive response with all metrics
            return [
                'success' => true,
                'data' => [
                    'sentiment' => $analysis['sentiment'],
                    'summary' => $analysis['summary'],
                    'keyfindings' => $keyfindings,
                    'tags' => $tags,
                    'topics' => $topics,
                    'customer_satisfaction' => $customerSatisfaction,
                    'agent_effectiveness' => $agentEffectiveness,
                    'improvement_suggestions' => $improvements,
                    'conversation_quality' => isset($analysis['conversation_quality']) ? $analysis['conversation_quality'] : null
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
    \"summary\": [comprehensive overall assessment in Czech language, 2-3 paragraphs],
    \"keyfindings\": [array of 3-5 key findings as bullet points in Czech language],
    \"tags\": [array of 3-7 single-word or short phrase tags that describe the conversation topics, issues, or themes, in Czech],
    \"topics\": [array of main conversation topics discussed, in Czech],
    \"customer_satisfaction\": [number between 0-100 estimating customer satisfaction level],
    \"agent_effectiveness\": [number between 0-100 rating how effective the agent was],
    \"improvement_suggestions\": [array of 1-3 specific improvement suggestions for the agent or service, in Czech],
    \"conversation_quality\": {
        \"clarity\": [number between 0-100 rating communication clarity],
        \"speed\": [number between 0-100 rating response speed/efficiency],
        \"solution\": [number between 0-100 rating effectiveness of solution provided]
    }
}

IMPORTANT INSTRUCTIONS:
- Do not ask any questions or provide explanations in your response.
- Do not ask for clarification on how to proceed. 
- Always generate the complete JSON response directly.
- Generate the full response regardless of the conversation length.
- Do not share your reasoning or thought process - ONLY output the final JSON.
- NEVER mention the amount of data, messages, or conversation length.
- Do not include any text outside the JSON structure.

The sentiment score should be 0-100 where:
- 0-30: Velmi negativní
- 31-45: Negativní
- 46-55: Neutrální
- 56-75: Pozitivní
- 76-100: Velmi pozitivní

For the summary, provide a comprehensive overall assessment of the conversation in 2-3 paragraphs in Czech language.

For the keyfindings, provide 3-5 specific, actionable insights as bullet points in Czech. Each finding should be a complete sentence that captures an important aspect of the conversation.

For the tags, provide 3-7 relevant tags as single words or short phrases that describe the main topics, issues, or themes in the conversation. Tags should be in Czech.

For topics, identify the main conversation topics or subjects discussed.

Customer satisfaction should estimate how satisfied the customer appears to be with the interaction.

Agent effectiveness should rate how well the agent handled the conversation.

Improvement suggestions should be specific, actionable recommendations to improve service.

Conversation quality metrics should rate different aspects of the conversation quality.

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
                    
                    // Create a placeholder analysis record with null values
                    $this->analysis->create(
                        $chat['chat_id'],
                        null,  // sentiment
                        null,  // summary
                        null,  // keyfindings
                        null,  // tags
                        null,  // topics
                        null,  // customer_satisfaction
                        null,  // agent_effectiveness
                        null,  // improvements
                        null   // conversation_quality
                    );
                    
                    return [
                        'success' => false,
                        'error' => 'Failed to parse analysis response: ' . json_last_error_msg() . '. Raw response: ' . substr($response, 0, 1000),
                        'message' => 'Analysis record created with null values'
                    ];
                }

                // Convert JSON fields to strings if needed and extract new metrics
                $keyfindings = $analysis['keyfindings'];
                if (is_array($keyfindings)) {
                    $keyfindings = implode("\n• ", $keyfindings);
                    // Add a bullet to the first item
                    $keyfindings = "• " . $keyfindings;
                }

                // Convert tags array to comma-separated string if needed
                $tags = $analysis['tags'];
                if (is_array($tags)) {
                    $tags = implode(", ", $tags);
                }

                // Convert topics array to string if needed
                $topics = isset($analysis['topics']) ? $analysis['topics'] : [];
                if (is_array($topics)) {
                    $topics = implode(", ", $topics);
                }

                // Convert improvement suggestions to string if needed
                $improvements = isset($analysis['improvement_suggestions']) ? $analysis['improvement_suggestions'] : [];
                if (is_array($improvements)) {
                    $improvements = implode("\n• ", $improvements);
                    // Add a bullet to the first item if not empty
                    if (!empty($improvements)) {
                        $improvements = "• " . $improvements;
                    }
                }

                // Extract other metrics
                $customerSatisfaction = isset($analysis['customer_satisfaction']) ? $analysis['customer_satisfaction'] : null;
                $agentEffectiveness = isset($analysis['agent_effectiveness']) ? $analysis['agent_effectiveness'] : null;
                $conversationQuality = isset($analysis['conversation_quality']) ? json_encode($analysis['conversation_quality']) : null;

                // Save analysis
                $this->analysis->create(
                    $chat['chat_id'],
                    $analysis['sentiment'],
                    $analysis['summary'],
                    $keyfindings,
                    $tags,
                    $topics,
                    $customerSatisfaction,
                    $agentEffectiveness,
                    $improvements,
                    isset($analysis['conversation_quality']) ? json_encode($analysis['conversation_quality']) : null
                );

                return [
                    'success' => true,
                    'data' => [
                        'chat_uuid' => $chat['chat_uuid'],
                        'sentiment' => $analysis['sentiment'],
                        'summary' => $analysis['summary'],
                        'keyfindings' => $keyfindings,
                        'tags' => $tags,
                        'topics' => $topics,
                        'customer_satisfaction' => $customerSatisfaction,
                        'agent_effectiveness' => $agentEffectiveness,
                        'improvement_suggestions' => $improvements,
                        'conversation_quality' => isset($analysis['conversation_quality']) ? $analysis['conversation_quality'] : null
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

    public function getDashboardStats() {
        try {
            $db = Database::getInstance();
            
            // Get current user's ID from AuthMiddleware
            $userData = AuthMiddleware::getAuthenticatedUser();
            $userId = $userData->id;
            
            // Verify user exists
            $userQuery = "SELECT id FROM users WHERE id = ?";
            $userStmt = $db->prepare($userQuery);
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }

            // Get user's organizations
            $orgsQuery = "SELECT organization FROM users_organization WHERE user = ?";
            $orgsStmt = $db->prepare($orgsQuery);
            $orgsStmt->execute([$userId]);
            $organizations = $orgsStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($organizations)) {
                throw new Exception('User not associated with any organization');
            }

            $orgPlaceholders = str_repeat('?,', count($organizations) - 1) . '?';
            
            // Get running audits (audits with open chats) for user's organizations
            $runningAuditsQuery = "SELECT COUNT(DISTINCT a.id) as count 
                                 FROM audits a 
                                 JOIN chats c ON a.uuid = c.audit_uuid 
                                 WHERE c.state = 'open'
                                 AND a.organization IN ($orgPlaceholders)";
            $stmt = $db->prepare($runningAuditsQuery);
            $stmt->execute($organizations);
            $runningAudits = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Get total assigned users for these organizations
            $activeUsersQuery = "SELECT COUNT(DISTINCT ua.user) as count 
                               FROM users_audit ua 
                               JOIN audits a ON ua.audit = a.id
                               WHERE a.organization IN ($orgPlaceholders)";
            $stmt = $db->prepare($activeUsersQuery);
            $stmt->execute($organizations);
            $activeUsers = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Get engaged users (users who have sent at least one message) for these organizations
            $engagedUsersQuery = "SELECT COUNT(DISTINCT u.id) as count 
                                FROM users u 
                                JOIN chats c ON c.user = u.id 
                                JOIN messages m ON m.chat_uuid = c.uuid 
                                JOIN audits a ON c.audit_uuid = a.uuid
                                WHERE m.role = 'user'
                                AND a.organization IN ($orgPlaceholders)";
            $stmt = $db->prepare($engagedUsersQuery);
            $stmt->execute($organizations);
            $engagedUsers = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Calculate engagement rate
            $engagementRate = $activeUsers > 0 ? round(($engagedUsers / $activeUsers) * 100, 2) : 0;

            // Get completed audits (all chats in finished state) for these organizations
            $completedAuditsQuery = "SELECT COUNT(DISTINCT a.id) as count 
                                   FROM audits a 
                                   JOIN chats c ON a.uuid = c.audit_uuid 
                                   WHERE c.state = 'finished'
                                   AND a.organization IN ($orgPlaceholders)";
            $stmt = $db->prepare($completedAuditsQuery);
            $stmt->execute($organizations);
            $completedAudits = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Get messages per day for last 14 days for these organizations
            $messagesQuery = "SELECT DATE(m.created_at) as date, COUNT(*) as count 
                            FROM messages m
                            JOIN chats c ON m.chat_uuid = c.uuid
                            JOIN audits a ON c.audit_uuid = a.uuid
                            WHERE m.role = 'user' 
                            AND m.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 14 DAY)
                            AND a.organization IN ($orgPlaceholders)
                            GROUP BY DATE(m.created_at) 
                            ORDER BY date";
            $stmt = $db->prepare($messagesQuery);
            $stmt->execute($organizations);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get completed audits per day for last 14 days for these organizations
            $auditsCompletedQuery = "SELECT DATE(c.updated_at) as date, COUNT(DISTINCT a.id) as count 
                                   FROM chats c
                                   JOIN audits a ON c.audit_uuid = a.uuid
                                   WHERE c.state = 'finished' 
                                   AND c.updated_at >= DATE_SUB(CURRENT_DATE, INTERVAL 14 DAY)
                                   AND a.organization IN ($orgPlaceholders)
                                   GROUP BY DATE(c.updated_at) 
                                   ORDER BY date";
            $stmt = $db->prepare($auditsCompletedQuery);
            $stmt->execute($organizations);
            $auditsCompleted = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'data' => [
                    'summary' => [
                        'running_audits' => $runningAudits,
                        'active_users' => $activeUsers,
                        'engagement_rate' => $engagementRate,
                        'completed_audits' => $completedAudits
                    ],
                    'charts' => [
                        'messages_per_day' => $messages,
                        'audits_completed_per_day' => $auditsCompleted
                    ]
                ]
            ];
        } catch (Exception $e) {
            error_log("Error getting dashboard stats: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function analyzeAudit($auditUuid) {
        try {
            $db = Database::getInstance();

            // Get all analyzed chats for this audit
            $query = "SELECT a.summary, a.keyfindings, a.sentiment, a.tags, a.topics, c.id as chat_id
                     FROM `analyze` a
                     JOIN chats c ON a.chat = c.id
                     WHERE c.audit_uuid = ? AND `a`.`tags` IS NOT NULL";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$auditUuid]);
            $analyses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($analyses)) {
                return [
                    'success' => false,
                    'error' => 'No analyzed chats found for this audit'
                ];
            }

            // Get audit ID
            $auditQuery = "SELECT id FROM audits WHERE uuid = ?";
            $stmt = $db->prepare($auditQuery);
            $stmt->execute([$auditUuid]);
            $audit = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$audit) {
                return [
                    'success' => false,
                    'error' => 'Audit not found'
                ];
            }

            // Begin transaction
            $db->beginTransaction();

            try {
                // Delete existing slides (will cascade delete findings and examples)
                $deleteQuery = "DELETE FROM audit_slides WHERE audit_id = ?";
                $stmt = $db->prepare($deleteQuery);
                $stmt->execute([$audit['id']]);

                // Prepare the data for Claude
                $findingsText = "";
                $tagsText = "";
                $topicsText = "";
                $chatSummaries = "";
                $chatMapping = [];
                foreach ($analyses as $index => $analysis) {
                    $findingsText .= "- Finding #{$index}: " . $analysis['keyfindings'] . " (Sentiment: " . $analysis['sentiment'] . ")\n";
                    if (!empty($analysis['tags'])) {
                        $tagsText .= "- Tags for finding #{$index}: Chat #{$analysis['chat_id']}: " . $analysis['tags'] . "\n";
                    }
                    if (!empty($analysis['topics'])) {
                        $topicsText .= "- Topics for finding #{$index}: Chat #{$analysis['chat_id']}: " . $analysis['topics'] . "\n";
                    }
                    if (!empty($analysis['summary'])) {
                        $chatSummaries .= "- Chat summary #{$index}: Chat #{$analysis['chat_id']}: " . $analysis['summary'] . "\n";
                    }
                    $chatMapping[$index] = $analysis['chat_id'];
                }

                // STAGE 1: Get topic list and structure
                $topicListPrompt = "You are an expert audit analyzer. Your task is to analyze the following key findings from an audit and identify the main topics for a structured presentation in Czech.

                Chat summaries: 
                $chatSummaries
                
                Key Findings:
                $findingsText
                
                Tags:
                $tagsText
                
                Topics: 
                $topicsText
                
                EXTREMELY IMPORTANT - YOU MUST RESPOND WITH ONLY JSON:
                - YOUR RESPONSE MUST START WITH { AND END WITH }
                - DO NOT INCLUDE ANY TEXT BEFORE OR AFTER THE JSON
                - DO NOT SAY 'I UNDERSTAND' OR ASK QUESTIONS
                - DO NOT EXPLAIN WHAT YOU'RE DOING
                - DO NOT USE MARKDOWN CODE BLOCKS
                - NEVER WRITE ANYTHING EXCEPT THE RAW JSON OBJECT
                
                Instructions for analysis:
                1. IDENTIFY 8-12 MAIN TOPIC AREAS from these findings
                2. For each topic:
                   - Create a clear, descriptive name (title)
                   - Provide a detailed description of at least 400 characters
                   - Identify which chat findings (by index number) belong to this topic
                3. Create an overview of all main topics
                4. Structure must be logical and comprehensive
                5. Topics should be distinct but cover all important areas
                
                YOUR ENTIRE RESPONSE MUST BE THIS JSON STRUCTURE, WITH NO OTHER TEXT:
                {
                    \"topics\": [
                        {
                            \"name\": \"Název kategorie\",
                            \"description\": \"Podrobný popis kategorie v alespoň 400 znacích\",
                            \"finding_indices\": [0, 5, 10, 15]
                        }
                    ],
                    \"tags_cloud\": [
                        {
                            \"tag\": \"Tag1\",
                            \"weight\": 10
                        }
                    ]
                }
                
                Rules for your JSON response:
                - All content must be in clear, professional Czech
                - IDENTIFY AT LEAST 8-12 DISTINCT TOPICS
                - Make topics specific enough to be meaningful but broad enough to group related findings
                - Each topic description must thoroughly explain what the category covers
                - Don't just repeat the title in the description - provide context and significance
                - YOUR ENTIRE RESPONSE MUST BE VALID JSON STARTING WITH { AND ENDING WITH }";
                
                // Get topic structure from Anthropic
                $topicResponse = $this->anthropic->analyzeAuditWithSonnet($topicListPrompt, true);
                error_log("Raw Claude topic response: " . $topicResponse);
                
                $topicResult = json_decode($topicResponse, true);
                
                if (json_last_error() !== JSON_ERROR_NONE || !isset($topicResult['topics'])) {
                    error_log("JSON parse error in topic list: " . json_last_error_msg());
                    throw new Exception("Failed to parse topic list response: " . json_last_error_msg());
                }
                
                // Array to store all findings for the home slide
                $allFindings = [];
                $slideTitles = [];
                $severityCounts = ['low' => 0, 'medium' => 0, 'high' => 0];
                
                // STAGE 2: Process each topic to get detailed findings
                foreach ($topicResult['topics'] as $topicIndex => $topic) {
                    // Create the slide first
                    $slideQuery = "INSERT INTO audit_slides (audit_id, name, description, order_index) 
                                 VALUES (?, ?, ?, ?)";
                    $stmt = $db->prepare($slideQuery);
                    $stmt->execute([$audit['id'], $topic['name'], $topic['description'], $topicIndex + 1]);
                    $slideId = $db->lastInsertId();
                    
                    // Store slide name for home slide generation
                    $slideTitles[] = $topic['name'];
                    
                    // Extract the relevant findings for this topic
                    $topicFindings = "";
                    $topicTags = "";
                    $topicChatSummaries = "";
                    $relevantChatIds = [];
                    
                    if (isset($topic['finding_indices']) && is_array($topic['finding_indices'])) {
                        foreach ($topic['finding_indices'] as $findingIndex) {
                            if (isset($analyses[$findingIndex])) {
                                $analysis = $analyses[$findingIndex];
                                $topicFindings .= "- Finding #{$findingIndex}: " . $analysis['keyfindings'] . " (Sentiment: " . $analysis['sentiment'] . ")\n";
                                if (!empty($analysis['tags'])) {
                                    $topicTags .= "- Tags for finding #{$findingIndex}: " . $analysis['tags'] . "\n";
                                }
                                if (!empty($analysis['summary'])) {
                                    $topicChatSummaries .= "- Chat summary #{$findingIndex}: " . $analysis['summary'] . "\n";
                                }
                                $relevantChatIds[] = $findingIndex;
                            }
                        }
                    }
                    
                    // Prepare the prompt for detailed findings on this topic
                    $findingsPrompt = "You are an expert audit analyzer. Your task is to analyze the following findings related to the topic \"{$topic['name']}\" and create detailed findings for this topic section in Czech.

                    Topic Name: {$topic['name']}
                    Topic Description: {$topic['description']}
                    
                    Relevant Chat Summaries: 
                    $topicChatSummaries
                    
                    Relevant Findings:
                    $topicFindings
                    
                    Relevant Tags:
                    $topicTags
                    
                    EXTREMELY IMPORTANT - YOU MUST RESPOND WITH ONLY JSON:
                    - YOUR RESPONSE MUST START WITH { AND END WITH }
                    - DO NOT INCLUDE ANY TEXT BEFORE OR AFTER THE JSON
                    - DO NOT SAY 'I UNDERSTAND' OR ASK QUESTIONS
                    - DO NOT EXPLAIN WHAT YOU'RE DOING
                    - DO NOT USE MARKDOWN CODE BLOCKS
                    - NEVER WRITE ANYTHING EXCEPT THE RAW JSON OBJECT
                    
                    Instructions for analysis:
                    1. Create AT LEAST 4-6 DETAILED FINDINGS for this specific topic
                    2. Each finding should be unique and specific to this topic area
                    3. For each finding:
                       - Create a clear, descriptive title
                       - Provide a detailed description of at least 300 characters
                       - Assess severity (low, medium, high)
                       - Write comprehensive recommendations of at least 400 characters
                       - Identify which chat indices this finding relates to
                    4. Findings should be comprehensive and cover all important aspects of this topic
                    
                    YOUR ENTIRE RESPONSE MUST BE THIS JSON STRUCTURE, WITH NO OTHER TEXT:
                    {
                        \"findings\": [
                            {
                                \"title\": \"Jasný název zjištění\",
                                \"description\": \"Podrobný popis zjištění v rozsahu alespoň 300 znaků. Vysvětlete, v čem spočívá problém nebo příležitost, jaký má dopad a proč je důležité se tímto zjištěním zabývat. Popis by měl být informativní a specifický.\",
                                \"severity\": \"low\",
                                \"recommendation\": \"Komplexní a konkrétní doporučení v rozsahu alespoň 400 znaků. Uveďte konkrétní kroky, které by měly být podniknuty, jak je implementovat, a jaký přínos to bude mít. Doporučení by mělo být praktické, proveditelné a přímo řešit popsané zjištění.\",
                                \"chat_id\": [0, 1, 2]
                            }
                        ]
                    }
                    
                    Rules for your JSON response:
                    - All content must be in clear, professional Czech
                    - severity must be exactly one of: 'low', 'medium', 'high'
                    - CREATE AT LEAST 4-6 DETAILED FINDINGS for this topic
                    - DETAILED DESCRIPTIONS: All finding descriptions must be at least 300 characters
                    - DETAILED RECOMMENDATIONS: All recommendations must be at least 400 characters
                    - Be specific and actionable in your recommendations
                    - YOUR ENTIRE RESPONSE MUST BE VALID JSON STARTING WITH { AND ENDING WITH }";
                    
                    // Get detailed findings for this topic
                    $findingsResponse = $this->anthropic->analyzeAuditWithSonnet($findingsPrompt, true);
                    error_log("Raw Claude findings response for topic {$topic['name']}: " . $findingsResponse);
                    
                    $findingsResult = json_decode($findingsResponse, true);
                    
                    if (json_last_error() !== JSON_ERROR_NONE || !isset($findingsResult['findings'])) {
                        error_log("JSON parse error in findings: " . json_last_error_msg());
                        continue; // Skip this topic if there's an error, don't fail the whole process
                    }
                    
                    // Process and save the findings
                    foreach ($findingsResult['findings'] as $findingIndex => $finding) {
                        // Track findings for home slide
                        $allFindings[] = $finding;
                        $severityCounts[$finding['severity']]++;
                        
                        // Insert finding
                        $findingQuery = "INSERT INTO audit_findings (slide_id, title, description, recommendation, severity, order_index) 
                                       VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt = $db->prepare($findingQuery);
                        $stmt->execute([
                            $slideId,
                            $finding['title'],
                            $finding['description'],
                            $finding['recommendation'],
                            $finding['severity'],
                            $findingIndex
                        ]);
                        $findingId = $db->lastInsertId();
                        
                        // Create example if chat_id is valid
                        if (isset($finding['chat_id']) && is_array($finding['chat_id'])) {
                            foreach ($finding['chat_id'] as $chatIndex) {
                                if (isset($chatMapping[$chatIndex])) {
                                    $exampleQuery = "INSERT INTO audit_finding_examples (finding_id, chat_id) 
                                                  VALUES (?, ?)";
                                    $stmt = $db->prepare($exampleQuery);
                                    $stmt->execute([$findingId, $chatMapping[$chatIndex]]);
                                }
                            }
                        }
                    }
                }
                
                // Save tags cloud data
                if (isset($topicResult['tags_cloud']) && !empty($topicResult['tags_cloud'])) {
                    // Delete existing tags for this audit
                    $deleteTagsQuery = "DELETE FROM audit_tags_cloud WHERE audit_id = ?";
                    $stmt = $db->prepare($deleteTagsQuery);
                    $stmt->execute([$audit['id']]);
                    
                    // Insert new tags
                    $insertTagQuery = "INSERT INTO audit_tags_cloud (audit_id, tag, weight) VALUES (?, ?, ?)";
                    $stmt = $db->prepare($insertTagQuery);
                    
                    foreach ($topicResult['tags_cloud'] as $tag) {
                        if (isset($tag['tag']) && isset($tag['weight'])) {
                            $stmt->execute([$audit['id'], $tag['tag'], $tag['weight']]);
                        }
                    }
                }
                
                // Now create the home slide based on the generated content
                $homeSlidePrompt = "You are an expert audit analyzer. Based on the following audit slides and findings, create a comprehensive executive summary in Czech language. This will be the home slide for the audit presentation.

Slides Categories:
" . implode(", ", $slideTitles) . "

Total findings: " . count($allFindings) . "
Severity distribution:
- High severity: {$severityCounts['high']}
- Medium severity: {$severityCounts['medium']}
- Low severity: {$severityCounts['low']}

Key findings:
";

                foreach ($allFindings as $finding) {
                    $severity = ucfirst($finding['severity']);
                    $homeSlidePrompt .= "- [{$severity}] {$finding['title']}: {$finding['recommendation']}\n";
                }

                $homeSlidePrompt .= "

EXTREMELY IMPORTANT - YOU MUST FOLLOW THESE RULES:
- YOUR RESPONSE MUST BE PURE HTML CONTENT ONLY
- DO NOT INCLUDE ANY TEXT BEFORE OR AFTER THE HTML
- DO NOT SAY 'I UNDERSTAND' OR ASK QUESTIONS
- DO NOT EXPLAIN WHAT YOU'RE DOING
- DO NOT USE MARKDOWN CODE BLOCKS
- NEVER WRITE ANYTHING EXCEPT THE RAW HTML CONTENT

Instructions for content creation:
1. Create a comprehensive, insightful executive summary that follows EXACTLY the HTML structure shown below
2. Use the exact HTML structure with the same classes, divs, and SVG elements as shown in the example
3. Replace only the text content with your analysis while keeping all HTML structure, classes and elements
4. Keep the same three sections: Shrnutí, Klíčové zjištění, and Návrhy na zlepšení
5. Your content should analyze the audit findings in professional Czech
6. The summary should be concise but comprehensive
7. Key findings should focus on the most important insights from across all topics
8. Improvement suggestions should be specific and actionable

EXACTLY COPY THIS HTML STRUCTURE AND ONLY REPLACE THE TEXT CONTENT:
<div><h3 class=\"text-sm font-medium mb-2\">Shrnutí</h3><p class=\"text-sm\">[YOUR COMPREHENSIVE SUMMARY HERE - 2-3 SENTENCES ABOUT THE AUDIT FINDINGS]</p></div><div><div class=\"flex items-center mb-2\"><svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"lucide lucide-circle-check-big h-4 w-4 mr-2 text-green-500\"><path d=\"M21.801 10A10 10 0 1 1 17 3.335\"></path><path d=\"m9 11 3 3L22 4\"></path></svg><h3 class=\"text-sm font-medium\">Klíčové zjištění</h3></div><div class=\"whitespace-pre-line text-sm\">[LIST 4-6 BULLET POINTS OF KEY FINDINGS, EACH STARTING WITH • ]</div></div><div><div class=\"flex items-center mb-2\"><svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"lucide lucide-circle-alert h-4 w-4 mr-2 text-amber-500\"><circle cx=\"12\" cy=\"12\" r=\"10\"></circle><line x1=\"12\" x2=\"12\" y1=\"8\" y2=\"12\"></line><line x1=\"12\" x2=\"12.01\" y1=\"16\" y2=\"16\"></line></svg><h3 class=\"text-sm font-medium\">Návrhy na zlepšení</h3></div><div class=\"whitespace-pre-line text-sm\">[LIST 3-5 BULLET POINTS OF IMPROVEMENT SUGGESTIONS, EACH STARTING WITH • ]</div></div>

YOUR ENTIRE RESPONSE MUST BE VALID HTML CONTENT THAT MATCHES THIS STRUCTURE EXACTLY. START DIRECTLY WITH <div> AND END WITH </div>.";

                // Get home slide content from Anthropic
                $homeSlideResponse = $this->anthropic->analyzeAuditWithSonnet($homeSlidePrompt, false);
                
                // Create home slide
                $homeSlideQuery = "INSERT INTO audit_slides (audit_id, name, description, is_home, html_content, order_index) 
                                 VALUES (?, 'Home', 'Executive Summary', 1, ?, 0)";
                $stmt = $db->prepare($homeSlideQuery);
                $stmt->execute([$audit['id'], $homeSlideResponse]);

                $db->commit();

                return [
                    'success' => true,
                    'message' => 'Audit analysis created successfully with multi-stage processing'
                ];
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("Error in analyzeAudit: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 