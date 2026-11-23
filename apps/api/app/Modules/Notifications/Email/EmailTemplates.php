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
    ];

    /** @param array<string, string> $vars @return array{subject: string, text: string, html: string} */
    public function render(string $template, string $locale, array $vars): array
    {
        $t = self::T[$template][$locale] ?? throw new \InvalidArgumentException("Gabarit inconnu : $template/$locale");
        preg_match_all('/\{\{(\w+)\}\}/', $t['body'], $m);
        foreach ($m[1] as $k) {
            if (!array_key_exists($k, $vars)) {
                throw new \InvalidArgumentException("Variable manquante : $k");
            }
        }
        $text = preg_replace_callback('/\{\{(\w+)\}\}/', static fn ($x) => $vars[$x[1]], $t['body']);
        $html = preg_replace_callback('/\{\{(\w+)\}\}/', static fn ($x) => htmlspecialchars($vars[$x[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $t['body']);
        return ['subject' => $t['subject'], 'text' => $text, 'html' => nl2br((string) $html)];
    }
}
