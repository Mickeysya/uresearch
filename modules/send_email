<?php
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_notification_email($to_email, $to_name, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'sandbox.smtp.mailtrap.io';
        $mail->SMTPAuth = true;
        $mail->Username = '527eb3c60a4816';
        $mail->Password = 'b697418c52d1fc';
        $mail->Port = 2525;

        $mail->setFrom('noreply@uresearch2.com', 'UResearch 2.0');
        $mail->addAddress($to_email, $to_name);

        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return "success";
    } catch (Exception $e) {
        return "Email error: " . $mail->ErrorInfo;
    }
}
?>
