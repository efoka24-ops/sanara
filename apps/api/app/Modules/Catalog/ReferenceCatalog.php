<?php
declare(strict_types=1);

namespace Sanara\Modules\Catalog;

use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;

/** Catalogue de référence partagé (sans prix ni tenant) et son import dans une pharmacie. */
final class ReferenceCatalog
{
    /** [nom, DCI, forme, dosage, conditionnement, famille, statut] */
    private const ROWS = [
        ['Paracétamol 500 mg', 'Paracétamol', 'Comprimé', '500 mg', 'B/20', 'Antalgiques', 'free'],
        ['Paracétamol 1 g', 'Paracétamol', 'Comprimé', '1 g', 'B/8', 'Antalgiques', 'free'],
        ['Ibuprofène 400 mg', 'Ibuprofène', 'Comprimé', '400 mg', 'B/20', 'Anti-inflammatoires', 'free'],
        ['Ibuprofène 200 mg', 'Ibuprofène', 'Comprimé', '200 mg', 'B/30', 'Anti-inflammatoires', 'free'],
        ['Diclofénac 50 mg', 'Diclofénac', 'Comprimé', '50 mg', 'B/20', 'Anti-inflammatoires', 'prescription_only'],
        ['Acide acétylsalicylique 500 mg', 'Acide acétylsalicylique', 'Comprimé', '500 mg', 'B/20', 'Antalgiques', 'free'],
        ['Amoxicilline 500 mg', 'Amoxicilline', 'Gélule', '500 mg', 'B/12', 'Antibiotiques', 'prescription_only'],
        ['Amoxicilline + acide clavulanique 1 g', 'Amoxicilline, acide clavulanique', 'Comprimé', '1 g', 'B/12', 'Antibiotiques', 'prescription_only'],
        ['Ciprofloxacine 500 mg', 'Ciprofloxacine', 'Comprimé', '500 mg', 'B/10', 'Antibiotiques', 'prescription_only'],
        ['Métronidazole 500 mg', 'Métronidazole', 'Comprimé', '500 mg', 'B/20', 'Antibiotiques', 'prescription_only'],
        ['Azithromycine 500 mg', 'Azithromycine', 'Comprimé', '500 mg', 'B/3', 'Antibiotiques', 'prescription_only'],
        ['Cotrimoxazole 480 mg', 'Sulfaméthoxazole, triméthoprime', 'Comprimé', '480 mg', 'B/20', 'Antibiotiques', 'prescription_only'],
        ['Artéméther-luméfantrine 20/120 mg', 'Artéméther, luméfantrine', 'Comprimé', '20/120 mg', 'B/24', 'Antipaludiques', 'prescription_only'],
        ['Artésunate 50 mg', 'Artésunate', 'Comprimé', '50 mg', 'B/12', 'Antipaludiques', 'prescription_only'],
        ['Quinine 300 mg', 'Quinine', 'Comprimé', '300 mg', 'B/30', 'Antipaludiques', 'prescription_only'],
        ['Sulfadoxine-pyriméthamine 500/25 mg', 'Sulfadoxine, pyriméthamine', 'Comprimé', '500/25 mg', 'B/3', 'Antipaludiques', 'prescription_only'],
        ['Metformine 850 mg', 'Metformine', 'Comprimé', '850 mg', 'B/30', 'Antidiabétiques', 'prescription_only'],
        ['Glibenclamide 5 mg', 'Glibenclamide', 'Comprimé', '5 mg', 'B/30', 'Antidiabétiques', 'prescription_only'],
        ['Amlodipine 5 mg', 'Amlodipine', 'Comprimé', '5 mg', 'B/30', 'Cardiovasculaire', 'prescription_only'],
        ['Énalapril 10 mg', 'Énalapril', 'Comprimé', '10 mg', 'B/30', 'Cardiovasculaire', 'prescription_only'],
        ['Captopril 25 mg', 'Captopril', 'Comprimé', '25 mg', 'B/30', 'Cardiovasculaire', 'prescription_only'],
        ['Furosémide 40 mg', 'Furosémide', 'Comprimé', '40 mg', 'B/20', 'Cardiovasculaire', 'prescription_only'],
        ['Hydrochlorothiazide 25 mg', 'Hydrochlorothiazide', 'Comprimé', '25 mg', 'B/30', 'Cardiovasculaire', 'prescription_only'],
        ['Oméprazole 20 mg', 'Oméprazole', 'Gélule', '20 mg', 'B/14', 'Digestif', 'prescription_only'],
        ['Salbutamol 100 µg', 'Salbutamol', 'Inhalateur', '100 µg', 'Flacon', 'Respiratoire', 'prescription_only'],
        ['Loratadine 10 mg', 'Loratadine', 'Comprimé', '10 mg', 'B/10', 'Antiallergiques', 'free'],
        ['Cétirizine 10 mg', 'Cétirizine', 'Comprimé', '10 mg', 'B/10', 'Antiallergiques', 'free'],
        ['Sérum physiologique 5 ml', 'Chlorure de sodium', 'Unidose', '5 ml', 'B/20', 'Soins', 'free'],
        ['Sels de réhydratation orale', 'Sels de réhydratation', 'Sachet', '', 'B/10', 'Digestif', 'free'],
        ['Vitamine C 500 mg', 'Acide ascorbique', 'Comprimé', '500 mg', 'Tube', 'Vitamines', 'free'],
        ['Fer + acide folique', 'Sulfate ferreux, acide folique', 'Comprimé', '', 'B/30', 'Vitamines', 'free'],
        ['Mébendazole 100 mg', 'Mébendazole', 'Comprimé', '100 mg', 'B/6', 'Antiparasitaires', 'prescription_only'],
        ['Albendazole 400 mg', 'Albendazole', 'Comprimé', '400 mg', 'B/1', 'Antiparasitaires', 'prescription_only'],
        ['Zinc 20 mg', 'Zinc', 'Comprimé', '20 mg', 'B/10', 'Digestif', 'free'],
        ['Diazépam 5 mg', 'Diazépam', 'Comprimé', '5 mg', 'B/30', 'Psychotropes', 'list_i'],
        ['Tramadol 50 mg', 'Tramadol', 'Gélule', '50 mg', 'B/20', 'Antalgiques', 'list_i'],
        ['Codéine + paracétamol', 'Codéine, paracétamol', 'Comprimé', '30/500 mg', 'B/16', 'Antalgiques', 'list_i'],
        ['Morphine 10 mg', 'Morphine', 'Ampoule', '10 mg', 'B/10', 'Stupéfiants', 'narcotic'],
    ];

    public function __construct(private readonly Db $db, private readonly ?CatalogService $catalog = null)
    {
    }

    public function seed(string $country): void
    {
        foreach (self::ROWS as [$name, $dci, $form, $strength, $pack, $family, $status]) {
            if ($this->db->one('SELECT id FROM reference_products WHERE country_code = ? AND name = ? AND form = ? AND strength = ?', [$country, $name, $form, $strength]) === null) {
                $this->db->insert('reference_products', ['id' => Ulid::generate(), 'country_code' => $country, 'name' => $name, 'dci' => $dci, 'form' => $form, 'strength' => $strength,
                    'pack' => $pack, 'family' => $family, 'regulatory_status' => $status, 'is_generic' => 1]);
            }
        }
    }

    /** Copie les références absentes du catalogue de la pharmacie, sans prix (la pharmacie renseigne les siens). */
    public function importInto(string $country, string $userId): int
    {
        $catalog = $this->catalog ?? throw new \LogicException('CatalogService requis');
        $n = 0;
        foreach ($this->db->all('SELECT * FROM reference_products WHERE country_code = ? ORDER BY name', [$country]) as $r) {
            if ($catalog->findByIdentity((string) $r['name'], $r['form'] ?: null, $r['strength'] ?: null) !== null) {
                continue;
            }
            $catalog->create([
                'name' => $r['name'], 'dci' => $r['dci'], 'form' => $r['form'] ?: null, 'strength' => $r['strength'] ?: null, 'pack' => $r['pack'] ?: null,
                'family_id' => $r['family'] ? $catalog->familyIdByName((string) $r['family']) : null, 'regulatory_status' => $r['regulatory_status'], 'is_generic' => (int) $r['is_generic'],
            ], $userId);
            $n++;
        }
        return $n;
    }
}
