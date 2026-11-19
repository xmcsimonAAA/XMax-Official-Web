<?php

return [
    'author_id' => (int)env('EXTERNAL_CONTENT_AUTHOR_ID', 1),
    'http'      => [
        'timeout'         => (int)env('EXTERNAL_CONTENT_HTTP_TIMEOUT', 25),
        'connect_timeout' => (int)env('EXTERNAL_CONTENT_CONNECT_TIMEOUT', 10),
        'retries'         => (int)env('EXTERNAL_CONTENT_HTTP_RETRIES', 3),
        'user_agent'      => env(
            'EXTERNAL_CONTENT_USER_AGENT',
            'XMax Inc. Website Sync XMaxIR@icrinc.com'
        ),
    ],
    'sec'       => [
        'cik'                => env('EXTERNAL_CONTENT_SEC_CIK', '0001473334'),
        'company_name'       => 'XMax Inc.',
        'category_parent_id' => (int)env('EXTERNAL_CONTENT_SEC_CATEGORY_PARENT_ID', 3),
        'backfill_from_year' => (int)env('EXTERNAL_CONTENT_SEC_FROM_YEAR', 1994),
    ],
    'globe'     => [
        'organization_name' => 'XMax Inc.',
        'organization_url'  => 'https://www.globenewswire.com/en/search/organization/XMax%2520Inc%C2%A7?page=%d',
        'category_id'       => (int)env('EXTERNAL_CONTENT_GLOBE_CATEGORY_ID', 2),
        'pages_per_run'     => (int)env('EXTERNAL_CONTENT_GLOBE_PAGES', 1),
    ],
];
