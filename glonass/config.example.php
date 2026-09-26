<?php

$trackerIds = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) (getenv('GLONASS_TRACKER_IDS') ?: ''))
)));

return [
    'api_base_url' => getenv('GLONASS_API_BASE_URL') ?: 'https://fm2.delta.ru',
    'api_login' => getenv('GLONASS_API_LOGIN') ?: '',
    'api_password' => getenv('GLONASS_API_PASSWORD') ?: '',
    'api_token' => getenv('GLONASS_API_TOKEN') ?: '',
    'tracker_ids' => $trackerIds,
];

