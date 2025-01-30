<?php

require_once __DIR__ . '/../services/AnalysisService.php';

class AnalyzeController {
    private $analysisService;

    public function __construct() {
        $this->analysisService = new AnalysisService();
    }

    public function analyzeChat($uuid) {
        try {
            // Run the analysis for a single chat
            $result = $this->analysisService->analyzeSingleChat($uuid["uuid"]);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'data' => $result['data']
                ]);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result['error']
                ]);
            }
        } catch (Exception $e) {
            error_log("Error in AnalyzeController@analyzeChat: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]);
        }
    }

    // Keep the existing run() method for analyzing all pending chats
    public function run() {
        try {
            $result = $this->analysisService->analyzePendingChats();
            
            if ($result['success']) {
                if (isset($result['message'])) {
                    // No chats to analyze
                    echo json_encode([
                        'success' => true,
                        'message' => $result['message']
                    ]);
                } else {
                    // One chat analyzed
                    echo json_encode([
                        'success' => true,
                        'data' => $result['data']
                    ]);
                }
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $result['error']
                ]);
            }
        } catch (Exception $e) {
            error_log("Error in AnalyzeController@run: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]);
        }
    }

    public function getChatDetail($uuid) {
        try {
            $result = $this->analysisService->getChatDetail($uuid);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'data' => $result['data']
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => $result['error']
                ]);
            }
        } catch (Exception $e) {
            error_log("Error in AnalyzeController@getChatDetail: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]);
        }
    }

    public function findByCode() {
        try {
            // Get code from POST data
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['code'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Code is required'
                ]);
                return;
            }

            $result = $this->analysisService->findAuditByCode($data['code']);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'data' => $result['data']
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => $result['error']
                ]);
            }
        } catch (Exception $e) {
            error_log("Error in AnalyzeController@findByCode: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]);
        }
    }

    public function getDashboardStats() {
        try {
            $result = $this->analysisService->getDashboardStats();
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'data' => $result['data']
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $result['error']
                ]);
            }
        } catch (Exception $e) {
            error_log("Error in AnalyzeController@getDashboardStats: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]);
        }
    }
} 