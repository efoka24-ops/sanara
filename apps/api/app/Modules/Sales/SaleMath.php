<?php
declare(strict_types=1);

namespace Sanara\Modules\Sales;

/** Calculs de vente en entiers (FCFA, prix TTC). Miroir PHP de packages/shared/src/sales. */
final class SaleMath
{
    /** Arrondi à l'entier le plus proche, moitié vers le haut, sans flottant. */
    public static function ratio(int $amount, int $num, int $den): int
    {
        return intdiv($amount * $num * 2 + $den, 2 * $den);
    }

    /** @return array{gross: int, discount: int, total: int, vat: int} */
    public static function line(int $qty, int $unitPrice, int $discountBp, int $vatBp): array
    {
        $gross = $qty * $unitPrice;
        $discount = self::ratio($gross, $discountBp, 10000);
        $total = $gross - $discount;
        return ['gross' => $gross, 'discount' => $discount, 'total' => $total, 'vat' => self::ratio($total, $vatBp, 10000 + $vatBp)];
    }

    /**
     * Répartition du règlement. Espèces : montant donné, le rendu s'y calcule ; autres modes : montant exact.
     *
     * @param list<array{method: string, amount: int}> $payments montants demandés (espèces = remis par le client)
     * @return array{applied: list<array{method: string, amount: int, tendered: int}>, change: int}|null null si insuffisant ou incohérent
     */
    public static function settle(int $total, array $payments): ?array
    {
        $nonCash = 0;
        $cashGiven = 0;
        foreach ($payments as $p) {
            if ($p['amount'] <= 0) {
                return null;
            }
            $p['method'] === 'cash' ? $cashGiven += $p['amount'] : $nonCash += $p['amount'];
        }
        if ($nonCash > $total || $nonCash + $cashGiven < $total) {
            return null;
        }
        $cashDue = $total - $nonCash;
        $change = $cashGiven - $cashDue;
        if ($cashGiven === 0 && $change !== 0) {
            return null;
        }
        $applied = [];
        $cashLeft = $cashDue;
        foreach ($payments as $p) {
            if ($p['method'] === 'cash') {
                $take = min($p['amount'], $cashLeft);
                $applied[] = ['method' => 'cash', 'amount' => $take, 'tendered' => $p['amount']];
                $cashLeft -= $take;
            } else {
                $applied[] = ['method' => $p['method'], 'amount' => $p['amount'], 'tendered' => $p['amount']];
            }
        }
        return ['applied' => $applied, 'change' => $change];
    }
}
