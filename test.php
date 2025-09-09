<?php

require __DIR__ . '/packages/default/send-email/vendor/autoload.php';

$data = json_encode([
    'name' => 'Yehor',
]);
if (!$data) {
    exit('Please supply data argument.');
}

$args = [
    'smtp_server' => 'smtp.mailgun.org',
    'smtp_port' => 587,
    'smtp_username' => '...',
    'smtp_password' => '...',
    'subject' => 'PDF file test',
    'sender_email' => '...',
    'sender_name' => 'Test Sender',
    'recipient_email' => 'egorsmkv@gmail.com',
    'recipient_name' => 'Yehor Smoliakov',
    'template' => 'hello',
    'variables' => base64_encode($data),
    'attachment_urls' => [
        [
            'filename' => 'test_1.pdf',
            'url' => 'https://raw.githubusercontent.com/egorsmkv/do-functions-php-send-email/refs/heads/main/packages/default/send-email/test.pdf',
            'type' => 'application/pdf',
        ],
        [
            'filename' => 'test_2.pdf',
            'url' => 'https://raw.githubusercontent.com/egorsmkv/do-functions-php-send-email/refs/heads/main/packages/default/send-email/test.pdf',
            'type' => 'application/pdf',
        ],
    ],
];

$client = new \GuzzleHttp\Client();

$url = 'https://faas-fra1-afec6ce7.doserverless.co/api/v1/web/fn-d162e87e-b749-4ae7-ac9c-1a295057435a/default/send-email';

$response = $client->post($url, [
    'json' => $args,
]);

$responseBody = $response->getBody()->getContents();
$responseCode = $response->getStatusCode();

echo $responseBody . PHP_EOL;
