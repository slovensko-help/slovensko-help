<?php

include_once 'config.php';

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

$messageUids = imap_search($mailbox, 'SUBJECT "' . $subject . '" SINCE "' . (new DateTimeImmutable('3 days ago'))->format('j F Y') . '"', SE_UID);

$result = [
    'success' => true,
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
                $htmlContent = imap_fetchbody($mailbox, $messageUid, '1.' . $partNumber, FT_UID | FT_PEEK);

                if (empty($htmlContent)) {
                    $htmlContent = imap_fetchbody($mailbox, $messageUid, $partNumber, FT_UID | FT_PEEK);
                }
            }
        }
    }

    $result['messages'][] = $htmlContent;
}

imap_close($mailbox);

response($result);