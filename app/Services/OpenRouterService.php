<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    protected string $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        // Read from DB first, fall back to env/config
        try {
            $settings = \App\Models\AiSetting::instance();
            $dbKey = $settings->decrypted_api_key;
            $this->apiKey = !empty($dbKey) ? $dbKey : config('services.openrouter.api_key', env('OPENROUTER_API_KEY', ''));
            $this->model = $settings->model ?: config('services.openrouter.model', env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'));
        } catch (\Throwable $e) {
            // DB not migrated yet — fall back to env
            $this->apiKey = config('services.openrouter.api_key', env('OPENROUTER_API_KEY', ''));
            $this->model = config('services.openrouter.model', env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'));
        }
    }

    /**
     * Check if the service is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Define the strict JSON schema that the LLM must follow when returning a diagnosis.
     */
    protected function getDiagnosisSchema(): array
    {
        return [
            'name' => 'deploy_diagnosis',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['healthy', 'building', 'failed', 'unknown'],
                        'description' => 'Current status of the deployment based on logs'
                    ],
                    'root_cause' => [
                        'type' => 'string',
                        'description' => 'A clear, developer-friendly explanation of why the deployment failed.'
                    ],
                    'confidence' => [
                        'type' => 'integer',
                        'description' => '0-100 score of how confident you are in this diagnosis.'
                    ],
                    'evidence' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => '2-3 specific log lines or context snippets that prove your root_cause.'
                    ],
                    'recommended_actions' => [
                        'type' => 'array',
                        'description' => 'Manual steps or tools the user can run to fix the problem.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string', 'description' => 'Button label, eg "Patch Database Credentials"'],
                                'tool' => ['type' => 'string', 'description' => 'Internal tool identifier (e.g. patch_envs, retry_deploy)'],
                                'safe' => ['type' => 'boolean', 'description' => 'Whether this action is safe to run automatically']
                            ],
                            'required' => ['label', 'tool', 'safe'],
                            'additionalProperties' => false
                        ]
                    ],
                    'human_message' => [
                        'type' => 'string',
                        'description' => 'A short, conversational explanation to the operator.'
                    ]
                ],
                'required' => ['status', 'root_cause', 'confidence', 'evidence', 'recommended_actions', 'human_message'],
                'additionalProperties' => false
            ]
        ];
    }

    /**
     * Define the available tools the LLM can explicitly request to invoke (OpenAI-compatible)
     */
    protected function getTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'retry_deploy',
                    'description' => 'Retry the deployment for the current Coolify application.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => ['type' => 'string', 'description' => 'Why retrying the deploy will help']
                        ],
                        'required' => ['reason'],
                        'additionalProperties' => false
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'patch_envs',
                    'description' => 'Update or inject environment variables in the Coolify vault.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'envs' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'key' => ['type' => 'string'],
                                        'value' => ['type' => 'string']
                                    ],
                                    'required' => ['key', 'value'],
                                    'additionalProperties' => false
                                ]
                            ]
                        ],
                        'required' => ['envs'],
                        'additionalProperties' => false
                    ]
                ]
            ]
        ];
    }

    /**
     * Send the log and context payload to OpenRouter for analysis
     */
    public function analyzeDeployment(array $messages, bool $allowTools = false): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('[OpenRouterService] API key is missing.');
            return null;
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => $this->getDiagnosisSchema()
            ]
        ];

        // Only inject tools if the mode is Fixer / we explicitly prompt for tool orchestration
        if ($allowTools) {
            $payload['tools'] = $this->getTools();
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'HTTP-Referer' => config('app.url', 'https://ycookies.dev'),
                    'X-Title' => 'YCookies AI Guardian'
                ])
                ->timeout(60) // LLM inferences can take time
                ->post($this->apiUrl, $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('[OpenRouterService] OpenRouter API Error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('[OpenRouterService] Exception connecting to OpenRouter: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * JSON schema for health check analysis responses.
     */
    protected function getHealthCheckSchema(): array
    {
        return [
            'name' => 'health_diagnosis',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'overall_assessment' => [
                        'type' => 'string',
                        'enum' => ['healthy', 'warning', 'critical'],
                        'description' => 'Overall assessment of the domain health'
                    ],
                    'root_cause' => [
                        'type' => 'string',
                        'description' => 'Clear explanation of what is wrong (or confirmation everything is fine)'
                    ],
                    'confidence' => [
                        'type' => 'integer',
                        'description' => '0-100 score of confidence in this diagnosis'
                    ],
                    'suggested_fixes' => [
                        'type' => 'array',
                        'description' => 'Prioritized list of actionable fixes',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'description' => 'Short action title'],
                                'description' => ['type' => 'string', 'description' => 'Detailed steps to fix'],
                                'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']]
                            ],
                            'required' => ['title', 'description', 'priority'],
                            'additionalProperties' => false
                        ]
                    ],
                    'human_message' => [
                        'type' => 'string',
                        'description' => 'Conversational summary for the admin'
                    ]
                ],
                'required' => ['overall_assessment', 'root_cause', 'confidence', 'suggested_fixes', 'human_message'],
                'additionalProperties' => false
            ]
        ];
    }

    /**
     * Analyze health check results for a domain using AI.
     */
    public function analyzeHealthCheck(string $domain, array $checkData): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('[OpenRouterService] API key is missing.');
            return null;
        }

        $systemPrompt = "You are the YCookies Health Analyzer. You receive structured health check "
            . "results for a domain proxied through the YCookies consent management platform.\n\n"
            . "YCookies architecture: Customer DNS → YCookies Node proxy → Origin server. "
            . "The proxy injects a cookie consent banner, blocks unconsented scripts, and serves the page.\n\n"
            . "Checks include: domain reachability, proxy header presence, script injection, config endpoints, "
            . "origin server access, consent logging, CSP blocking, duplicate injection, response headers, and page load time.\n\n"
            . "Analyze the check results and identify:\n"
            . "1. The root cause of any failures or warnings\n"
            . "2. Prioritized fix suggestions the admin can act on\n"
            . "3. Whether failures are expected (e.g. firewalled origins returning 403/503) or real issues\n\n"
            . "Be concise and actionable. Focus on what the admin should DO next. "
            . "If everything is healthy, confirm it briefly and mention any minor improvements.";

        $userContent = "Domain: {$domain}\n\n"
            . "Health Check Results:\n"
            . json_encode($checkData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => substr($userContent, 0, 15000)],
        ];

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => $this->getHealthCheckSchema()
            ]
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'HTTP-Referer' => config('app.url', 'https://ycookies.dev'),
                    'X-Title' => 'YCookies Health Analyzer'
                ])
                ->timeout(60)
                ->post($this->apiUrl, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? null;
                return $content ? json_decode($content, true) : null;
            }

            Log::error('[OpenRouterService] Health analysis API error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
        } catch (\Exception $e) {
            Log::error('[OpenRouterService] Health analysis exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Analyze raw container logs from Coolify to detect errors, memory leaks, or crashes.
     */
    public function analyzeContainerLogs(string $uuid, string $logs): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('[OpenRouterService] API key is missing.');
            return null;
        }

        $systemPrompt = "You are the YCookies Infrastructure AI. You are a senior DevOps engineer analyzing raw Docker "
            . "container logs from a Coolify deployment. Your goal is to identify why a container might be "
            . "crashing, returning 504 Gateway Timeouts, or exhibiting other errors.\n\n"
            . "Look for:\n"
            . "- OOM (Out Of Memory) kills or memory limit warnings\n"
            . "- Exceptions, stack traces, and FATAL errors\n"
            . "- Slow database queries or connection timeouts\n"
            . "- Nginx/PHP-FPM worker exhaustion\n"
            . "- Node.js unhandled rejections\n\n"
            . "Be concise, developer-friendly, and actionable.";

        $userContent = "Container/App UUID: {$uuid}\n\n"
            . "Recent Logs (tail):\n"
            . $logs;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            // We truncate logs to ~30k chars to avoid token limits, taking the most recent logs (end of string)
            ['role' => 'user', 'content' => substr($userContent, -30000)],
        ];

        return $this->analyzeDeployment($messages, false);
    }

    /**
     * Simple prompt → response wrapper for free-form AI questions.
     * Used by the daily digest AI brief generation.
     */
    public function ask(string $prompt): ?string
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $messages = [
            ['role' => 'system', 'content' => 'You are YCoppilot, a traffic analysis assistant for the YCookies proxy platform. Be concise and actionable.'],
            ['role' => 'user', 'content' => substr($prompt, 0, 15000)],
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'HTTP-Referer' => config('app.url', 'https://ycookies.dev'),
                    'X-Title' => 'YCookies Daily Digest',
                ])
                ->timeout(30)
                ->post($this->apiUrl, [
                    'model'    => $this->model,
                    'messages' => $messages,
                ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content');
            }

            Log::warning('[OpenRouterService] ask() API error', [
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::warning('[OpenRouterService] ask() exception: ' . $e->getMessage());
        }

        return null;
    }
}
