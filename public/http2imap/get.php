<?php

namespace http2imap;

use DateTimeImmutable;
use Exception;

include_once 'config.php';

class Controller
{
    const PARAMETER_REQUIRED = 1;
    const PARAMETER_OPTIONAL = 1;

    private $request = [
        'mailbox' => self::PARAMETER_REQUIRED,
        'user' => self::PARAMETER_REQUIRED,
        'password' => self::PARAMETER_REQUIRED,
        'subject' => self::PARAMETER_REQUIRED,
        'token' => self::PARAMETER_REQUIRED,
        'since' => self::PARAMETER_OPTIONAL,
        'before' => self::PARAMETER_OPTIONAL,
    ];
    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function init(array $formData): void
    {
        foreach ($this->request as $parameter => $parameterType) {
            $this->request[$parameter] = $this->formField($formData, $parameter);

            if (self::PARAMETER_REQUIRED === $parameterType && null === $this->request[$parameter]) {
                throw $this->exception('Required parameter "' . $parameter . '" is missing.');
            }
        }

        if ($this->token !== $this->request['token']) {
            throw $this->exception('Forbidden.');
        }

        if (null === $this->request['since']) {
            $this->request['since'] = (new DateTimeImmutable('3 days ago'))->format('j F Y');
        }

        if (null === $this->request['before']) {
            $this->request['before'] = '';
        }
    }

    public function returnResponse()
    {
        $mailbox = $this->mailbox();
        $messages = [];

        foreach ($this->messageNumbers($mailbox, $this->criteria()) as $messageNumber) {
            $rawHeaders = imap_fetchheader($mailbox, $messageNumber);
            $messages[] = [
                'headers' => imap_rfc822_parse_headers($rawHeaders),
                'raw_headers' => $rawHeaders,
                'content' => $this->messageContent($mailbox, $messageNumber),
            ];
        }

        imap_close($mailbox);

        $this->jsonResponse([
            'success' => true,
            'messages' => $messages,
        ]);
    }

    public function returnErrorResponse(Exception $exception)
    {
        $this->jsonResponse([
            'success' => false,
            'error' => $exception->getMessage(),
        ]);
    }

    private function messageContent($mailbox, $messageNumber): string
    {
        $structure = (array)imap_fetchstructure($mailbox, $messageNumber);

        if (isset($structure['parts'])) {
            $path = $this->findFirstHTMLPath($structure['parts'], []);

            if (null === $path) {
                $section = '1';
            } else {
                $section = join('.', $path);
            }
        } else {
            $section = '1';
        }

        $content = imap_fetchbody($mailbox, $messageNumber, $section);

        if (empty($content)) {
            $content = imap_fetchbody($mailbox, $messageNumber, '1.' . $section);
        }

        return $this->html2text($content);
    }

    private function html2text($htmlContent)
    {
        $content = quoted_printable_decode($htmlContent);
        $content = iconv('ISO-8859-2', 'UTF-8', $content);
        $content = str_replace('&nbsp;', " ", $content);
        $content = stripslashes($content);
        $content = html_entity_decode($content);
        $content = str_replace('</td>', "|", $content);
        $content = str_replace('<br>', "\n", $content);
        $content = strip_tags($content);

        return $content;
    }

    private function criteria(): string
    {
        $criteria = [
            'SUBJECT' => $this->request['subject'],
            'SINCE' => $this->request['since'],
        ];

        if (!empty($this->request['before'])) {
            $criteria['BEFORE'] = $this->request['before'];
        }

        return implode(' ', array_map(function ($name, $value) {
            return $name . ' "' . $value . '"';
        }, array_keys($criteria), $criteria));
    }

    private function mailbox()
    {
        $mailbox = imap_open(
            $this->request['mailbox'],
            $this->request['user'],
            $this->request['password']);

        if (false === $mailbox) {
            throw $this->exception(imap_last_error());
        }

        return $mailbox;
    }

    private function messageNumbers($mailbox, string $criteria): array
    {
        return imap_search($mailbox, $criteria);
    }

    private function jsonResponse($payload)
    {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    private function exception($error): Exception
    {
        return new Exception($error);
    }

    private function formField(array $formData, string $fieldName)
    {
        return isset($formData[$fieldName]) ? $formData[$fieldName] : null;
    }

    private function findFirstHTMLPath($parts, $parentPath = [])
    {
        if (isset($parts)) {
            foreach ($parts as $key => $part) {
                $part = (array)$part;

                if (isset($part['parts'])) {
                    $parentPath[] = $key + 1;
                    $path = $this->findFirstHTMLPath($part['parts'], $parentPath);

                    if (null !== $path) {
                        return $path;
                    }
                }

                if ($part['subtype'] === 'HTML') {
                    $parentPath[] = $key + 1;
                    return $parentPath;
                }
            }
        }

        return null;
    }
}

$controller = new Controller(AUTH_TOKEN);

try {
    $controller->init($_POST);
    $controller->returnResponse();
} catch (Exception $exception) {
    $controller->returnErrorResponse($exception);
}