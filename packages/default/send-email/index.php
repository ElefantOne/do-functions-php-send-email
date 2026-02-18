<?php

declare(strict_types=1);

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
 * Dumps a variable into a JSON string.
 *
 * @param mixed $value The variable to dump.
 *
 * @return string The JSON string representation of the variable, or an error message if encoding fails.
 */
function dump(mixed $value): string
{
    $data = json_encode($value);
    if (!$data) {
        return 'Failed to encode data';
    }

    return $data;
}

/**
 * Send email using SMTP server.
 *
 * @param array{
 *   smtp_server: string,
 *   smtp_port: int|string,
 *   smtp_username: string,
 *   smtp_password: string,
 *   subject: string,
 *   sender_email: string,
 *   sender_name: string,
 *   recipient_email: string,
 *   recipient_name: string,
 *   template: string,
 *   variables: string,
 *   attachments?: array<int, array{filename: string, content: string, type: string}>,
 *   attachment_urls?: array<int, array{filename: string, url: string, type: string}>
 * } $args SMTP server and email message arguments
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
        if (!array_key_exists($arg, $args) || $args[$arg] === null) {
            return wrap(['error' => "Please supply {$arg} argument."]);
        }
    }

    // Send the message
    $result = send($args);

    return wrap(['response' => $result, 'version' => 1]);
}

/**
 * Validates that templates exist.
 *
 * @param string $template Template name
 *
 * @return array|null Error array if validation fails, null if successful
 */
function validateTemplates(string $template): ?array
{
    $templateNameHTML = sprintf('%s/content.html', $template);
    $templateNameTXT = sprintf('%s/content.txt', $template);

    $pathTemplateNameHTML = TEMPLATES_DIR . '/' . $templateNameHTML;
    $pathTemplateNameTXT = TEMPLATES_DIR . '/' . $templateNameTXT;

    if (!file_exists($pathTemplateNameHTML)) {
        return ['status' => ERROR, 'result' => 'template (HTML) does not exist'];
    }
    if (!file_exists($pathTemplateNameTXT)) {
        return ['status' => ERROR, 'result' => 'template (TXT) does not exist'];
    }

    return null;
}

/**
 * Parses and validates variables from base64 encoded JSON string.
 *
 * @param string $encodedVariables Base64 encoded JSON string
 *
 * @return array{success: bool, data?: array<array-key, mixed>, error?: array{status: int, result: string}} Result with success flag and either data or error
 */
function parseVariables(string $encodedVariables): array
{
    $decoded = base64_decode($encodedVariables, true);
    if (is_bool($decoded)) {
        return ['success' => false, 'error' => ['status' => ERROR, 'result' => 'Failed to decode variables']];
    }

    /** @var mixed $decoded_json */
    $decoded_json = json_decode($decoded, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => ['status' => ERROR, 'result' => 'Failed to parse variables']];
    }

    if (!is_array($decoded_json)) {
        return ['success' => false, 'error' => ['status' => ERROR, 'result' => 'Variables must be an array']];
    }

    return ['success' => true, 'data' => $decoded_json];
}

/**
 * Renders templates with provided variables.
 *
 * @param string $template Template name
 * @param array<array-key, mixed> $variables Variables to render
 *
 * @return array{html: string, txt: string} Rendered content
 */
function renderTemplates(string $template, array $variables): array
{
    $loader = new FilesystemLoader(TEMPLATES_DIR);
    $twig = new Environment($loader, [
        'cache' => '/tmp',
    ]);

    $templateNameHTML = sprintf('%s/content.html', $template);
    $templateNameTXT = sprintf('%s/content.txt', $template);

    $html = $twig->render($templateNameHTML, $variables);
    $txt = $twig->render($templateNameTXT, $variables);

    return ['html' => $html, 'txt' => $txt];
}

/**
 * Creates and configures a mailer instance.
 *
 * @param array $args SMTP configuration arguments
 *
 * @return Mailer Configured mailer instance
 */
function createMailer(array $args): Mailer
{
    $dsn = sprintf(
        'smtp://%s:%s@%s:%s',
        $args['smtp_username'],
        $args['smtp_password'],
        $args['smtp_server'],
        $args['smtp_port'],
    );
    $transport = Transport::fromDsn($dsn);

    return new Mailer($transport);
}

/**
 * Composes an email message with the provided content.
 *
 * @param array $args Email arguments
 * @param string $html HTML content
 * @param string $txt Text content
 *
 * @return Email Configured email message
 */
function composeEmail(array $args, string $html, string $txt): Email
{
    $from = new Address($args['sender_email'], $args['sender_name']);
    $to = new Address($args['recipient_email'], $args['recipient_name']);

    return (new Email())
        ->subject($args['subject'])
        ->from($from)
        ->to($to)
        ->text($txt)
        ->html($html);
}

/**
 * Validates attachment fields.
 *
 * @param mixed $attachment Attachment data
 * @param array<int, string> $requiredFields Required field names
 *
 * @return bool True if valid, false otherwise
 */
function validateAttachmentFields(mixed $attachment, array $requiredFields): bool
{
    if (!is_array($attachment)) {
        return false;
    }

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $attachment) || $attachment[$field] === null) {
            return false;
        }
    }

    return true;
}

/**
 * Processes direct attachments.
 *
 * @param Email $message Email message to attach to
 * @param array<array-key, mixed> $args Email arguments
 */
function processDirectAttachments(Email $message, array $args): void
{
    if (!array_key_exists('attachments', $args) || $args['attachments'] === null) {
        return;
    }

    $attachments = $args['attachments'];
    if (!is_array($attachments)) {
        return;
    }

    foreach ($attachments as $attachment) {
        if (!validateAttachmentFields($attachment, ['filename', 'content', 'type'])) {
            error_log('Invalid attachment data: ' . dump($attachment));
            continue;
        }

        // At this point we know $attachment is an array with the required fields
        assert(is_array($attachment));
        $message->attach(
            (string) $attachment['content'],
            (string) $attachment['filename'],
            (string) $attachment['type']
        );
    }
}

/**
 * Processes URL-based attachments.
 *
 * @param Email $message Email message to attach to
 * @param array<array-key, mixed> $args Email arguments
 *
 * @return array{success: bool, filesStatuses?: array<int, int>, error?: array{status: int, result: string}} Result with status and file statuses
 *
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function processAttachmentUrls(Email $message, array $args): array
{
    $filesStatuses = [];

    if (!array_key_exists('attachment_urls', $args) || $args['attachment_urls'] === null) {
        return ['success' => true, 'filesStatuses' => $filesStatuses];
    }

    $attachmentUrls = $args['attachment_urls'];
    if (!is_array($attachmentUrls)) {
        return ['success' => true, 'filesStatuses' => $filesStatuses];
    }

    $client = new Client();

    foreach ($attachmentUrls as $attachment) {
        if (!validateAttachmentFields($attachment, ['filename', 'type', 'url'])) {
            error_log('Invalid attachment data: ' . dump($attachment));
            continue;
        }

        // At this point we know $attachment is an array with the required fields
        assert(is_array($attachment));

        try {
            $response = $client->get((string) $attachment['url']);
            $status = $response->getStatusCode();
            $filesStatuses[] = $status;

            if ($status !== 200) {
                error_log("Failed to download {$attachment['url']}: Status {$status}");
                continue;
            }

            $content = $response->getBody()->getContents();
            $message->attach(
                $content,
                (string) $attachment['filename'],
                (string) $attachment['type']
            );
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => ['status' => ERROR, 'result' => 'Failed to download: ' . $e->getMessage()],
            ];
        }
    }

    return ['success' => true, 'filesStatuses' => $filesStatuses];
}

/**
 * Sends an email using the provided SMTP server and message details.
 *
 * @param array{
 *   smtp_server: string,
 *   smtp_port: int|string,
 *   smtp_username: string,
 *   smtp_password: string,
 *   subject: string,
 *   sender_email: string,
 *   sender_name: string,
 *   recipient_email: string,
 *   recipient_name: string,
 *   template: string,
 *   variables: string,
 *   attachments?: array<int, array{filename: string, content: string, type: string}>,
 *   attachment_urls?: array<int, array{filename: string, url: string, type: string}>
 * } $args SMTP server and email message arguments
 *
 * @return array{status: int, result?: string, filesStatuses?: array<int, int>} Response with status and result
 */
function send(array $args): array
{
    $validationError = validateTemplates($args['template']);
    if ($validationError !== null) {
        return $validationError;
    }

    $parsedVariables = parseVariables($args['variables']);
    if (!$parsedVariables['success']) {
        /** @var array{status: int, result: string} $error */
        $error = $parsedVariables['error'];
        return $error;
    }

    try {
        /** @var array<array-key, mixed> $variablesData */
        $variablesData = $parsedVariables['data'];
        $rendered = renderTemplates($args['template'], $variablesData);
        $mailer = createMailer($args);
        $message = composeEmail($args, $rendered['html'], $rendered['txt']);

        processDirectAttachments($message, $args);

        $attachmentResult = processAttachmentUrls($message, $args);
        if (!$attachmentResult['success']) {
            /** @var array{status: int, result: string} $error */
            $error = $attachmentResult['error'];
            return $error;
        }

        $mailer->send($message);

        /** @var array<int, int> $filesStatuses */
        $filesStatuses = $attachmentResult['filesStatuses'] ?? [];
        return ['status' => OK, 'filesStatuses' => $filesStatuses];
    } catch (Throwable $e) {
        return ['status' => ERROR, 'result' => 'Failed to send: ' . $e->getMessage()];
    }
}
