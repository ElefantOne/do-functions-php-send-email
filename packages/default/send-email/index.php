<?php

use GuzzleHttp\Client;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Statuses
const OK = 1;

const ERROR = -1;

// Templates path
const TEMPLATES_DIR = __DIR__ . '/templates';

// Error reporting
error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * Wraps the response in a consistent format.
 *
 * @param array $args Arguments to be returned
 *
 * @return array Response with status and result
 */
function wrap(array $args): array
{
    return ['body' => $args];
}

/**
 * Send email using SMTP server.
 *
 * @param array $args SMTP server and email message arguments
 *
 * @return array Response with status and result
 */
function main(array $args): array
{
    $requiredArgs = [
        'smtp_server',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'subject',
        'sender_email',
        'sender_name',
        'recipient_email',
        'recipient_name',
        'template',
        'variables',
    ];

    foreach ($requiredArgs as $arg) {
        if (!isset($args[$arg])) {
            return wrap(['error' => "Please supply {$arg} argument."]);
        }
    }

    // Send the message
    $result = send($args);

    return wrap(['response' => $result, 'version' => 1]);
}

/**
 * Sends an email using the provided SMTP server and message details.
 *
 * @param array $args SMTP server and email message arguments
 *
 * @return array Response with status and result
 */
function send(array $args): array
{
    // Templates part
    $loader = new FilesystemLoader(TEMPLATES_DIR);
    $twig = new Environment($loader, [
        'cache' => '/tmp',
    ]);

    $templateNameHTML = sprintf('%s/content.html', $args['template']);
    $templateNameTXT = sprintf('%s/content.txt', $args['template']);

    $pathTemplateNameHTML = TEMPLATES_DIR . '/' . $templateNameHTML;
    $pathTemplateNameTXT = TEMPLATES_DIR . '/' . $templateNameTXT;

    if (!file_exists($pathTemplateNameHTML)) {
        return ['status' => ERROR, 'result' => 'template (HTML) does not exist'];
    }
    if (!file_exists($pathTemplateNameTXT)) {
        return ['status' => ERROR, 'result' => 'template (TXT) does not exist'];
    }

    // Render tempalates
    $decoded = base64_decode($args['variables'], true);
    if ($decoded === false) {
        return ['status' => ERROR, 'result' => 'Failed to decode variables'];
    }

    $variables = json_decode($decoded, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['status' => ERROR, 'result' => 'Failed to parse variables'];
    }

    // $variables must be an array
    if (!is_array($variables)) {
        return ['status' => ERROR, 'result' => 'Variables must be an array'];
    }

    $html = $twig->render($templateNameHTML, $variables);
    $txt = $twig->render($templateNameTXT, $variables);

    // Email part
    $dsn = sprintf(
        'smtp://%s:%s@%s:%s',
        $args['smtp_username'],
        $args['smtp_password'],
        $args['smtp_server'],
        $args['smtp_port'],
    );
    $transport = Transport::fromDsn($dsn);
    $mailer = new Mailer($transport);

    $from = new Address($args['sender_email'], $args['sender_name']);
    $to = new Address($args['recipient_email'], $args['recipient_name']);

    // Compose the message
    $message = (new Email())
        ->subject($args['subject'])
        ->from($from)
        ->to($to)
        ->text($txt)
        ->html($html);

    // Attachments part
    if (isset($args['attachments'])) {
        foreach ($args['attachments'] as $attachment) {
            if (!isset($attachment['filename'], $attachment['content'], $attachment['type'])) {
                error_log('Invalid attachment data: ' . json_encode($attachment));
                continue;
            }

            $message->attach($attachment['content'], $attachment['filename'], $attachment['type']);
        }
    }

    $filesStatuses = [];

    if (isset($args['attachment_urls'])) {
        // Create a Guzzle client
        $client = new Client();

        foreach ($args['attachment_urls'] as $attachment) {
            if (!isset($attachment['filename'], $attachment['type'], $attachment['url'])) {
                error_log('Invalid attachment data: ' . json_encode($attachment));
                continue;
            }

            // Download using Guzzle
            try {
                $response = $client->get($attachment['url']);

                $status = $response->getStatusCode();
                $filesStatuses[] = $status;

                if ($status !== 200) {
                    error_log("Failed to download {$attachment['url']}: Status {$status}");

                    continue;
                }

                $content = $response->getBody()->getContents();
            } catch (Exception $e) {
                return ['status' => ERROR, 'result' => 'Failed to download: ' . $e->getMessage()];
            }

            $message->attach($content, $attachment['filename'], $attachment['type']);
        }
    }

    // Send the email
    try {
        $mailer->send($message);

        return ['status' => OK, 'filesStatuses' => $filesStatuses];
    } catch (Exception $e) {
        return ['status' => ERROR, 'result' => 'Failed to send: ' . $e->getMessage()];
    }
}
