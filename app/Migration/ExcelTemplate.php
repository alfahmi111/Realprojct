<?php

namespace App\Migration;

use ZipArchive;

/**
 * The quick-migration Excel template (owner request 2026-11-24): fixed columns, one contract per row, so the platform
 * reads the file itself — no AI reader needed and no guessing. Writes a real .xlsx with ZipArchive (no extra package)
 * and reads .xlsx or UTF-8 .csv in the same column order. Arabic values (فرد، سكني، ربع سنوي…) map to the stored codes.
 */
final class ExcelTemplate
{
    /** field => [Arabic header, example] — the order is the contract. */
    public const COLUMNS = [
        'ejar_contract_number' => ['رقم عقد إيجار', '10291961792'],
        'tenant_name' => ['اسم المستأجر', 'خالد أحمد الحربي'],
        'tenant_kind' => ['نوع المستأجر (فرد / منشأة)', 'فرد'],
        'tenant_national_id' => ['رقم الهوية أو السجل التجاري', '1016860767'],
        'tenant_phone' => ['الجوال', '966500000123'],
        'unit_code' => ['رمز الوحدة', '101'],
        'usage' => ['الغرض (سكني / تجاري)', 'سكني'],
        'starts_on' => ['تاريخ البداية YYYY-MM-DD', '2026-01-01'],
        'ends_on' => ['تاريخ النهاية YYYY-MM-DD', '2026-12-31'],
        'annual_rent' => ['الإيجار السنوي بالريال', '48000'],
        'payment_frequency' => ['دورة السداد (شهري / ربع سنوي / نصف سنوي / سنوي / دفعة واحدة)', 'ربع سنوي'],
        'deposit' => ['مبلغ الضمان بالريال', '3000'],
    ];

    public const REQUIRED = ['tenant_name', 'unit_code', 'starts_on', 'ends_on', 'annual_rent', 'payment_frequency'];

    private const MAP = [
        'tenant_kind' => ['فرد' => 'individual', 'منشأة' => 'company', 'منشاة' => 'company', 'شركة' => 'company'],
        'usage' => ['سكني' => 'residential', 'تجاري' => 'commercial'],
        'payment_frequency' => ['شهري' => 'monthly', 'ربع سنوي' => 'quarterly', 'نصف سنوي' => 'semiannual', 'سنوي' => 'annual', 'دفعة واحدة' => 'single'],
    ];

    /** Build the template file and return its path. */
    public function build(string $path): string
    {
        $cell = fn (string $ref, string $v, int $style = 0) => '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . htmlspecialchars($v, ENT_XML1) . '</t></is></c>';
        $cols = array_values(self::COLUMNS);
        $letter = fn (int $i) => chr(65 + $i);
        $head = $ex = '';
        foreach ($cols as $i => [$h, $e]) {
            $head .= $cell($letter($i) . '1', $h, 1);
            $ex .= $cell($letter($i) . '2', $e);
        }
        $widths = '';
        foreach ($cols as $i => [$h]) {
            $widths .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(14, min(40, mb_strlen($h) + 4)) . '" customWidth="1"/>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $widths . '</cols><sheetData><row r="1">' . $head . '</row><row r="2">' . $ex . '</row></sheetData></worksheet>';
        $help = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><cols><col min="1" max="1" width="110" customWidth="1"/></cols><sheetData>';
        foreach (['طريقة التعبئة', 'صف لكل عقد ابتداءً من الصف الثاني في ورقة «العقود». احذف صف المثال قبل الرفع.', 'لا تغيّر ترتيب الأعمدة ولا عناوينها.',
            'التواريخ بالميلادي بصيغة YYYY-MM-DD، والمبالغ بالريال بلا فواصل.', 'رمز الوحدة كما يظهر في «الوحدات التأجيرية».',
            'الحقول الإلزامية: اسم المستأجر، رمز الوحدة، البداية، النهاية، الإيجار السنوي، دورة السداد.',
            'بعد الرفع تراجع كل عقد قبل حفظه؛ لا يُحفظ شيء قبل تأكيدك.'] as $r => $line) {
            $help .= '<row r="' . ($r + 1) . '">' . $cell('A' . ($r + 1), $line, $r === 0 ? 1 : 0) . '</row>';
        }
        $help .= '</sheetData></worksheet>';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="العقود" sheetId="1" r:id="rId1"/><sheet name="طريقة التعبئة" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0D8A7A"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/worksheets/sheet2.xml', $help);
        $zip->close();
        return $path;
    }

    /**
     * Read a filled template (.xlsx or .csv). @return list<array{row:int, fields:list<array{field:string, value:?string, confidence:int}>}>
     * Confidence is 100 for a value read from its column, 0 for a missing required field or an unknown Arabic choice.
     */
    public function parse(string $path): array
    {
        $rows = str_ends_with(strtolower($path), '.csv') ? $this->readCsv($path) : $this->readXlsx($path);
        if (! $rows) {
            throw new MigrationException('الملف فارغ أو لا يطابق القالب.');
        }
        $headers = array_map(fn ($h) => trim((string) $h), array_shift($rows));
        $expected = array_column(array_values(self::COLUMNS), 0);
        if (array_slice($headers, 0, count($expected)) !== $expected) {
            throw new MigrationException('عناوين الأعمدة لا تطابق القالب. نزّل القالب واستعمله دون تغيير ترتيب الأعمدة.');
        }
        $keys = array_keys(self::COLUMNS);
        $out = [];
        foreach ($rows as $i => $r) {
            if (! array_filter($r, fn ($v) => trim((string) $v) !== '')) {
                continue;   // blank row
            }
            $fields = [];
            foreach ($keys as $c => $field) {
                $v = trim((string) ($r[$c] ?? ''));
                $conf = 100;
                if (isset(self::MAP[$field]) && $v !== '') {
                    $mapped = self::MAP[$field][$v] ?? (in_array($v, self::MAP[$field], true) ? $v : null);
                    $conf = $mapped === null ? 0 : 100;
                    $v = $mapped ?? $v;
                }
                if ($v === '' && in_array($field, self::REQUIRED, true)) {
                    $conf = 0;
                }
                if (in_array($field, ['starts_on', 'ends_on'], true) && $v !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $v = $this->excelDate($v) ?? $v;
                    $conf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? 100 : 0;
                }
                $fields[] = ['field' => $field, 'value' => $v === '' ? null : $v, 'confidence' => $conf];
            }
            $out[] = ['row' => $i + 2, 'fields' => $fields];
        }
        return $out;
    }

    private function excelDate(string $v): ?string
    {
        return is_numeric($v) ? gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400)) : null;   // Excel serial date
    }

    private function readCsv(string $path): array
    {
        $h = fopen($path, 'r');
        $rows = [];
        while (($r = fgetcsv($h, escape: '')) !== false) {
            $rows[] = $r;
        }
        fclose($h);
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);   // UTF-8 BOM written by Excel
        }
        return $rows;
    }

    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new MigrationException('تعذّر فتح الملف؛ احفظه بصيغة xlsx أو csv.');
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml);
            foreach ($sx->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            return [];
        }
        $rows = [];
        foreach (simplexml_load_string($sheet)->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                $col = 0;
                foreach (str_split(preg_replace('/\d/', '', (string) $c['r'])) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $t = (string) $c['t'];
                $r[$col - 1] = match ($t) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    default => (string) $c->v,
                };
            }
            if ($r) {
                ksort($r);
                $rows[] = array_replace(array_fill(0, max(array_keys($r)) + 1, ''), $r);
            }
        }
        return $rows;
    }
}
