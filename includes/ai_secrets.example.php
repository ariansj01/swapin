<?php
// Copy to ai_secrets.php and set your API keys.
// Never commit ai_secrets.php to version control.

define('GROQ_API_KEY', 'gsk_your_key_here');
define('GROQ_MODEL', 'openai/gpt-oss-120b');

define('OPENROUTER_API_KEY', 'sk-or-v1_your_key_here');
define('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct');

define('GEMINI_API_KEY', '');
define('GEMINI_MODEL', 'gemini-3.8-flash');

// Per-user AI usage limits
define('AI_CHAT_USER_LIMIT', 30);
define('AI_CHAT_USER_WINDOW', 3600);
define('AI_MATCH_REFRESH_LIMIT', 6);
define('AI_MATCH_REFRESH_WINDOW', 3600);
define('AI_VALUATE_USER_LIMIT', 3);
define('AI_VALUATE_USER_WINDOW', 900);
