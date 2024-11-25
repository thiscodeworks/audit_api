<?php

class AnthropicService {
    private $apiKey;
    private $baseUrl = 'https://api.anthropic.com/v1/messages';
    private $pusherService;
    private $message;

    public function __construct(PusherService $pusherService) {
        $this->apiKey = getenv('ANTHROPIC_API_KEY');
        $this->pusherService = $pusherService;
    }

    public function getResponse($messages, $systemPrompt, $chatUuid) {
        error_log("Starting streaming for chat: " . $chatUuid);
        
        // Send start message event
        $this->pusherService->trigger(
            'chat-' . $chatUuid,
            'message-start',
            ['status' => 'started']
        );

        $formattedMessages = [];
        foreach ($messages as $message) {
            $formattedMessages[] = [
                'role' => $message['role'],
                'content' => $message['content']
            ];
        }

        $data = [
            'model' => 'claude-3-haiku-20240307',
            'max_tokens' => 4096,
            'messages' => $formattedMessages,
            'stream' => true,
            'temperature' => 0.7
        ];

        if ($systemPrompt) {
            $data['system'] = $systemPrompt;
        }

        error_log("Request data: " . json_encode($data));

        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'anthropic-version: 2023-06-01',
            'x-api-key: ' . $this->apiKey,
            'Accept: text/event-stream'
        ]);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) {
            error_log("Response header: " . trim($header));
            return strlen($header);
        });

        if (ob_get_level()) ob_end_clean();
        
        $buffer = '';
        $fullResponse = '';
        $completeResponse = '';
        
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use ($chatUuid, &$buffer, &$fullResponse, &$completeResponse) {
            $fullResponse .= $data;
            error_log("Raw chunk received: " . bin2hex($data));
            
            $buffer .= $data;
            
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                
                error_log("Processing line: " . $line);
                
                if (empty(trim($line))) continue;
                
                if (strpos($line, 'data: ') === 0) {
                    $jsonData = trim(substr($line, 6));
                    error_log("JSON data: " . $jsonData);
                    
                    if ($jsonData === '[DONE]') {
                        error_log("Stream completed");
                        continue;
                    }
                    
                    try {
                        $message = json_decode($jsonData, true);
                        error_log("Decoded message: " . json_encode($message));
                        
                        if (isset($message['delta']['text'])) {
                            $text = $message['delta']['text'];
                            $completeResponse .= $text;
                            error_log("Sending to Pusher - Channel: chat-{$chatUuid}, Text: {$text}");
                            
                            try {
                                $result = $this->pusherService->trigger(
                                    'chat-' . $chatUuid,
                                    'message-chunk',
                                    ['text' => $text]
                                );
                                error_log("Pusher result: " . json_encode($result));
                            } catch (Exception $e) {
                                error_log("Pusher error: " . $e->getMessage());
                            }
                        }
                    } catch (Exception $e) {
                        error_log("JSON decode error: " . $e->getMessage());
                    }
                }
            }
            
            return strlen($data);
        });

        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            error_log("Curl error: " . $error);
            throw new Exception('Curl error: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseInfo = curl_getinfo($ch);
        error_log("CURL Info: " . json_encode($responseInfo));
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("HTTP error: " . $httpCode . ", Full Response: " . $fullResponse);
            throw new Exception('Anthropic API error: ' . $fullResponse);
        }

        error_log("Streaming completed successfully");
        error_log("Complete response: " . $completeResponse);

        if (!empty($completeResponse)) {
            // Save the complete response to database
            $this->message = new Message();
            $messageUuid = $this->message->create($chatUuid, $completeResponse, 'assistant');
            error_log("Saved assistant message with UUID: " . $messageUuid);

            // Send end message event with the message UUID
            $this->pusherService->trigger(
                'chat-' . $chatUuid,
                'message-end',
                [
                    'status' => 'completed',
                    'message_uuid' => $messageUuid
                ]
            );
        } else {
            error_log("Warning: Empty response from assistant");
            // Send error end message event
            $this->pusherService->trigger(
                'chat-' . $chatUuid,
                'message-end',
                [
                    'status' => 'error',
                    'error' => 'Empty response from assistant'
                ]
            );
        }

        return true;
    }
} 