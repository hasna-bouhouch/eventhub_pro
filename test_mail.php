<?php
require_once 'lib/PHPMailer/src/PHPMailer.php';
require_once 'lib/PHPMailer/src/SMTP.php';
require_once 'lib/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'hasnabouhouch03@gmail.com';
    $mail->Password   = 'sess brpb eqyy oivi'; // ← mettez le nouveau
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom('hasnabouhouch03@gmail.com', 'EventHub Pro');
    $mail->addAddress('azg.kaoutar@gmail.com', 'Professeur');
    $mail->isHTML(true);
    $mail->Subject = 'Test EventHub Pro';
    $mail->Body    = '<h2>Test reussi !</h2><p>Application EventHub fonctionne.</p>';

    $mail->send();
    echo "✅ Email envoye avec succes !";
} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage();
}