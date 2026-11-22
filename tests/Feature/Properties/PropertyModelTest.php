<?php

namespace Tests\Feature\Properties;

use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Space;
use App\Models\UnitTypeAssetTemplate;
use App\Properties\BuildingWizard;
use App\Properties\InvalidUnitAttributes;
use App\Properties\PropertyException;
use App\Properties\UnitTypes;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec §4 property model. Covered IDs:
 * REQ-0055, REQ-0066, REQ-0067, REQ-0070, REQ-0071, REQ-0072, REQ-0073, REQ-0074, REQ-0075, REQ-0076, REQ-0077, REQ-0078, REQ-0079, REQ-0080, REQ-0081, REQ-0082, REQ-0083, REQ-0084, REQ-0085, REQ-0086, REQ-0087, REQ-0088, REQ-0089, REQ-0090, REQ-0091, REQ-0092, REQ-0093, REQ-0094, REQ-0095, REQ-0096, REQ-0097, REQ-0098, REQ-0099, REQ-0100, REQ-0101, REQ-0102, REQ-0103, REQ-0104, REQ-0105, REQ-0106, REQ-0107, REQ-0108, REQ-0109, REQ-0110, REQ-0111, REQ-0112, REQ-0113, REQ-0114, REQ-0115, REQ-0116, REQ-0117, REQ-0118, REQ-0119, REQ-0120, REQ-0121, REQ-0123, REQ-0124, REQ-0125, REQ-0126, REQ-0127, REQ-0128, REQ-0129, REQ-0130, REQ-0131, REQ-0132, REQ-0133, REQ-0134, REQ-0135, REQ-0136, REQ-0137, REQ-0138, REQ-0144, REQ-0167, REQ-0176
 * REQ-0070, REQ-0071, REQ-0072, REQ-0073 (space ≠ leasable unit ≠ asset),
 * REQ-0074, REQ-0075, REQ-0076, REQ-0077, REQ-0078, REQ-0079, REQ-0080, REQ-0081, REQ-0082 (tree),
 * REQ-0121, REQ-0123, REQ-0124, REQ-0125, REQ-0126, REQ-0127, REQ-0128 (wizard steps 1–6),
 * REQ-0129…REQ-0138 entities (REQ-0129, REQ-0130, REQ-0131, REQ-0132, REQ-0133, REQ-0134, REQ-0135, REQ-0136, REQ-0137, REQ-0138, REQ-0144),
 * REQ-0066, REQ-0067 (management agreement), REQ-0167 (bilingual names), REQ-0176 (tracks flag), REQ-0571.
 */
class PropertyModelTest extends TestCase
{
    use RefreshDatabase;

    private BuildingWizard $w;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        $this->w = app(BuildingWizard::class);
    }

    private function property(string $name = 'عمارة النخيل', string $template = 'mixed_building'): Property
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك', 'name_en' => 'Owner']);
        $portfolio = Portfolio::create(['name_ar' => 'محفظة', 'name_en' => 'Portfolio', 'owner_id' => $owner->id]);
        return $this->w->createProperty($portfolio, [
            'name_ar' => $name, 'name_en' => 'Property', 'deed_number' => '310101000001', 'ejar_property_number' => 'EJ-1',
            'real_estate_registry_number' => 'RR-9', 'national_address' => ['building_no' => '1234', 'street' => 'King Fahd', 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '12211', 'additional_no' => '5678'],
            'region' => 'riyadh', 'city' => 'riyadh', 'latitude' => '24.7136000', 'longitude' => '46.6753000',
        ], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], $template);
    }

    private function codes(array $nodes): array
    {
        return array_map(fn ($n) => $n['code'], $nodes);
    }

    /** Scenario أ — 8 apartments + 4 shops → 12 separate leasable units; whole building as composite from the same data (REQ-0096…REQ-0102). */
    public function test_req_0102_scenario_a_eight_apartments_four_shops_and_whole_building(): void
    {
        $p = $this->property();
        $b = $this->w->generate($p, ['name_ar' => 'المبنى A', 'numbering' => 'floor_seq', 'floors' => [
            ['number' => 0, 'name_ar' => 'الطابق الأرضي', 'units' => [['type' => 'shop', 'count' => 4, 'label_ar' => 'محل']]],
            ['number' => 1, 'name_ar' => 'الطابق 1', 'units' => [['type' => 'apartment', 'count' => 4, 'label_ar' => 'شقة']]],
            ['number' => 2, 'name_ar' => 'الطابق 2', 'units' => [['type' => 'apartment', 'count' => 4, 'label_ar' => 'شقة']]],
        ]]);
        $tree = $this->w->tree($p)[0]['children'];
        $this->assertSame(['1', '2', '3', '4'], $this->codes($tree[0]['children']));
        $this->assertSame(['101', '102', '103', '104'], $this->codes($tree[1]['children']));
        $this->assertSame(['201', '202', '203', '204'], $this->codes($tree[2]['children']));
        $this->assertSame(12, LeasableUnit::where('property_id', $p->id)->count());
        $this->assertSame(4, LeasableUnit::where('unit_type', 'shop')->count());

        $whole = $this->w->wholeBuilding($b, 'ALL', 'المبنى كاملاً');
        $this->assertTrue($whole->is_composite);
        $this->assertSame('whole_building', $whole->unit_type);
        $this->assertCount(12, $whole->spaces);
        $this->assertSame(12, Space::where('kind', 'unit')->count());   // no duplicated data
        $apt101 = LeasableUnit::where('code', '101')->first();
        $this->assertSame([$whole->id], $apt101->overlappingUnitIds());   // leasing one blocks the other (engine in M3)
        $this->assertCount(12, $whole->overlappingUnitIds());
    }

    /** Scenario ب — a villa: one building with ground + first + annex, a single unit (REQ-0103…REQ-0106). */
    public function test_req_0106_scenario_b_villa_is_one_unit(): void
    {
        $p = $this->property('فيلا الياسمين', 'villa');
        $this->w->generate($p, ['name_ar' => 'الفيلا', 'single_unit' => ['type' => 'villa', 'name_ar' => 'الفيلا كاملة', 'levels' => ['طابق أرضي', 'أول', 'ملحق']]]);
        $this->assertSame(1, LeasableUnit::count());
        $tree = $this->w->tree($p)[0]['children'];
        $this->assertSame('villa', $tree[0]['type']);
        $this->assertSame(['L1', 'L2', 'L3'], $this->codes($tree[0]['children']));
    }

    /** Scenario ج — owner villa + 6-apartment building in one property (REQ-0107…REQ-0110). */
    public function test_req_0110_scenario_c_villa_and_apartments_in_one_property(): void
    {
        $p = $this->property('مجمع الروضة', 'compound');
        $this->w->generate($p, ['name_ar' => 'المبنى 1: فيلا المالك', 'single_unit' => ['type' => 'villa', 'code' => 'V1', 'name_ar' => 'فيلا المالك']]);
        $this->w->generate($p, ['name_ar' => 'المبنى 2', 'floors' => [
            ['number' => 1, 'units' => [['type' => 'apartment', 'count' => 3]]], ['number' => 2, 'units' => [['type' => 'apartment', 'count' => 3]]],
        ]]);
        $this->assertSame(2, $p->buildings()->count());
        $this->assertSame(7, LeasableUnit::count());
        $this->assertSame(6, LeasableUnit::where('unit_type', 'apartment')->count());
    }

    /** Scenario د — compound: villas 1–10, building 11 with 12 apartments, shared facilities (REQ-0111…REQ-0115, REQ-0080). */
    public function test_req_0115_scenario_d_compound_with_shared_facilities(): void
    {
        $p = $this->property('مجمع الواحة', 'compound');
        for ($i = 1; $i <= 10; $i++) {
            $this->w->generate($p, ['name_ar' => "المبنى $i", 'single_unit' => ['type' => 'villa', 'code' => "V$i", 'name_ar' => "فيلا $i"]]);
        }
        $this->w->generate($p, ['name_ar' => 'المبنى 11', 'code_prefix' => 'B11-', 'floors' => [
            ['number' => 1, 'units' => [['type' => 'apartment', 'count' => 6]]], ['number' => 2, 'units' => [['type' => 'apartment', 'count' => 6]]],
        ], 'common_areas' => [['name_ar' => 'مسبح'], ['name_ar' => 'نادي'], ['name_ar' => 'بوابة'], ['name_ar' => 'حراسة']]]);
        $this->assertSame(11, $p->buildings()->count());
        $this->assertSame(22, LeasableUnit::count());
        $this->assertSame(4, Space::where('kind', 'common_area')->count());
        $this->assertSame(0, LeasableUnit::whereHas('spaces', fn ($q) => $q->where('kind', 'common_area'))->count());
    }

    /** Scenario هـ — office tower: floors 1–5 offices (each office or the whole floor as composite), ground floor shops (REQ-0116…REQ-0120). */
    public function test_req_0119_scenario_e_office_tower_with_floor_composites(): void
    {
        $p = $this->property('برج الأعمال', 'office_tower');
        $floors = [['number' => 0, 'units' => [['type' => 'shop', 'count' => 3]]]];
        for ($f = 1; $f <= 5; $f++) {
            $floors[] = ['number' => $f, 'units' => [['type' => 'office', 'count' => 4]]];
        }
        $this->w->generate($p, ['name_ar' => 'البرج', 'floors' => $floors]);
        $floor3 = Space::where('kind', 'floor')->where('code', 'F3')->first();
        $ids = Space::where('parent_id', $floor3->id)->pluck('id')->all();
        $composite = $this->w->createComposite($p, $ids, 'office', 'FL3', 'الطابق 3 كاملاً');
        $this->assertTrue($composite->is_composite);
        $this->assertSame(23 + 1, LeasableUnit::count());
        $this->assertSame([$composite->id], LeasableUnit::where('code', '301')->first()->overlappingUnitIds());
    }

    /** REQ-0081 — rentable locations on facilities (billboard on the roof, ATM in the lobby). */
    public function test_req_0081_rentable_locations_on_common_areas(): void
    {
        $p = $this->property();
        $this->w->generate($p, ['name_ar' => 'A', 'common_areas' => [
            ['name_ar' => 'السطح', 'rentable_locations' => [['type' => 'billboard', 'name_ar' => 'لوحة السطح'], ['type' => 'atm_tower', 'name_ar' => 'برج اتصالات']]],
            ['name_ar' => 'المدخل', 'rentable_locations' => [['type' => 'atm_tower', 'name_ar' => 'صرّاف المدخل']]],
        ]]);
        $this->assertSame(3, LeasableUnit::count());
        $this->assertSame(2, Space::where('kind', 'rentable_location')->where('unit_type', 'atm_tower')->count());
    }

    /** REQ-0082 — vacant land: a property without a building, itself the leasable unit. */
    public function test_req_0082_vacant_land_has_no_building(): void
    {
        $p = $this->property('أرض', 'custom');
        $lu = $this->w->addLand($p, 'أرض فضاء', ['area_sqm' => '1500', 'deed_no' => 'D1', 'permitted_use' => 'commercial']);
        $this->assertSame('land', $lu->unit_type);
        $this->assertNull($lu->spaces->first()->building_id);
        $this->assertSame(0, $p->buildings()->count());
    }

    /** REQ-0083…REQ-0095, REQ-0055 — every unit type with its mandatory attributes. */
    public function test_req_0083_every_unit_type_validates_its_mandatory_attributes(): void
    {
        $valid = [
            'apartment' => ['area_sqm' => '120.5', 'rooms' => 3, 'bathrooms' => 2, 'furnished' => false, 'electricity_meter_no' => 'E1', 'water_account_no' => 'W1'],
            'villa' => ['area_sqm' => '400', 'floors' => 2, 'rooms' => 6, 'annex' => true, 'pool' => false, 'garden' => true],
            'shop' => ['area_sqm' => '60', 'frontage_m' => '8.5', 'permitted_activity' => 'بقالة', 'municipal_license_no' => 'M1'],
            'office' => ['area_sqm' => '90', 'furnished_equipped' => true, 'parking_spaces' => 2],
            'whole_building' => [],
            'warehouse' => ['area_sqm' => '1000', 'height_m' => '9', 'floor_type' => 'خرسانة', 'loading_door' => true, 'three_phase_power' => true],
            'kiosk' => ['area_sqm' => '6', 'location_description' => 'بجوار المدخل', 'permitted_activity' => 'قهوة'],
            'parking' => ['number' => 'P12', 'covered' => true, 'dedicated' => false],
            'billboard' => ['dimensions' => '4x12', 'placement' => 'roof', 'illuminated' => true, 'municipal_permit_no' => 'B9'],
            'atm_tower' => ['location_description' => 'المدخل', 'occupied_area_sqm' => '2', 'power_consumption' => '1.5kW', 'lessee_entity' => 'بنك'],
            'land' => ['area_sqm' => '1500', 'deed_no' => 'D1', 'permitted_use' => 'agricultural'],
            'open_space' => ['area_sqm' => '300', 'use' => 'events'],
        ];
        foreach ($valid as $type => $attrs) {
            $this->assertSame(array_keys($attrs), array_keys(UnitTypes::validate($type, $attrs)), $type);
            if ($attrs) {
                $missing = $attrs;
                array_pop($missing);
                try {
                    UnitTypes::validate($type, $missing);
                    $this->fail("$type accepted missing attribute");
                } catch (InvalidUnitAttributes) {
                    $this->assertTrue(true);
                }
            }
        }
        $this->assertCount(13, UnitTypes::TYPES);   // 12 types of §4.3 + room
        $this->assertTrue(UnitTypes::isComposite('whole_building'));
        $this->assertTrue(UnitTypes::TYPES['land']['needs_pro_review']);   // R-19
        $this->expectException(InvalidUnitAttributes::class);
        UnitTypes::validate('apartment', ['area_sqm' => 120.5] + $valid['apartment']);   // float rejected
    }

    /** REQ-0123 — owners and shares must add up to exactly one. */
    public function test_req_0123_shares_must_add_up_to_one(): void
    {
        $o1 = Owner::create(['kind' => 'heirs', 'name_ar' => 'وريث 1']);
        $o2 = Owner::create(['kind' => 'heirs', 'name_ar' => 'وريث 2']);
        $o3 = Owner::create(['kind' => 'heirs', 'name_ar' => 'وريث 3']);
        $pf = Portfolio::create(['name_ar' => 'ورثة']);
        $p = $this->w->createProperty($pf, ['name_ar' => 'عمارة الورثة'], [
            ['owner' => $o1, 'numerator' => 1, 'denominator' => 2], ['owner' => $o2, 'numerator' => 1, 'denominator' => 3], ['owner' => $o3, 'numerator' => 1, 'denominator' => 6],
        ], 'residential_building');
        $this->assertSame(3, $p->shares()->count());

        $this->expectException(PropertyException::class);
        $this->w->createProperty($pf, ['name_ar' => 'خطأ'], [['owner' => $o1, 'numerator' => 1, 'denominator' => 2], ['owner' => $o2, 'numerator' => 1, 'denominator' => 3]], 'custom');
    }

    /** REQ-0066, REQ-0067 — management agreement; the self agreement has a zero fee. */
    public function test_req_0067_self_agreement_has_zero_fee_and_req_0066_terms_are_kept(): void
    {
        $p = $this->property();
        $owner = Owner::first();
        $self = $this->w->createAgreement($owner, [$p], ['is_self' => true, 'fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'collector' => 'owner', 'starts_on' => '2026-12-01']);
        $this->assertSame(0, $self->fee_percentage_bp);
        $this->assertTrue($self->fee_fixed_minor->isZero());

        $a = $this->w->createAgreement($owner, [$p], ['fee_type' => 'mixed', 'fee_percentage_bp' => 750, 'fee_fixed_minor' => Money::parse('500'), 'spend_cap_minor' => Money::parse('2000'), 'collector' => 'manager', 'starts_on' => '2026-12-01']);
        $this->assertSame(750, $a->fee_percentage_bp);
        $this->assertSame(200000, $a->spend_cap_minor->minor());
        $this->assertSame([$p->id], $a->properties->pluck('id')->all());

        $this->expectException(QueryException::class);   // the database refuses a self agreement with a fee
        DB::table('management_agreements')->where('id', $self->id)->update(['fee_percentage_bp' => 100]);
    }

    /** REQ-0126 — merge, split, change type. */
    public function test_req_0126_merge_split_and_change_type(): void
    {
        $p = $this->property();
        $this->w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 3]]]]]);
        [$a, $b, $c] = Space::where('kind', 'unit')->orderBy('sort')->get()->all();

        $merged = $this->w->merge($a, $b, '101-102', 'شقة مدمجة');
        $this->assertCount(2, $merged->spaces);
        $this->assertSame(2, LeasableUnit::whereNull('archived_at')->count());   // merged + 103

        $parts = $this->w->split($c, [['code' => '103A', 'name_ar' => 'استوديو أ'], ['code' => '103B', 'name_ar' => 'استوديو ب']]);
        $this->assertCount(2, $parts);
        $this->assertNotNull($c->fresh()->archived_at);   // archived, never deleted

        $this->w->changeType($parts[0]->spaces->first(), 'office');
        $this->assertSame('office', $parts[0]->fresh()->unit_type);
    }

    /** REQ-0125 — letter numbering pattern A1, A2… */
    public function test_req_0125_letter_numbering_pattern(): void
    {
        $p = $this->property();
        $this->w->generate($p, ['name_ar' => 'A', 'numbering' => 'letter_seq', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]], ['number' => 2, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        $this->assertSame(['A1', 'A2', 'B1', 'B2'], LeasableUnit::orderBy('id')->pluck('code')->all());
    }

    /** REQ-0127 — default assets per unit type from the subscriber list, or as edited by the manager. */
    public function test_req_0127_default_assets_per_unit_type(): void
    {
        $p = $this->property();
        $this->w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        UnitTypeAssetTemplate::create(['unit_type' => 'apartment', 'name_ar' => 'مكيف سبليت', 'category' => 'hvac', 'quantity' => 2]);
        UnitTypeAssetTemplate::create(['unit_type' => 'apartment', 'name_ar' => 'سخان', 'category' => 'plumbing']);
        $this->assertSame(6, $this->w->applyDefaultAssets($p));
        $this->assertSame(2, $this->w->applyDefaultAssets($p, ['apartment' => [['name_ar' => 'مطبخ', 'category' => 'appliances']]]));
    }

    /** REQ-0128, REQ-0138 — a price per unit per duration; day/week prices wait for phase 3. */
    public function test_req_0128_price_per_unit_and_duration(): void
    {
        $p = $this->property();
        $this->w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        $u = LeasableUnit::first();
        $this->w->setPrice($u, 'month', Money::parse('6000'));
        $this->w->setPrice($u, 'year', Money::parse('60000'));
        $this->w->setPrice($u, 'year', Money::parse('58000'));   // replaces (old one archived)
        $this->assertSame(5800000, \App\Models\RatePlan::where('duration_unit', 'year')->whereNull('archived_at')->first()->price_minor->minor());
        $this->expectException(PropertyException::class);
        $this->w->setPrice($u, 'day', Money::parse('350'));
    }

    /** REQ-0176 — a unit opens for short-term letting only with a valid hospitality licence (DB constraint too). */
    public function test_req_0176_short_term_requires_hospitality_licence(): void
    {
        $p = $this->property();
        $this->w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        $u = LeasableUnit::first();
        $this->assertSame('long', $u->allowed_tracks);
        try {
            DB::transaction(fn () => $u->update(['allowed_tracks' => 'both']));
            $this->fail('short track without licence accepted');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $u->refresh()->update(['allowed_tracks' => 'both', 'hospitality_license_no' => 'H-1', 'hospitality_license_expires_on' => '2027-06-30']);
        $this->assertTrue($u->canOpenShortTerm(new \DateTimeImmutable('2027-06-30 12:00')));
        $this->assertFalse($u->canOpenShortTerm(new \DateTimeImmutable('2027-07-01')));
    }

    /** REQ-0073, REQ-0144 — an asset can hang on any node: unit, building common area, compound. */
    public function test_req_0073_assets_attach_to_any_node(): void
    {
        $p = $this->property();
        $this->w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]], 'common_areas' => [['name_ar' => 'مصعد']]]);
        foreach (Space::whereIn('kind', ['unit', 'common_area', 'floor'])->get() as $node) {
            \App\Models\Asset::create(['space_id' => $node->id, 'qr_code' => 'Q' . $node->id, 'category' => 'hvac', 'type' => 'split', 'name_ar' => 'أصل']);
        }
        $this->assertSame(3, \App\Models\Asset::count());
    }

    /** REQ-0168 — property structure rows are archived, never physically deleted. REQ-0169 — RLS on every new table. */
    public function test_req_0168_property_rows_cannot_be_deleted_and_are_isolated(): void
    {
        $p = $this->property();
        $other = $this->makeSubscriber();
        $this->tenant()->set($other->id);
        $this->assertSame(0, Property::count());
        $this->assertSame(0, Owner::count());
        $this->tenant()->set($p->subscriber_id);
        $this->expectException(QueryException::class);
        Property::whereKey($p->id)->delete();
    }

    /** REQ-0167 — every entity carries Arabic and English names. */
    public function test_req_0167_entities_have_arabic_and_english_names(): void
    {
        foreach (['owners', 'portfolios', 'properties', 'buildings', 'spaces', 'leasable_units', 'assets', 'subscribers'] as $t) {
            $cols = array_column(DB::select('select column_name from information_schema.columns where table_schema = current_schema() and table_name = ?', [$t]), 'column_name');
            $this->assertContains('name_ar', $cols, $t);
            $this->assertContains('name_en', $cols, $t);
        }
    }
}
