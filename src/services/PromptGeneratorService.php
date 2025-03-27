<?php

require_once __DIR__ . '/AnthropicService.php';

class PromptGeneratorService {
    private $anthropic;
    private $promptTemplateDir;

    public function __construct() {
        $this->anthropic = new AnthropicService(null);
        $this->promptTemplateDir = __DIR__ . '/../prompts';
    }

    /**
     * Generate a system prompt for an audit using Claude API
     * 
     * @param array $auditData The audit data to use for generating the prompt
     * @return array An array containing 'success' and either 'prompt' or 'error'
     */
    public function generateAuditPrompt($auditData) {
        try {
            // Load the Claude prompt generator template
            $templatePath = $this->promptTemplateDir . '/claude_prompt_generator.txt';
            $template = file_get_contents($templatePath);
            
            if ($template === false) {
                throw new Exception("Failed to load Claude prompt generator template");
            }

            // Format questions list for Claude
            $questionsList = '';
            if (isset($auditData['questions']) && is_array($auditData['questions'])) {
                foreach ($auditData['questions'] as $index => $question) {
                    $questionsList .= ($index + 1) . ". " . $question . "\n";
                }
            }

            // Get company description if available
            $companyDescription = '';
            if (isset($auditData['organization_name'])) {
                $companyDescription = $auditData['organization_name'];
            }

            // Prepare focus areas
            $focusAreas = isset($auditData['description']) ? $auditData['description'] : 'workplace assessment';

            // Fill in the template with audit data
            $filledTemplate = str_replace(
                [
                    '{{company_name}}',
                    '{{audit_type}}',
                    '{{audit_name}}',
                    '{{audit_description}}',
                    '{{audit_focus_areas}}',
                    '{{questions_list}}'
                ],
                [
                    $auditData['company_name'] ?? 'Unknown Company',
                    $auditData['type'] ?? 'general',
                    $auditData['title'] ?? 'Workplace Audit',
                    $auditData['description'] ?? 'Assessing workplace environment and employee satisfaction',
                    $focusAreas,
                    $questionsList
                ],
                $template
            );

            // Send to Claude API
            $generatedPrompt = $this->anthropic->generateContent($filledTemplate);
            
            if (empty($generatedPrompt)) {
                throw new Exception("Claude API returned an empty response");
            }

            // Claude should return just the XML, but let's extract it to be safe
            $xmlPattern = '/<audit_system_prompt>.*?<\/audit_system_prompt>/s';
            if (preg_match($xmlPattern, $generatedPrompt, $matches)) {
                $xmlContent = $matches[0];
            } else {
                // If no XML pattern is found, use the entire response
                $xmlContent = $generatedPrompt;
            }

            // Log the generated prompt for debugging
            error_log("Generated XML prompt: " . $xmlContent);

            return [
                'success' => true,
                'prompt' => $xmlContent
            ];
        } catch (Exception $e) {
            error_log("Error generating audit prompt: " . $e->getMessage());
            
            // Fall back to the default template if there's an error
            try {
                return [
                    'success' => false,
                    'prompt' => $this->getDefaultPrompt($auditData),
                    'error' => $e->getMessage()
                ];
            } catch (Exception $fallbackException) {
                return [
                    'success' => false,
                    'error' => "Failed to generate prompt: " . $e->getMessage() . 
                              ". Fallback also failed: " . $fallbackException->getMessage()
                ];
            }
        }
    }

    /**
     * Generate a default prompt using the template without Claude
     * 
     * @param array $auditData The audit data to use for generating the prompt
     * @return string The default prompt
     */
    private function getDefaultPrompt($auditData) {
        // Load the default template
        $templatePath = $this->promptTemplateDir . '/audit_system_prompt.xml';
        $template = file_get_contents($templatePath);
        
        if ($template === false) {
            throw new Exception("Failed to load default prompt template");
        }

        // Format questions list
        $questionsList = "<question_list>\n";
        if (isset($auditData['questions']) && is_array($auditData['questions'])) {
            foreach ($auditData['questions'] as $question) {
                $questionsList .= "  <question>" . htmlspecialchars($question) . "</question>\n";
            }
        }
        $questionsList .= "</question_list>";

        // Fill in the template with audit data
        return str_replace(
            [
                '{{company_name}}',
                '{{company_description}}',
                '{{audit_name}}',
                '{{audit_type}}',
                '{{audit_description}}',
                '{{audit_goals}}',
                '{{audit_focus}}',
                '{{questions_list}}'
            ],
            [
                htmlspecialchars($auditData['company_name'] ?? 'Unknown Company'),
                htmlspecialchars($auditData['organization_name'] ?? 'A company undergoing an audit process'),
                htmlspecialchars($auditData['title'] ?? 'Workplace Audit'),
                htmlspecialchars($auditData['type'] ?? 'general'),
                htmlspecialchars($auditData['description'] ?? 'Assessing workplace environment and employee satisfaction'),
                'Identify areas of improvement and gather employee feedback',
                htmlspecialchars($auditData['description'] ?? 'workplace environment'),
                $questionsList
            ],
            $template
        );
    }
} 