<?php

namespace App\Properties;

use App\Models\Asset;
use App\Models\Building;
use App\Models\LeasableUnit;
use App\Models\ManagementAgreement;
use App\Models\Owner;
use App\Models\OwnershipShare;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Space;
use App\Models\UnitTypeAssetTemplate;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Add-building wizard (spec §4.5, REQ-0121…REQ-0128). Step 0 (AI quick migration) lives in M9.
 *
 *  1. property data + owners and shares          → createProperty()
 *  2. template                                    → TEMPLATES
 *  3. quick generation (floors, units, numbering) → generate()
 *  4. edit: merge, split, change type             → merge(), split(), changeType(), createComposite()
 *  5. default assets per unit type                → applyDefaultAssets()
 *  6. pricing per unit and duration               → setPrice()
 */
class BuildingWizard
{
    public const TEMPLATES = [
        'residential_building' => ['ar' => 'عمارة سكنية', 'en' => 'Residential building'],
        'mixed_building' => ['ar' => 'عمارة مختلطة (محلات + شقق)', 'en' => 'Mixed building (shops + apartments)'],
        'villa' => ['ar' => 'فيلا', 'en' => 'Villa'],
        'compound' => ['ar' => 'مجمّع', 'en' => 'Compound'],
        'office_tower' => ['ar' => 'برج مكاتب', 'en' => 'Office tower'],
        'custom' => ['ar' => 'مخصص', 'en' => 'Custom'],
    ];

    public const NUMBERING = ['floor_seq', 'letter_seq'];   // 101, 102… | A1, A2…

    /**
     * Step 1. $owners = [['owner' => Owner, 'numerator' => 1, 'denominator' => 2], ...] — shares must add up to exactly 1.
     */
    public function createProperty(Portfolio $portfolio, array $data, array $owners, string $template): Property
    {
        if (! isset(self::TEMPLATES[$template])) {
            throw new PropertyException("Unknown template [$template].");
        }
        $this->assertSharesSumToOne($owners);
        return DB::transaction(function () use ($portfolio, $data, $owners, $template) {
            $property = Property::create($data + ['portfolio_id' => $portfolio->id, 'template' => $template]);
            foreach ($owners as $o) {
                OwnershipShare::create(['property_id' => $property->id, 'owner_id' => $o['owner']->id, 'share_numerator' => $o['numerator'], 'share_denominator' => $o['denominator']]);
            }
            return $property;
        });
    }

    /** Management agreement (REQ-0066); a self agreement has zero fee (REQ-0067). */
    public function createAgreement(Owner $owner, array $properties, array $terms): ManagementAgreement
    {
        if ($terms['is_self'] ?? false) {
            $terms = array_merge($terms, ['fee_type' => 'fixed', 'fee_percentage_bp' => 0, 'fee_fixed_minor' => 0]);
        }
        return DB::transaction(function () use ($owner, $properties, $terms) {
            $a = ManagementAgreement::create($terms + ['owner_id' => $owner->id]);
            foreach ($properties as $p) {
                $a->properties()->attach($p->id, ['subscriber_id' => $p->subscriber_id]);
            }
            return $a;
        });
    }

    /**
     * Step 3. Generate one building's tree.
     * $spec = ['name_ar' => ..., 'numbering' => 'floor_seq', 'floors' => [['number' => 0, 'name_ar' => 'الطابق الأرضي', 'units' => [['type' => 'shop', 'count' => 4, 'label_ar' => 'محل']]], ...],
     *          'common_areas' => [['name_ar' => 'مسبح']], 'single_unit' => ['type' => 'villa', 'name_ar' => 'الفيلا كاملة']]
     */
    public function generate(Property $property, array $spec): Building
    {
        $numbering = $spec['numbering'] ?? 'floor_seq';
        if (! in_array($numbering, self::NUMBERING, true)) {
            throw new PropertyException("Unknown numbering pattern [$numbering].");
        }
        return DB::transaction(function () use ($property, $spec, $numbering) {
            $building = Building::create(['property_id' => $property->id, 'name_ar' => $spec['name_ar'], 'name_en' => $spec['name_en'] ?? null]);

            if (isset($spec['single_unit'])) {   // villa: the building itself is one unit (scenario ب)
                $u = $spec['single_unit'];
                $space = $this->space($property, $building, null, 'unit', $u['type'], $u['code'] ?? 'U1', $u['name_ar'], 0);
                foreach ($u['levels'] ?? [] as $i => $level) {   // e.g. ground + first + annex as rooms of the villa
                    $this->space($property, $building, $space->id, 'room', null, 'L' . ($i + 1), $level, $i);
                }
                $this->leasable($property, [$space], $u['type'], $space->code, $u['name_ar']);
            }

            foreach ($spec['floors'] ?? [] as $fi => $floor) {
                $number = $floor['number'] ?? $fi;
                $floorSpace = $this->space($property, $building, null, 'floor', null, 'F' . $number, $floor['name_ar'] ?? "الطابق $number", $fi);
                $seq = 0;
                foreach ($floor['units'] ?? [] as $group) {
                    UnitTypes::get($group['type']);
                    for ($i = 0; $i < ($group['count'] ?? 1); $i++) {
                        $seq++;
                        $code = ($spec['code_prefix'] ?? '') . ($numbering === 'floor_seq' ? (string) ($number === 0 ? $seq : $number * 100 + $seq) : chr(65 + $fi) . $seq);
                        $name = trim(($group['label_ar'] ?? UnitTypes::get($group['type'])['ar']) . ' ' . $code);
                        $unit = $this->space($property, $building, $floorSpace->id, 'unit', $group['type'], $code, $name, $seq);
                        $this->leasable($property, [$unit], $group['type'], $code, $name);
                    }
                }
            }

            foreach ($spec['common_areas'] ?? [] as $ci => $area) {
                $ca = $this->space($property, $building, null, 'common_area', null, 'C' . ($ci + 1), $area['name_ar'], 1000 + $ci);
                foreach ($area['rentable_locations'] ?? [] as $ri => $loc) {   // billboard on the roof, ATM in the lobby (REQ-0081)
                    $code = 'C' . ($ci + 1) . '-R' . ($ri + 1);
                    $rl = $this->space($property, $building, $ca->id, 'rentable_location', $loc['type'], $code, $loc['name_ar'], $ri);
                    $this->leasable($property, [$rl], $loc['type'], $code, $loc['name_ar']);
                }
            }
            return $building;
        });
    }

    /** Vacant land: a property without a building that is itself the leasable unit (REQ-0082). */
    public function addLand(Property $property, string $nameAr, array $attributes = []): LeasableUnit
    {
        return DB::transaction(function () use ($property, $nameAr, $attributes) {
            $space = $this->space($property, null, null, 'land', 'land', 'LAND', $nameAr, 0, $attributes);
            return $this->leasable($property, [$space], 'land', 'LAND', $nameAr);
        });
    }

    /**
     * A composite leasable unit over existing spaces (whole building, whole floor) — the same data, no duplication
     * (REQ-0102, REQ-0088, REQ-0119).
     */
    public function createComposite(Property $property, array $spaceIds, string $unitType, string $code, string $nameAr): LeasableUnit
    {
        $spaces = Space::whereIn('id', $spaceIds)->where('property_id', $property->id)->whereNull('archived_at')->get();
        if ($spaces->count() !== count(array_unique($spaceIds)) || $spaces->count() < 2) {
            throw new PropertyException('A composite unit needs at least two spaces of the same property.');
        }
        return DB::transaction(fn () => $this->leasable($property, $spaces->all(), $unitType, $code, $nameAr, true));
    }

    /** All unit spaces of a building as one composite "whole building" unit (scenario أ). */
    public function wholeBuilding(Building $building, string $code, string $nameAr): LeasableUnit
    {
        $ids = Space::where('building_id', $building->id)->where('kind', 'unit')->whereNull('archived_at')->pluck('id')->all();
        return $this->createComposite($building->property, $ids, 'whole_building', $code, $nameAr);
    }

    /** Step 4 — merge two unit spaces into one leasable unit; the separate units are archived. */
    public function merge(Space $a, Space $b, string $code, string $nameAr): LeasableUnit
    {
        if ($a->property_id !== $b->property_id || $a->kind !== 'unit' || $b->kind !== 'unit') {
            throw new PropertyException('Only two units of the same property can be merged.');
        }
        return DB::transaction(function () use ($a, $b, $code, $nameAr) {
            $this->archiveSingleUnitsOf([$a, $b]);
            return $this->leasable($a->building->property, [$a, $b], $a->unit_type, $code, $nameAr, true);
        });
    }

    /** Step 4 — split one unit space into several new units under the same parent. */
    public function split(Space $unit, array $parts): array
    {
        if ($unit->kind !== 'unit' || count($parts) < 2) {
            throw new PropertyException('A unit splits into at least two parts.');
        }
        return DB::transaction(function () use ($unit, $parts) {
            $this->archiveSingleUnitsOf([$unit]);
            $unit->archive();
            $property = $unit->building->property;
            $created = [];
            foreach ($parts as $i => $p) {
                $s = $this->space($property, $unit->building, $unit->parent_id, 'unit', $p['type'] ?? $unit->unit_type, $p['code'], $p['name_ar'], $unit->sort * 10 + $i);
                $created[] = $this->leasable($property, [$s], $s->unit_type, $s->code, $s->name_ar);
            }
            return $created;
        });
    }

    /** Step 4 — change a unit's type (keeps attributes that the new type also has). */
    public function changeType(Space $unit, string $type): void
    {
        UnitTypes::get($type);
        DB::transaction(function () use ($unit, $type) {
            $keep = array_intersect_key($unit->attributes ?? [], UnitTypes::get($type)['attributes']);
            $unit->update(['unit_type' => $type, 'attributes' => $keep]);
            foreach ($unit->leasableUnits()->whereNull('archived_at')->where('is_composite', false)->get() as $lu) {
                $lu->update(['unit_type' => $type]);
            }
        });
    }

    /** Fill the mandatory attributes of a unit (§4.3) — validated per type. */
    public function setAttributes(Space $unit, array $attributes): void
    {
        $unit->update(['attributes' => UnitTypes::validate($unit->unit_type, $attributes)]);
    }

    /**
     * Step 5 — create the default assets of each unit from the subscriber's template list for its type, after the
     * manager confirmed or edited it ($overrides[unit_type] replaces the template list).
     */
    public function applyDefaultAssets(Property $property, array $overrides = []): int
    {
        $count = 0;
        $units = Space::where('property_id', $property->id)->where('kind', 'unit')->whereNull('archived_at')->get();
        DB::transaction(function () use ($units, $overrides, &$count) {
            foreach ($units as $unit) {
                $list = $overrides[$unit->unit_type] ?? UnitTypeAssetTemplate::where('unit_type', $unit->unit_type)->get()->map(fn ($t) => $t->only(['name_ar', 'name_en', 'category', 'quantity']))->all();
                foreach ($list as $item) {
                    for ($q = 0; $q < ($item['quantity'] ?? 1); $q++) {
                        Asset::create(['space_id' => $unit->id, 'qr_code' => 'A-' . Str::upper(Str::random(10)), 'category' => $item['category'], 'type' => $item['category'], 'name_ar' => $item['name_ar'], 'name_en' => $item['name_en'] ?? null]);
                        $count++;
                    }
                }
            }
        });
        return $count;
    }

    /**
     * Step 6 — price per unit per duration unit (REQ-0128, REQ-0177). Day/week prices belong to the short-term track,
     * which is phase 3 (decision 6): refused until that phase.
     */
    public function setPrice(LeasableUnit $unit, string $durationUnit, Money $price): RatePlan
    {
        if (in_array($durationUnit, ['day', 'week'], true)) {
            throw new PropertyException('Daily/weekly prices belong to short-term letting (phase 3, decision 6).');
        }
        if (! in_array($durationUnit, ['month', 'year'], true) || ! $price->isPositive()) {
            throw new PropertyException('Invalid price.');
        }
        return DB::transaction(function () use ($unit, $durationUnit, $price) {
            RatePlan::where('leasable_unit_id', $unit->id)->where('duration_unit', $durationUnit)->whereNull('archived_at')->update(['archived_at' => now()]);
            return RatePlan::create(['leasable_unit_id' => $unit->id, 'duration_unit' => $durationUnit, 'price_minor' => $price]);
        });
    }

    /** The tree of a property as nested arrays (for the UI and the scenario tests). */
    public function tree(Property $property): array
    {
        $build = function ($nodes) use (&$build) {
            return $nodes->map(fn (Space $s) => ['code' => $s->code, 'kind' => $s->kind, 'type' => $s->unit_type, 'name' => $s->name_ar, 'children' => $build($s->children)])->all();
        };
        $out = [];
        foreach ($property->buildings()->orderBy('id')->get() as $b) {
            $out[] = ['building' => $b->name_ar, 'children' => $build(Space::where('building_id', $b->id)->whereNull('parent_id')->whereNull('archived_at')->orderBy('sort')->get())];
        }
        foreach (Space::where('property_id', $property->id)->whereNull('building_id')->whereNull('archived_at')->get() as $land) {
            $out[] = ['building' => null, 'children' => [['code' => $land->code, 'kind' => 'land', 'type' => 'land', 'name' => $land->name_ar, 'children' => []]]];
        }
        return $out;
    }

    private function assertSharesSumToOne(array $owners): void
    {
        if (! $owners) {
            throw new PropertyException('A property needs at least one owner.');
        }
        // Sum of fractions == 1 using integer arithmetic only.
        $num = 0;
        $den = 1;
        foreach ($owners as $o) {
            if ($o['numerator'] <= 0 || $o['denominator'] <= 0 || $o['numerator'] > $o['denominator']) {
                throw new PropertyException('Each share must be a fraction between 0 and 1.');
            }
            $num = $num * $o['denominator'] + $o['numerator'] * $den;
            $den *= $o['denominator'];
            $g = $this->gcd($num, $den);
            [$num, $den] = [intdiv($num, $g), intdiv($den, $g)];
        }
        if ($num !== $den) {
            throw new PropertyException("Ownership shares add up to $num/$den, not 1.");
        }
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? max(1, abs($a)) : $this->gcd($b, $a % $b);
    }

    private function archiveSingleUnitsOf(array $spaces): void
    {
        foreach ($spaces as $s) {
            foreach ($s->leasableUnits()->whereNull('archived_at')->get() as $lu) {
                $lu->archive();
            }
        }
    }

    private function space(Property $p, ?Building $b, ?int $parentId, string $kind, ?string $type, string $code, string $nameAr, int $sort, array $attributes = []): Space
    {
        return Space::create(['property_id' => $p->id, 'building_id' => $b?->id, 'parent_id' => $parentId, 'kind' => $kind, 'unit_type' => $type, 'code' => $code, 'name_ar' => $nameAr, 'attributes' => $attributes, 'sort' => $sort]);
    }

    private function leasable(Property $p, array $spaces, string $type, string $code, string $nameAr, bool $composite = false): LeasableUnit
    {
        $lu = LeasableUnit::create(['property_id' => $p->id, 'code' => $code, 'name_ar' => $nameAr, 'unit_type' => $type, 'is_composite' => $composite]);
        foreach ($spaces as $s) {
            $lu->spaces()->attach($s->id, ['subscriber_id' => $p->subscriber_id]);
        }
        return $lu;
    }
}
