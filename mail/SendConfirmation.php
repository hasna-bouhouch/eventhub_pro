<?php
/**
 * EventHub Pro — mail/SendConfirmation.php  [COMPLÉTÉ - Partie 2.1]
 */

require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/db.php';

class SendConfirmation
{
    public static function send(PDO $pdo, array $event, string $name, string $email, string $token): bool
    {
        // ── Charger et personnaliser le template HTML ─────────────────
        $templatePath = __DIR__ . '/templates/confirmation.html';
        if (!file_exists($templatePath)) {
            error_log('[EventHub] Template confirmation.html introuvable.');
            return false;
        }

        $html = file_get_contents($templatePath);

        // Formatage de la date en français
        $dateObj      = new DateTime($event['event_date']);
        $dateFormatee = $dateObj->format('d/m/Y à H\hi');

        // Lien de désinscription (à adapter selon votre domaine)
        $baseUrl         = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $unsubscribeLink = $baseUrl . '/events/unsubscribe.php?token=' . urlencode($token);
        $ticketLink      = $baseUrl . '/pdf/ticket.php?token=' . urlencode($token);

        // Remplacement des placeholders {{...}}
        $replacements = [
            '{{PARTICIPANT_NAME}}'  => htmlspecialchars($name),
            '{{EVENT_TITLE}}'       => htmlspecialchars($event['title']),
            '{{EVENT_DATE}}'        => $dateFormatee,
            '{{EVENT_LOCATION}}'    => htmlspecialchars($event['location']),
            '{{UNSUBSCRIBE_LINK}}'  => $unsubscribeLink,
            '{{TICKET_LINK}}'       => $ticketLink,
            '{{YEAR}}'              => date('Y'),
        ];

        foreach ($replacements as $placeholder => $value) {
            $html = str_replace($placeholder, $value, $html);
        }

        // ── Envoi avec PHPMailer ──────────────────────────────────────
        try {
            $mail = createMailer();
            $mail->addAddress($email, $name);
            $mail->addCC('walid.bouarifi@gmail.com');
            // --- AJOUT DE LA PIÈCE JOINTE PDF (PARTIE 3.1) ---
            // // On appelle la fonction de ticket.php en mode 'S' (String)
            require_once __DIR__ . '/../pdf/ticket.php'; 
            $registrationId = (int)$pdo->lastInsertId(); // Si appelé juste après l'insert
            // Note : On récupère l'ID via une requête si nécessaire, 
            // mais ici on utilise le contenu généré par la fonction


            $mail->Subject = 'Votre inscription — ' . $event['title'];
            $mail->Body    = $html;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $html));

            $mail->send();
            return true;

        } catch (\PHPMailer\PHPMailer\Exception $e) {
            logMailError($pdo, 'confirmation', $email, $e->getMessage());
            return false;
        }
    }
}
