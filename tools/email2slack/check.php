<?php
include 'config.php';
include 'slack-send.php';

$mailbox = imap_open(MAIL_HOST,MAIL_USER,MAIL_PASS) or die('Cannot connect: ' . imap_last_error());

$MC = imap_check($mailbox);

$result = imap_fetch_overview($mailbox,"1:{$MC->Nmsgs}",0);

foreach ($result as $overview) {
  if($overview->seen == 0) {
    $secret = md5(SALT.$overview->udate);
    $subject = htmlentities($overview->subject);
    $from = htmlentities($overview->from);
    $text = "A new email has been shared: \n *Subject:* ".$subject."\n *Sent by:* ".$from."\n\n <".HOSTED_LOCATION."view.php?uid=".$overview->uid."&amp;secret=".$secret."|View here>";
    slacksend($text);
    imap_setflag_full($mailbox, $overview->msgno, "\\Seen");
  }
}

imap_close($mailbox);

?>
