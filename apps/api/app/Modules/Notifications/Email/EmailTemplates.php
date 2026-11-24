<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Email;

/** Gabarits FR/EN (maquette B8). Variables {{nom}}, échappées en HTML. */
final class EmailTemplates
{
    private const T = [
        'access_granted' => [
            'fr' => ['subject' => 'Votre accès Sanara est prêt',
                'body' => "Bonjour {{responsable}},\n\nVotre pharmacie {{pharmacie}} a été validée. Activez votre compte avec le lien ci-dessous, valable {{duree_lien}}.\n\n{{lien_activation}}"],
            'en' => ['subject' => 'Your Sanara access is ready',
                'body' => "Hello {{responsable}},\n\nYour pharmacy {{pharmacie}} has been approved. Activate your account with the link below, valid for {{duree_lien}}.\n\n{{lien_activation}}"],
        ],
        'application_received' => [
            'fr' => ['subject' => "Votre demande d'accès Sanara est bien reçue",
                'body' => "Bonjour {{responsable}},\n\nNous avons reçu votre demande {{numero}}. Notre équipe vérifie vos documents et vous répond par e-mail.\n\nCode de suivi : {{code_suivi}}"],
            'en' => ['subject' => 'We received your Sanara access request',
                'body' => "Hello {{responsable}},\n\nWe received your request {{numero}}. Our team is checking your documents and will reply by email.\n\nTracking code: {{code_suivi}}"],
        ],
        'access_rejected' => [
            'fr' => ['subject' => "Votre demande d'accès Sanara",
                'body' => "Bonjour {{responsable}},\n\nVotre demande n'a pas pu être acceptée.\n\nMotif : {{motif}}"],
            'en' => ['subject' => 'Your Sanara access request',
                'body' => "Hello {{responsable}},\n\nYour request could not be accepted.\n\nReason: {{motif}}"],
        ],
        'info_requested' => [
            'fr' => ['subject' => 'Un document est à renvoyer',
                'body' => "Bonjour {{responsable}},\n\n{{message}}\n\nRenvoyez le document depuis votre lien de suivi. Code de suivi : {{code_suivi}}"],
            'en' => ['subject' => 'A document needs to be resent',
                'body' => "Hello {{responsable}},\n\n{{message}}\n\nResend the document from your tracking page. Tracking code: {{code_suivi}}"],
        ],
        'activation_resend' => [
            'fr' => ['subject' => 'Votre nouveau lien d\'activation Sanara',
                'body' => "Bonjour,\n\nVoici un nouveau lien pour activer votre compte, valable {{duree_lien}} :\n\n{{lien_activation}}\n\nLes liens précédents ne fonctionnent plus."],
            'en' => ['subject' => 'Your new Sanara activation link',
                'body' => "Hello,\n\nHere is a new link to activate your account, valid for {{duree_lien}}:\n\n{{lien_activation}}\n\nPrevious links no longer work."],
        ],
        'password_reset' => [
            'fr' => ['subject' => 'Réinitialisation de votre mot de passe Sanara',
                'body' => "Bonjour,\n\nPour choisir un nouveau mot de passe (lien valable 1 heure) :\n\n{{lien}}\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message."],
            'en' => ['subject' => 'Reset your Sanara password',
                'body' => "Hello,\n\nTo choose a new password (link valid for 1 hour):\n\n{{lien}}\n\nIf you did not request this, ignore this message."],
        ],
        'account_locked' => [
            'fr' => ['subject' => 'Compte Sanara verrouillé temporairement',
                'body' => "Bonjour,\n\nLe compte {{compte}} a été verrouillé 15 minutes après plusieurs échecs de connexion. S'il ne s'agit pas de vous ou d'un membre de l'équipe, changez le mot de passe."],
            'en' => ['subject' => 'Sanara account temporarily locked',
                'body' => "Hello,\n\nThe account {{compte}} was locked for 15 minutes after several failed sign-ins. If this was not you or a team member, change the password."],
        ],
        'member_invited' => [
            'fr' => ['subject' => 'Vous êtes invité sur Sanara',
                'body' => "Bonjour {{nom}},\n\nVous avez été invité(e) à rejoindre votre pharmacie sur Sanara avec le rôle : {{role}}.\n\nActivez votre compte : {{lien_activation}}"],
            'en' => ['subject' => 'You have been invited to Sanara',
                'body' => "Hello {{nom}},\n\nYou have been invited to join your pharmacy on Sanara as: {{role}}.\n\nActivate your account: {{lien_activation}}"],
        ],
    ];

    public function __construct(private readonly ?\Sanara\Common\Persistence\Db $db = null)
    {
    }

    /** @return list<string> codes de gabarits */
    public static function codes(): array
    {
        return array_keys(self::T);
    }

    /** @return array{subject: string, body: string} gabarit d'origine */
    public static function defaults(string $code, string $locale): array
    {
        return self::T[$code][$locale] ?? throw new \InvalidArgumentException("Gabarit inconnu : $code/$locale");
    }

    /** @return list<string> variables {{nom}} d'un texte */
    public static function variables(string $text): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $text, $m);
        return array_values(array_unique($m[1]));
    }

    /** Gabarit en vigueur : modification enregistrée par le super admin, sinon texte d'origine. @return array{subject: string, body: string} */
    public function effective(string $code, string $locale): array
    {
        $o = $this->db?->one('SELECT subject, body FROM message_templates WHERE code = ? AND locale = ?', [$code, $locale]);
        return $o !== null ? ['subject' => (string) $o['subject'], 'body' => (string) $o['body']] : self::defaults($code, $locale);
    }

    /** @param array<string, string> $vars @return array{subject: string, text: string, html: string} */
    public function render(string $template, string $locale, array $vars): array
    {
        $t = $this->effective($template, $locale);
        foreach (self::variables($t['body']) as $k) {
            if (!array_key_exists($k, $vars)) {
                throw new \InvalidArgumentException("Variable manquante : $k");
            }
        }
        $text = preg_replace_callback('/\{\{(\w+)\}\}/', static fn ($x) => $vars[$x[1]], $t['body']);
        $html = preg_replace_callback('/\{\{(\w+)\}\}/', static fn ($x) => htmlspecialchars($vars[$x[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $t['body']);
        $subject = preg_replace_callback('/\{\{(\w+)\}\}/', static fn ($x) => $vars[$x[1]] ?? '', $t['subject']);
        return ['subject' => (string) $subject, 'text' => (string) $text, 'html' => nl2br((string) $html)];
    }
}
