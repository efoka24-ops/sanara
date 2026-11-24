<?php
declare(strict_types=1);

namespace Sanara\Modules\Sales;

use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;

/** Numérotation sans collision : un compteur par pharmacie et par portée (poste + année), incrémenté en transaction. */
final class Sequences
{
    public function __construct(private readonly Db $db, private readonly TenantDb $tdb)
    {
    }

    public function next(string $scope): int
    {
        return (int) $this->db->transaction(function () use ($scope): int {
            $row = $this->tdb->select('document_sequences', ['scope' => $scope], null, 1)[0] ?? null;
            if ($row === null) {
                $this->tdb->insert('document_sequences', ['id' => Ulid::generate(), 'scope' => $scope, 'last_value' => 1]);
                return 1;
            }
            $n = (int) $row['last_value'] + 1;
            // Mise à jour conditionnelle : si un autre poste est passé entre-temps, on relit et on recommence.
            while ($this->db->execute('UPDATE document_sequences SET last_value = ? WHERE id = ? AND last_value = ?', [$n, $row['id'], $row['last_value']]) === 0) {
                $row = $this->tdb->select('document_sequences', ['scope' => $scope], null, 1)[0];
                $n = (int) $row['last_value'] + 1;
            }
            return $n;
        });
    }
}
