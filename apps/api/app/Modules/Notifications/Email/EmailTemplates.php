<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Email;

/** Gabarits FR/EN (modèle « Accès validé » de la maquette B8). Variables {{nom}}, échappées en HTML. */
final class EmailTemplates
{
    private const T = [
        'access_granted' => [
            'fr' => ['subject' => 'Votre accès Sanara est prêt',
                'body' => "Bonjour {{responsable}},\n\nVotre pharmacie {{pharmacie}} a été validée. Activez votre compte avec le lien ci-dessous, valable {{duree_lien}}.\n\n{{lien_activation}}"],
            'en' => ['subject' => 'Your Sanara access is ready',
                'body' => "Hello {{responsable}},\n\nYour pharmacy {{pharmacie}} has been approved. Activate your account with the link below, valid for {{duree_lien}}.\n\n{{lien_activation}}"],
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
