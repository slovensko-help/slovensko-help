<?php

include_once 'config.php';
include_once '../post.php';

$callerNumber = isset($_POST['caller_number']) ? $_POST['caller_number'] : 'unknown';
$cloudtalkNumber = isset($_POST['cloudtalk_number']) ? $_POST['cloudtalk_number'] : 'unknown';

post(
    SLACK_URL,
    [
        'text' => '[' . date('Y-m-d H:i:s') . '] Nový hovor z čísla ' . $callerNumber . ' na číslo ' . $cloudtalkNumber . '.'
    ]
);

post(
    'https://breakfast.eea.sk/jira/rest/servicedeskapi/request',
    [
        "serviceDeskId" => "15",
        "requestTypeId" => "163",
        "requestFieldValues" => [
            "summary" => "Request od $callerNumber",
            "description" => "Telefonát z čísla $callerNumber",
            "customfield_10300" => "textove pole Meno",
            "customfield_10301" => "textove pole Priezvisko",
            "customfield_10418" => "textove pole Adresa",
            "customfield_10305" => "textove pole email",
            "customfield_10303" => "$callerNumber"
        ],
    ],
    JIRA_LOGIN,
    JIRA_PASSWORD
);