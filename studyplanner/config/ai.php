<?php

define('AI_PROVIDER', 'anthropic');
define('AI_MODEL', 'claude-sonnet-4-20250514');
define('AI_API_KEY', '');

function getAiApiKey() {
    if (!empty($_SESSION['anthropic_api_key'])) {
        return trim($_SESSION['anthropic_api_key']);
    }

    $env_key = getenv('ANTHROPIC_API_KEY');
    if (!empty($env_key)) {
        return trim($env_key);
    }

    if (defined('AI_API_KEY') && AI_API_KEY !== '') {
        return trim(AI_API_KEY);
    }

    return '';
}

