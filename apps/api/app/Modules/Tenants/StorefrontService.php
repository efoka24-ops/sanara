<?php
declare(strict_types=1);

namespace Sanara\Modules\Tenants;

use DateTimeImmutable;
use DateTimeZone;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;
use Sanara\Modules\Applications\DutyCalendar;

/** US19, T156 : ce que la pharmacie montre au public (adresse, services, assurances) et ses gardes. */
final class StorefrontService
{
    public const SERVICES = ['vitals', 'malaria_test', 'parapharmacy', 'medical_equipment', 'mobile_money'];
    private const PLACES = ['region', 'department', 'city', 'district'];

    public function __construct(private readonly Db $db, private readonly TenantDb $tdb, private readonly TenantContext $ctx, private readonly Clock $clock)
    {
    }

    /** @return array{profile: array<string, mixed>, duties: list<array<string, string>>, available_services: list<string>, visible: bool} */
    public function get(): array
    {
        $tenant = $this->tenant();
        $saved = $this->saved();
        // Tant que la vitrine n'est pas enregistrée, les lieux viennent de la demande d'accès acceptée.
        $source = $saved ?? $this->db->one('SELECT * FROM pharmacy_applications WHERE tenant_id = ?', [$this->ctx->require()]) ?? [];
        $latitude = $source['latitude'] ?? $tenant['latitude'];
        $longitude = $source['longitude'] ?? $tenant['longitude'];

        $profile = [];
        foreach (self::PLACES as $k) {
            $profile[$k] = (string) ($source[$k] ?? '');
        }
        $profile += [
            'address' => (string) $tenant['address'], 'phone' => (string) $tenant['phone'],
            'email' => (string) ($saved['public_email'] ?? ''),
            'latitude' => $latitude === null ? null : (float) $latitude,
            'longitude' => $longitude === null ? null : (float) $longitude,
            'services' => json_decode((string) ($saved['services'] ?? '[]'), true),
            'insurances' => json_decode((string) ($saved['insurances'] ?? '[]'), true),
        ];

        $zone = (string) $tenant['timezone'];
        $duties = [];
        foreach ($this->upcoming() as $d) {
            $duties[] = ['id' => (string) $d['id'], 'starts' => DutyCalendar::local((string) $d['starts_at'], $zone), 'ends' => DutyCalendar::local((string) $d['ends_at'], $zone)];
        }
        return ['profile' => $profile, 'duties' => $duties, 'available_services' => self::SERVICES, 'visible' => (int) $tenant['public_visible'] === 1];
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $errors = [];
        $text = static function (string $key, int $max, bool $required = false) use ($input, &$errors): string {
            $v = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if (mb_strlen($v) > $max) {
                $errors[$key] = 'too_long';
            } elseif ($required && $v === '') {
                $errors[$key] = 'required';
            }
            return $v;
        };
        $places = [];
        foreach (self::PLACES as $k) {
            $places[$k] = $text($k, 80, $k === 'region' || $k === 'city');
        }
        $address = $text('address', 255);
        $phone = $text('phone', 32);
        $email = $text('email', 190);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'email';
        }

        $point = [];
        foreach (['latitude' => 90, 'longitude' => 180] as $k => $max) {
            $v = $input[$k] ?? null;
            if ($v === null || $v === '') {
                $point[$k] = null;
            } elseif (!is_numeric($v) || abs((float) $v) > $max) {
                $errors[$k] = 'position';
            } else {
                $point[$k] = round((float) $v, 6);
            }
        }
        if (count($point) === 2 && ($point['latitude'] === null) !== ($point['longitude'] === null)) {
            $errors['longitude'] = 'position';
        }

        $services = array_values(array_unique(array_filter((array) ($input['services'] ?? []), 'is_string')));
        if (array_diff($services, self::SERVICES) !== []) {
            $errors['services'] = 'unknown';
        }
        $insurances = array_values(array_unique(array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', (array) ($input['insurances'] ?? [])), static fn (string $v) => $v !== '')));
        if (count($insurances) > 30 || array_filter($insurances, static fn (string $v) => mb_strlen($v) > 80) !== []) {
            $errors['insurances'] = 'too_long';
        }
        if ($errors !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $errors]);
        }

        $now = $this->clock->stamp();
        $row = $places + $point + [
            'public_email' => $email === '' ? null : $email,
            'services' => json_encode($services), 'insurances' => json_encode($insurances, JSON_UNESCAPED_UNICODE), 'updated_at' => $now,
        ];
        $this->db->transaction(function () use ($row, $address, $phone, $now): void {
            if ($this->saved() === null) {
                $this->tdb->insert('storefront_profiles', ['id' => Ulid::generate()] + $row);
            } else {
                $this->tdb->update('storefront_profiles', $row, []);
            }
            $this->db->execute('UPDATE tenants SET address = ?, phone = ?, updated_at = ? WHERE id = ?', [$address, $phone, $now, $this->ctx->require()]);
        });
    }

    /**
     * Ajoute une garde saisie à l'heure locale ; une fin avant le début désigne le lendemain.
     *
     * @param array<string, mixed> $input date, from, to
     */
    public function addDuty(array $input): string
    {
        $date = (string) ($input['date'] ?? '');
        $from = (string) ($input['from'] ?? '');
        $to = (string) ($input['to'] ?? '');
        $zone = new DateTimeZone((string) $this->tenant()['timezone']);
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw $this->invalid('date', 'date');
        }
        $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (!preg_match($time, $from) || !preg_match($time, $to)) {
            throw $this->invalid('from', 'time');
        }
        $starts = $day->modify($from);
        $ends = $day->modify($to);
        if ($ends <= $starts) {
            $ends = $ends->modify('+1 day');
        }
        $now = $this->clock->now();
        if ($ends <= $now) {
            throw $this->invalid('date', 'past');
        }
        if ($starts > $now->modify('+1 year')) {
            throw $this->invalid('date', 'too_far');
        }
        $startsAt = DutyCalendar::utc($starts);
        $endsAt = DutyCalendar::utc($ends);
        foreach ($this->upcoming() as $d) {
            if ($d['starts_at'] < $endsAt && $d['ends_at'] > $startsAt) {
                throw $this->invalid('date', 'overlap');
            }
        }
        $id = Ulid::generate();
        $this->tdb->insert('duty_periods', ['id' => $id, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'created_at' => $this->clock->stamp()]);
        return $id;
    }

    public function deleteDuty(string $id): void
    {
        if ($this->tdb->delete('duty_periods', ['id' => $id]) === 0) {
            throw new ApiException('not_found', 404);
        }
    }

    /** @return array<string, mixed>|null */
    private function saved(): ?array
    {
        return $this->tdb->select('storefront_profiles', [], null, 1)[0] ?? null;
    }

    /** Gardes en cours ou à venir. @return list<array<string, mixed>> */
    private function upcoming(): array
    {
        $now = $this->clock->stamp();
        return array_values(array_filter($this->tdb->select('duty_periods', [], 'starts_at'), static fn (array $d) => $d['ends_at'] > $now));
    }

    /** @return array<string, mixed> */
    private function tenant(): array
    {
        return $this->db->one(
            'SELECT t.address, t.phone, t.latitude, t.longitude, t.public_visible, c.timezone FROM tenants t JOIN countries c ON c.code = t.country_code WHERE t.id = ?',
            [$this->ctx->require()],
        ) ?? throw new ApiException('not_found', 404);
    }

    private function invalid(string $field, string $reason): ApiException
    {
        return new ApiException('validation_failed', 422, ['fields' => [$field => $reason]]);
    }
}
