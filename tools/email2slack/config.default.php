<?php

/* Replace the following constants */

define('MAIL_HOST', '{imap.googlemail.com:993/imap/ssl}INBOX'); // Change if not using Gmail
define('MAIL_USER', 'user@somemail.com'); // IMAP username/login, usually an email
define('MAIL_PASS', 'mypassword');
define('SLACK_URL', 'https://hooks.slack.com/someURL'); // Paste your slack hook URL here
define('SLACK_USERNAME', 'email2slack'); // Set a username for the Slack message
define('SLACK_CHANNEL', '#general'); // Define the channel this will post in. MUST include #hashtag
define('SLACK_ICON_EMOJI', ':email:'); // Set an icon for the Slack message

?>
