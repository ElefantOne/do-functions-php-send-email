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
        if (!array_key_exists($arg, $args)) {
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
 * @return array{status: int, result: string}|null Error array if validation fails, null if successful
 */
function validate_templates(string $template): ?array
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
 * @return array{success: false, error: array{status: int, result: string}}|array{success: true, data: array<array-key, mixed>} Result with success flag and either data or error
 */
function parse_variables(string $encodedVariables): array
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
 *
 * @throws \Twig\Error\LoaderError
 * @throws \Twig\Error\RuntimeError
 * @throws \Twig\Error\SyntaxError
 */
function render_templates(string $template, array $variables): array
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
 * @param array<array-key, mixed> $args SMTP configuration arguments
 *
 * @return Mailer Configured mailer instance
 */
function create_mailer(array $args): Mailer
{
    // Type assertions for mago analyzer
    /** @var string $username */
    $username = $args['smtp_username'];
    /** @var string $password */
    $password = $args['smtp_password'];
    /** @var string $server */
    $server = $args['smtp_server'];
    /** @var int|string $port */
    $port = $args['smtp_port'];
    
    $dsn = sprintf(
        'smtp://%s:%s@%s:%s',
        $username,
        $password,
        $server,
        $port,
    );
    $transport = Transport::fromDsn($dsn);

    return new Mailer($transport);
}

/**
 * Composes an email message with the provided content.
 *
 * @param array<array-key, mixed> $args Email arguments
 * @param string $html HTML content
 * @param string $txt Text content
 *
 * @return Email Configured email message
 */
function compose_email(array $args, string $html, string $txt): Email
{
    // Type assertions for mago analyzer
    /** @var string $senderEmail */
    $senderEmail = $args['sender_email'];
    /** @var string $senderName */
    $senderName = $args['sender_name'];
    /** @var string $recipientEmail */
    $recipientEmail = $args['recipient_email'];
    /** @var string $recipientName */
    $recipientName = $args['recipient_name'];
    /** @var string $subject */
    $subject = $args['subject'];
    
    $from = new Address($senderEmail, $senderName);
    $to = new Address($recipientEmail, $recipientName);

    return (new Email())
        ->subject($subject)
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
function validate_attachment_fields(mixed $attachment, array $requiredFields): bool
{
    if (!is_array($attachment)) {
        return false;
    }

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $attachment) || $attachment[$field] === null || !is_string($attachment[$field])) {
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
function process_direct_attachments(Email $message, array $args): void
{
    if (!array_key_exists('attachments', $args) || $args['attachments'] === null) {
        return;
    }

    $attachments = $args['attachments'];
    if (!is_array($attachments)) {
        return;
    }

    foreach ($attachments as $attachment) {
        if (!validate_attachment_fields($attachment, ['filename', 'content', 'type'])) {
            error_log('Invalid attachment data: ' . dump($attachment));
            continue;
        }

        // validate_attachment_fields ensures $attachment is an array with string values
        /** @var array{filename: string, content: string, type: string} $attachment */
        $message->attach(
            $attachment['content'],
            $attachment['filename'],
            $attachment['type']
        );
    }
}

/**
 * Processes URL-based attachments.
 *
 * @param Email $message Email message to attach to
 * @param array<array-key, mixed> $args Email arguments
 *
 * @return array{success: false, error: array{status: int, result: string}}|array{success: true, filesStatuses: array<int, int>} Result with status and file statuses
 *
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function process_attachment_urls(Email $message, array $args): array
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
        if (!validate_attachment_fields($attachment, ['filename', 'type', 'url'])) {
            error_log('Invalid attachment data: ' . dump($attachment));
            continue;
        }

        // validate_attachment_fields ensures $attachment is an array with string values
        try {
            /** @var array{filename: string, type: string, url: string} $attachment */
            $response = $client->get($attachment['url']);
            $status = $response->getStatusCode();
            $filesStatuses[] = $status;

            if ($status !== 200) {
                error_log("Failed to download {$attachment['url']}: Status {$status}");
                continue;
            }

            $content = $response->getBody()->getContents();
            $message->attach(
                $content,
                $attachment['filename'],
                $attachment['type']
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
    $validationError = validate_templates($args['template']);
    if ($validationError !== null) {
        return $validationError;
    }

    $parsedVariables = parse_variables($args['variables']);
    if (!$parsedVariables['success']) {
        return $parsedVariables['error'];
    }

    try {
        $rendered = render_templates($args['template'], $parsedVariables['data']);
        $mailer = create_mailer($args);
        $message = compose_email($args, $rendered['html'], $rendered['txt']);

        process_direct_attachments($message, $args);

        $attachmentResult = process_attachment_urls($message, $args);
        if (!$attachmentResult['success']) {
            return $attachmentResult['error'];
        }

        $mailer->send($message);

        $filesStatuses = $attachmentResult['filesStatuses'];
        return ['status' => OK, 'filesStatuses' => $filesStatuses];
    } catch (Throwable $e) {
        return ['status' => ERROR, 'result' => 'Failed to send: ' . $e->getMessage()];
    }
}
