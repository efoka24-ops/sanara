<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Email;

use PHPMailer\PHPMailer\PHPMailer;

/** Envoi SMTP authentifié (boîte noreply@trugroup.cm). Identifiants lus dans l'environnement, jamais dans le dépôt. */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromName = 'Sanara',
    ) {
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = $this->host;
        $m->Port = $this->port;
        $m->SMTPAuth = true;
        $m->Username = $this->username;
        $m->Password = $this->password;
        $m->SMTPSecure = $this->encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $m->CharSet = 'UTF-8';
        $m->Timeout = 10;
        $m->setFrom($this->username, $this->fromName);
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;
        $m->send();
    }

    /** Vérifie la connexion et l'authentification sans envoyer de message. */
    public function check(): bool
    {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = $this->host;
        $m->Port = $this->port;
        $m->SMTPAuth = true;
        $m->Username = $this->username;
        $m->Password = $this->password;
        $m->SMTPSecure = $this->encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $m->Timeout = 10;
        $ok = $m->smtpConnect([ 'ssl' => ['verify_peer' => true]]);
        $m->smtpClose();
        return $ok;
    }
}
