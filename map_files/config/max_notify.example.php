<?php

return [
    'key' => getenv('MAX_NOTIFY_KEY') ?: '',
    'base_url' => getenv('MAX_API_BASE_URL') ?: 'https://platform-api.max.ru',
    'token' => getenv('MAX_API_TOKEN') ?: '',
    'chat_id' => getenv('MAX_CHAT_ID') ?: '',
    'admin_password' => getenv('MAX_ADMIN_PASSWORD') ?: '',
];

