<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/index.php';

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

    'subject' => 'Test email',

    'sender_email' => '',
    'sender_name' => 'Test Sender',

    'recipient_email' => 'egorsmkv@gmail.com',
    'recipient_name' => 'Yehor Smoliakov',

    'template' => 'hello',
    'variables' => base64_encode($data),
];

$response = main($args);

echo "Response:\n";

print_r($response);
