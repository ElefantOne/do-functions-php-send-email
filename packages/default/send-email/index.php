<?php

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Statuses
const OK = 1;
const ERROR = -1;

// Error reporting
error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * Just a wrapper function to return the response.
 *
 * @param array $args Arguments to be returned
 *
 * @return array Response with status and result
 */
function wrap(array $args) : array
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
function main(array $args) : array
{
    // SMTP arguments
    if (!isset($args['smtp_server'])) {
        return wrap(['error' => 'Please supply smtp_server argument.']);
    }

    if (!isset($args['smtp_port'])) {
        return wrap(['error' => 'Please supply smtp_port argument.']);
    }

    if (!isset($args['smtp_username'])) {
        return wrap(['error' => 'Please supply smtp_username argument.']);
    }

    if (!isset($args['smtp_password'])) {
        return wrap(['error' => 'Please supply smtp_password argument.']);
    }

    // Email message argument
    if (!isset($args['subject'])) {
        return wrap(['error' => 'Please supply subject argument.']);
    }

    if (!isset($args['sender_email'])) {
        return wrap(['error' => 'Please supply sender_email argument.']);
    }

    if (!isset($args['sender_name'])) {
        return wrap(['error' => 'Please supply sender_name argument.']);
    }

    if (!isset($args['recipient_email'])) {
        return wrap(['error' => 'Please supply recipient_email argument.']);
    }

    if (!isset($args['recipient_name'])) {
        return wrap(['error' => 'Please supply recipient_name argument.']);
    }

    // Template variables
    if (!isset($args['template'])) {
        return wrap(['error' => 'Please supply template argument.']);
    }

    if (!isset($args['variables'])) {
        return wrap(['error' => 'Please supply variables argument.']);
    }

    // Send the message
    $result = send($args);

    return wrap(['response' => $result, 'version' => 1]);
}

/**
 * Send email using SMTP server.
 *
 * @param array $args SMTP server and email message arguments
 *
 * @return array Response with status and result
 */
function send(array $args): array
{
    // Templates part
    $loader = new FilesystemLoader(__DIR__ . '/templates');
    $twig = new Environment($loader, [
        'cache' => '/tmp',
    ]);

    $templateNameHTML = sprintf('%s/content.html', $args['template']);
    $templateNameTXT = sprintf('%s/content.txt', $args['template']);

    if (!file_exists(__DIR__ . '/templates/' . $templateNameHTML)) {
        return ['status' => ERROR, 'result' => 'template (HTML) does not exist'];
    }
    if (!file_exists(__DIR__ . '/templates/' . $templateNameTXT)) {
        return ['status' => ERROR, 'result' => 'template (TXT) does not exist'];
    }

    // Render tempalates
    $variables = (array) json_decode(base64_decode($args['variables']));

    $html = $twig->render($templateNameHTML, $variables);
    $txt = $twig->render($templateNameTXT, $variables);

    // Email part
    $dsn = sprintf(
        'smtp://%s:%s@%s:%s',
        $args['smtp_username'],
        $args['smtp_password'],
        $args['smtp_server'],
        $args['smtp_port']
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
            if (!isset($attachment['filename'])) {
                continue;
            }

            if (!isset($attachment['content'])) {
                continue;
            }

            if (!isset($attachment['type'])) {
                continue;
            }

            $message->attach($attachment['content'], $attachment['filename'], $attachment['type']);
        }
    }

    // Send the message
    try {
        $mailer->send($message);

        return ['status' => OK];
    } catch (Exception $e) {
        return ['status' => ERROR, 'result' => 'Failed to send: ' . $e->getMessage()];
    }
}
