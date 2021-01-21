<?php

include_once 'config.php';

class mail {
    /**
     * If you change one of these, please check the other for fixes as well
     *
     * @const Pattern to match RFC 2047 charset encodings in mail headers
     */
    const rfc2047header = '/=\?([^ ?]+)\?([BQbq])\?([^ ?]+)\?=/';

    const rfc2047header_spaces = '/(=\?[^ ?]+\?[BQbq]\?[^ ?]+\?=)\s+(=\?[^ ?]+\?[BQbq]\?[^ ?]+\?=)/';

    /**
     * http://www.rfc-archive.org/getrfc.php?rfc=2047
     *
     * =?<charset>?<encoding>?<data>?=
     *
     * @param string $header
     */
    public static function is_encoded_header($header) {
        // e.g. =?utf-8?q?Re=3a=20Support=3a=204D09EE9A=20=2d=20Re=3a=20Support=3a=204D078032=20=2d=20Wordpress=20Plugin?=
        // e.g. =?utf-8?q?Wordpress=20Plugin?=
        return preg_match(self::rfc2047header, $header) !== 0;
    }

    public static function header_charsets($header) {
        $matches = null;
        if (!preg_match_all(self::rfc2047header, $header, $matches, PREG_PATTERN_ORDER)) {
            return array();
        }
        return array_map('strtoupper', $matches[1]);
    }

    public static function decode_header($header) {
        $matches = null;

        /* Repair instances where two encodings are together and separated by a space (strip the spaces) */
        $header = preg_replace(self::rfc2047header_spaces, "$1$2", $header);

        /* Now see if any encodings exist and match them */
        if (!preg_match_all(self::rfc2047header, $header, $matches, PREG_SET_ORDER)) {
            return $header;
        }
        foreach ($matches as $header_match) {
            list($match, $charset, $encoding, $data) = $header_match;
            $encoding = strtoupper($encoding);
            switch ($encoding) {
                case 'B':
                    $data = base64_decode($data);
                    break;
                case 'Q':
                    $data = quoted_printable_decode(str_replace("_", " ", $data));
                    break;
                default:
                    throw new Exception("preg_match_all is busted: didn't find B or Q in encoding $header");
            }
            // This part needs to handle every charset
            switch (strtoupper($charset)) {
                case "UTF-8":
                    break;
                case 'ISO-8859-2':
                    $data = iconv('ISO-8859-2', 'UTF-8', $data);
                    break;
                default:
                    /* Here's where you should handle other character sets! */
                    throw new Exception("Unknown charset in header - time to write some code.");
            }
            $header = str_replace($match, $data, $header);
        }
        return $header;
    }
}

function response($payload) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function errorResponse($error) {
    response([
        'success' => false,
        'error' => $error,
    ]);
}

$mailbox = isset($_POST['mailbox']) ? $_POST['mailbox'] : null;
$user = isset($_POST['user']) ? $_POST['user'] : null;
$password = isset($_POST['password']) ? $_POST['password'] : null;
$subject = isset($_POST['subject']) ? $_POST['subject'] : null;
$token = isset($_POST['token']) ? $_POST['token'] : null;
$since = isset($_POST['since']) ? $_POST['since'] : (new DateTimeImmutable('3 days ago'))->format('j F Y');
$before = isset($_POST['before']) ? (' BEFORE "' . $_POST['before'] . '"') : '';


if (null === $mailbox || null === $user || null === $password || null === $token || null === $subject) {
    errorResponse('Wrong parameter values.');
}

if ($token !== AUTH_TOKEN) {
    errorResponse('Wrong auth token.');
}

$mailbox = imap_open($mailbox,$user,$password);

if (false === $mailbox) {
    errorResponse(imap_last_error());
}

$MC = imap_check($mailbox);

$messageUids = imap_search($mailbox, 'SUBJECT "' . $subject . '" SINCE "' . $since . '"' . $before, SE_UID);

$result = [
    'success' => true,
    'since' => $since,
    'before' => $before,
    'messages' => [],
];

foreach ($messageUids as $messageUid) {
    $structure = (array) imap_fetchstructure($mailbox, $messageUid, FT_UID);
    $htmlContent = '';

    if (isset($structure['parts'])) {
        foreach ($structure['parts'] as $key => $part) {
            $part = (array) $part;

            $partNumber = ($key + 1);

            if ($part['subtype'] === 'HTML') {
                $htmlContent = imap_fetchbody($mailbox, $messageUid, '1.' . $partNumber, FT_UID);

                if (empty($htmlContent)) {
                    $htmlContent = imap_fetchbody($mailbox, $messageUid, $partNumber, FT_UID);
                }
            }
        }
    }

    $htmlContent = quoted_printable_decode($htmlContent);
    $htmlContent = iconv('ISO-8859-2', 'UTF-8', $htmlContent);
    $htmlContent = str_replace('&nbsp;', " ", $htmlContent);
    $htmlContent = stripslashes($htmlContent);
    $htmlContent = html_entity_decode($htmlContent);
    $htmlContent = str_replace('</td>', "|", $htmlContent);
    $htmlContent = str_replace('<br>', "\n", $htmlContent);
    $htmlContent = strip_tags($htmlContent);

    $header = imap_headerinfo($mailbox, imap_msgno($mailbox, $messageUid));

    $message = [
        'date' => null,
        'from' => null,
        'subject' => null,
        'content' => $htmlContent,
    ];

    if (false !== $header) {
        $header = (array) $header;

        if (isset($header['date'])) {
            $message['date'] = $header['date'];
        }
        if (isset($header['from'])) {
            $message['from'] = $header['fromaddress'];
        }
        if (isset($header['subject'])) {
            $message['subject'] = mail::decode_header($header['subject']);
        }
    }

    $result['messages'][] = $message;
}

$result['messages'] = array_reverse($result['messages']);

imap_close($mailbox);

response($result);