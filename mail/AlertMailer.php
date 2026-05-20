<?php
/**
 * EventHub Pro — mail/AlertMailer.php  [COMPLÉTÉ - Partie 2.2]
 *
 * SOLUTION ANTI-DOUBLON (voir aussi register.php) :
 * La colonne events.alert_sent est utilisée comme verrou logique.
 * L'UPDATE atomique "WHERE alert_sent = 0" garantit qu'un seul
 * processus envoie l'alerte, même en cas de requêtes concurrentes.
 */

require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../pdf/report.php';

class AlertMailer
{
    public static function sendCapacityAlert(PDO $pdo, array $event): bool
    {
        // ── Générer le rapport PDF en fichier temporaire ──────────────
        $tempPdf = sys_get_temp_dir() . '/report_event_' . $event['id'] . '_' . time() . '.pdf';

        try {
            generateReportPDF($pdo, (int)$event['id'], 'F', $tempPdf);
        } catch (Exception $e) {
            error_log('[EventHub] Rapport PDF échec : ' . $e->getMessage());
            $tempPdf = null; // On envoie quand même l'email sans pièce jointe
        }

        // ── Charger et personnaliser le template HTML ─────────────────
        $templatePath = __DIR__ . '/templates/alert.html';
        $html = file_exists($templatePath)
            ? file_get_contents($templatePath)
            : self::defaultAlertHtml();

        $fillPct = round(($event['registered_count'] / $event['capacity']) * 100);
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $replacements = [
            '{{ORGANIZER_NAME}}' => htmlspecialchars($event['organizer_email']),
            '{{EVENT_TITLE}}'    => htmlspecialchars($event['title']),
            '{{FILL_PCT}}'       => $fillPct . '%',
            '{{REGISTERED}}'     => $event['registered_count'],
            '{{CAPACITY}}'       => $event['capacity'],
            '{{DASHBOARD_LINK}}' => $baseUrl . '/dashboard.php',
            '{{YEAR}}'           => date('Y'),
        ];

        foreach ($replacements as $placeholder => $value) {
            $html = str_replace($placeholder, $value, $html);
        }

        // ── Envoi avec PHPMailer ──────────────────────────────────────
        try {
            $mail = createMailer();
            $mail->addAddress($event['organizer_email']);
            $mail->Subject = '⚠️ Alerte capacité ' . $fillPct . '% — ' . $event['title'];
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);

            if ($tempPdf && file_exists($tempPdf)) {
                $mail->addAttachment($tempPdf, 'rapport_' . $event['id'] . '.pdf');
            }

            $mail->send();

            // Nettoyer le fichier temporaire
            if ($tempPdf && file_exists($tempPdf)) {
                @unlink($tempPdf);
            }

            // Logger l'envoi réussi
            $logStmt = $pdo->prepare(
                'INSERT INTO mail_logs (type, recipient, event_id, created_at)
                 VALUES (:type, :recipient, :event_id, NOW())'
            );
            $logStmt->execute([
                ':type'      => 'capacity_alert',
                ':recipient' => $event['organizer_email'],
                ':event_id'  => $event['id'],
            ]);

            return true;

        } catch (\PHPMailer\PHPMailer\Exception $e) {
            logMailError($pdo, 'capacity_alert', $event['organizer_email'], $e->getMessage());
            return false;
        }
    }

    /** Template HTML minimal de secours si alert.html est absent */
    private static function defaultAlertHtml(): string
    {
        return '<!DOCTYPE html><html><body>
            <h2>⚠️ Alerte Capacité — EventHub Pro</h2>
            <p>Bonjour,</p>
            <p>L\'événement <strong>{{EVENT_TITLE}}</strong> a atteint
               <strong>{{FILL_PCT}}</strong> de remplissage
               ({{REGISTERED}} / {{CAPACITY}} inscrits).</p>
            <p><a href="{{DASHBOARD_LINK}}">Voir le dashboard →</a></p>
            <p>EventHub Pro — {{YEAR}}</p>
        </body></html>';
    }
}
