<?php
declare(strict_types=1);

namespace Sanara\Common\Errors;

/** Codes d'erreur stables et messages FR/EN (aucun texte en dur dans les contrôleurs). */
final class ErrorCatalog
{
    private const M = [
        'bad_request' => ['Requête invalide.', 'Invalid request.'],
        'validation_failed' => ['Certaines informations sont invalides.', 'Some information is invalid.'],
        'unauthorized' => ['Connexion requise.', 'Sign-in required.'],
        'forbidden' => ['Vous n\'avez pas le droit d\'effectuer cette action.', 'You are not allowed to do this.'],
        'not_found' => ['Ressource introuvable.', 'Resource not found.'],
        'method_not_allowed' => ['Méthode non autorisée.', 'Method not allowed.'],
        'insufficient_stock' => ['Stock insuffisant.', 'Insufficient stock.'],
        'discount_needs_approval' => ['Cette remise dépasse le plafond : validation d’un responsable requise.', 'This discount exceeds the cap: manager approval required.'],
        'prescription_required' => ['Produit réglementé : ordonnance et pharmacien délivrant requis.', 'Regulated product: prescription and dispensing pharmacist required.'],
        'cash_session_required' => ['Ouvrez votre caisse avant de vendre.', 'Open your cash register before selling.'],
        'cash_session_stale' => ['Clôturez la caisse du jour précédent avant de vendre.', 'Close the previous day cash session before selling.'],
        'cash_session_open' => ['Une caisse est déjà ouverte pour vous.', 'A cash session is already open for you.'],
        'payment_insufficient' => ['Le règlement ne couvre pas le total.', 'The payment does not cover the total.'],
        'conflict' => ['Cette opération entre en conflit avec une donnée existante.', 'This conflicts with existing data.'],
        'too_many_requests' => ['Trop de tentatives, réessayez plus tard.', 'Too many attempts, try again later.'],
        'invalid_credentials' => ['Identifiants incorrects.', 'Invalid credentials.'],
        'account_locked' => ['Compte verrouillé temporairement.', 'Account temporarily locked.'],
        'mfa_required' => ['Activez la double vérification pour continuer.', 'Enable two-factor authentication to continue.'],
        'invalid_token' => ['Session invalide ou expirée.', 'Invalid or expired session.'],
        'invalid_refresh_token' => ['Session expirée, reconnectez-vous.', 'Session expired, sign in again.'],
        'file_type_not_allowed' => ['Type de fichier non autorisé.', 'File type not allowed.'],
        'file_too_large' => ['Fichier trop volumineux.', 'File too large.'],
        'server_error' => ['Une erreur est survenue de notre côté.', 'Something went wrong on our side.'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::M);
    }

    public static function message(string $code, string $locale = 'fr'): string
    {
        return (self::M[$code] ?? self::M['server_error'])[$locale === 'en' ? 1 : 0];
    }
}
