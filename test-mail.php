<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Require Composer's autoloader
require 'vendor/autoload.php';

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();                                            // Send using SMTP
    $mail->Host       = '100.82.205.40';                     // Your server IP or domain (e.g., dev.uratex.com.ph)
    $mail->SMTPAuth   = true;                                   // Enable SMTP authentication
    $mail->Username   = 'root';                   // Your server system user / SASL username
    $mail->Password   = 'UX@Shopify!@#68';                   // Your user password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // Enable TLS encryption (STARTTLS on port 587)
    $mail->Port       = 587;                                    // TCP port to connect to

    // Recipients
    $mail->setFrom('sender@dev.uratex.com.ph', 'Dev Team');
    $mail->addAddress('jenor.ricafort@uratex.com.ph', 'Test Recipient'); // Add a recipient

    // Content
    $mail->isHTML(true);                                        // Set email format to HTML
    $mail->Subject = 'Postfix SMTP API Test Mail (PHP)';
    $mail->Body    = '<b>Hello!</b> This is a test email sent from our internal Postfix SMTP server via PHP.';
    $mail->AltBody = 'Hello! This is a test email sent from our internal Postfix SMTP server via PHP.';

    $mail->send();
    echo 'Message has been successfully sent!';
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
