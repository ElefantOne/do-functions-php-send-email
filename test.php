<?php

$data = json_encode([
    'name' => 'Yehor',
]);
if (!$data) {
    exit('Please supply data argument.');
}

$args = [
    'smtp_server' => 'smtp.mailgun.org',
    'smtp_port' => 587,
    'smtp_username' => '--username--',
    'smtp_password' => '--password--',

    'subject' => 'Test email',

    'sender_email' => '--email--',
    'sender_name' => 'Test Sender',

    'recipient_email' => 'egorsmkv@gmail.com',
    'recipient_name' => 'Yehor Smoliakov',

    'template' => 'hello',
    'variables' => base64_encode($data),
];

// echo json_encode($args, JSON_PRETTY_PRINT) . PHP_EOL;

$query = http_build_query($args);
$url = 'curl -vvvv "https://faas-fra1-afec6ce7.doserverless.co/api/v1/web/fn-d162e87e-b749-4ae7-ac9c-1a295057435a/default/send-email?' . $query . '"';

echo $url . PHP_EOL;
