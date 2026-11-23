<?php
declare(strict_types=1);

namespace Sanara\Common\Rbac;

/** Rôles et permissions par défaut. La matrice caissier suit l'écran H7 de la maquette. */
final class DefaultRoles
{
    public const PERMISSIONS = [
        'platform.manage' => 'Administrer la plateforme',
        'applications.review' => 'Examiner les demandes d\'accès',
        'audit.read' => 'Consulter le journal d\'audit',
        'team.manage' => 'Gérer l\'équipe et les droits',
        'settings.write' => 'Modifier les paramètres de l\'officine',
        'assistant.configure' => 'Régler l\'autonomie de l\'assistant',
        'requests.process' => 'Traiter les demandes patients',
        'products.read' => 'Consulter le catalogue',
        'products.write' => 'Modifier le catalogue',
        'stock.read' => 'Consulter le stock',
        'stock.write' => 'Mouvements et inventaire',
        'purchases.write' => 'Achats et réceptions',
        'sales.create' => 'Vendre et encaisser',
        'sales.discount' => 'Accorder une remise',
        'sales.cancel' => 'Annuler une vente',
        'pickup.validate' => 'Valider un retrait par code',
        'cash.close' => 'Clôturer la caisse',
        'patients.read' => 'Consulter les dossiers patients',
        'patients.write' => 'Modifier les dossiers patients',
        'narcotics.dispense' => 'Délivrer des produits réglementés',
        'finance.read' => 'Consulter la finance',
        'reports.read' => 'Consulter les rapports',
    ];

    private const TENANT_ALL = [
        'audit.read', 'team.manage', 'settings.write', 'assistant.configure', 'requests.process', 'products.read', 'products.write',
        'stock.read', 'stock.write', 'purchases.write', 'sales.create', 'sales.discount', 'sales.cancel', 'pickup.validate',
        'cash.close', 'patients.read', 'patients.write', 'narcotics.dispense', 'finance.read', 'reports.read',
    ];

    /** @return array<string, array{name: string, scope: string, permissions: list<string>}> */
    public static function roles(): array
    {
        return [
            'super_admin' => ['name' => 'Super admin', 'scope' => 'platform', 'permissions' => ['platform.manage', 'applications.review', 'audit.read']],
            'owner' => ['name' => 'Pharmacien titulaire', 'scope' => 'tenant', 'permissions' => self::TENANT_ALL],
            'pharmacist' => ['name' => 'Pharmacien adjoint', 'scope' => 'tenant', 'permissions' => [
                'requests.process', 'products.read', 'products.write', 'stock.read', 'stock.write', 'purchases.write', 'sales.create',
                'sales.discount', 'pickup.validate', 'patients.read', 'patients.write', 'narcotics.dispense', 'reports.read',
            ]],
            'storekeeper' => ['name' => 'Magasinier', 'scope' => 'tenant', 'permissions' => ['products.read', 'stock.read', 'stock.write', 'purchases.write']],
            'cashier' => ['name' => 'Caissier', 'scope' => 'tenant', 'permissions' => ['products.read', 'sales.create', 'sales.discount', 'pickup.validate']],
            'accountant' => ['name' => 'Comptable', 'scope' => 'tenant', 'permissions' => ['finance.read', 'reports.read', 'cash.close']],
        ];
    }
}
