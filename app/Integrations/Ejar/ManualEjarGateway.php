<?php

namespace App\Integrations\Ejar;

use App\Integrations\Ejar\Events\EjarStatusRecorded;
use InvalidArgumentException;

/**
 * Manual-assisted Ejar flow (REQ-0461): the system prepares the complete contract data, the employee registers it
 * in Ejar by hand and enters the registered contract number; status follow-up and CSV reconciliation run here.
 */
class ManualEjarGateway implements EjarGateway
{
    public const STATUSES = ['draft', 'sent', 'awaiting_parties', 'registered', 'expired'];

    public function prepareRegistration(array $lease): array
    {
        return ['mode' => 'manual', 'instructions_ar' => 'سجّل العقد في منصة إيجار بهذه البيانات ثم أدخل رقم العقد الموثّق.', 'data' => $lease];
    }

    public function recordStatus(string $leaseId, string $status, ?string $ejarContractNumber = null): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown Ejar status [$status].");
        }
        if ($status === 'registered' && ($ejarContractNumber === null || trim($ejarContractNumber) === '')) {
            throw new InvalidArgumentException('A registered contract needs its Ejar contract number.');
        }
        event(new EjarStatusRecorded($leaseId, $status, $ejarContractNumber));
    }

    /** Parse an Ejar CSV export (UTF-8, optional BOM, header row) into associative rows. */
    public function importContracts(string $csvPath): array
    {
        $h = fopen($csvPath, 'r');
        if ($h === false) {
            throw new InvalidArgumentException("Cannot read [$csvPath].");
        }
        $rows = [];
        $header = null;
        while (($line = fgetcsv($h, escape: '')) !== false) {
            if ($header === null) {
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
                $header = array_map('trim', $line);
                continue;
            }
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_map('trim', $line), count($header), ''));
        }
        fclose($h);
        return $rows;
    }

    public function mode(): string
    {
        return 'manual';
    }
}
