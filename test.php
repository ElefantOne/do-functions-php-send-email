<?php

require __DIR__ . '/packages/default/send-email/vendor/autoload.php';

// Constants
const OK = 1;

const HTTP_OK = 200;

const ERROR = -1;

$data = json_encode([
    'name' => 'Yehor',
]);
if (!$data) {
    exit('Please supply data argument.');
}

$args = [
    'smtp_server' => 'smtp.mailgun.org',
    'smtp_port' => 587,
    'smtp_username' => '',
    'smtp_password' => '',
    'subject' => 'PDF file test',
    'sender_email' => 'author@example.com',
    'sender_name' => 'Test Sender',
    'recipient_email' => 'egorsmkv@gmail.com',
    'recipient_name' => 'Yehor Smoliakov',
    'template' => 'hello',
    'variables' => base64_encode($data),
    'attachment_urls' => [
        [
            'filename' => 'test_1.pdf',
            'url' => 'https://raw.githubusercontent.com/ElefantOne/do-functions-php-send-email/refs/heads/main/packages/default/send-email/test.pdf',
            'type' => 'application/pdf',
        ],
        [
            'filename' => 'test_2.pdf',
            'url' => 'https://raw.githubusercontent.com/ElefantOne/do-functions-php-send-email/refs/heads/main/packages/default/send-email/test.pdf',
            'type' => 'application/pdf',
        ],
    ],
];

$client = new \GuzzleHttp\Client();

$url = 'https://faas-fra1-afec6ce7.doserverless.co/api/v1/web/fn-d162e87e-b749-4ae7-ac9c-1a295057435a/default/send-email';

$response = $client->post($url, [
    // Config
    'connect_timeout' => 20,
    'read_timeout' => 20,
    'timeout' => 30,
    // The data
    'json' => $args,
]);

$responseBody = $response->getBody()->getContents();
$responseCode = $response->getStatusCode();

if (HTTP_OK !== $responseCode) {
    echo "Something went wrong!\n";

    exit(1);
}

echo "HTTP call was successful!\n";

/**
 * @var array $parsedData
 */
$parsedData = json_decode($responseBody, true);

/**
 * @var array $response
 */
$response = $parsedData['response'];

switch ($response['status']) {
    case OK:
        echo "Notification sent successfully!\n";
        break;
    case ERROR:
        echo "Notification failed to send! Use parsedData variable to find out more.\n";
        break;
    default:
        echo "Something went wrong! Use parsedData variable to find out more.\n";
        break;
}
