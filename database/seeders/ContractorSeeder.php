<?php

namespace Database\Seeders;

use App\Enums\FirmType;
use App\Models\Contractor;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The state vendor registry — GLOBAL, not tenant-owned (see the Contractor
 * docblock). Fictional firms only; demo data, so it never runs in production.
 *
 * Ordering: this seeder needs a user for `created_by_id`, so it runs AFTER
 * DemoTenantSeeder. Registry entries are attributed to the platform's first
 * (oversight) account, and `created_by_tenant_id` stays null — nobody's
 * workspace registered them.
 */
class ContractorSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $registrar = User::query()->oldest('id')->first();

        if ($registrar === null) {
            throw new RuntimeException('ContractorSeeder requires an existing user for created_by_id — run DemoTenantSeeder first.');
        }

        $firms = [
            [
                'name' => 'Harmattan Civil Works Ltd',
                'rc_number' => 'RC418290',
                'type' => FirmType::Contractor,
                'category' => 'Civil Works',
                'performance_score' => '78.50',
            ],
            [
                'name' => 'Greenfield Construction Company Ltd',
                'rc_number' => 'RC552104',
                'type' => FirmType::Contractor,
                'category' => 'Building',
                'performance_score' => '84.25',
            ],
            [
                'name' => 'Blue Heron Engineering Ltd',
                'rc_number' => 'RC690337',
                'type' => FirmType::Contractor,
                'category' => 'Civil Works',
                'performance_score' => null,
            ],
            [
                'name' => 'Northline Medical Supplies Ltd',
                'rc_number' => 'RC773845',
                'type' => FirmType::Supplier,
                'category' => 'Supply',
                'performance_score' => '91.00',
            ],
            [
                'name' => 'Meridian Development Consultants',
                'rc_number' => 'RC801562',
                'type' => FirmType::ConsultantFirm,
                'category' => 'Consultancy',
                'performance_score' => '88.75',
            ],
            [
                'name' => 'Summit Facility Services Ltd',
                'rc_number' => 'RC934771',
                'type' => FirmType::Supplier,
                'category' => 'Facility Management',
                'performance_score' => null,
            ],
            [
                // Informal firm with no RC number: the nullable-unique column
                // treats NULLs as distinct, so many of these can coexist.
                'name' => 'Riverbend Community Builders',
                'rc_number' => null,
                'type' => FirmType::Contractor,
                'category' => 'Civil Works',
                'performance_score' => null,
            ],
            [
                'name' => 'Sunrise Integrated Projects Ltd',
                'rc_number' => 'RC206914',
                'type' => FirmType::Contractor,
                'category' => 'Civil Works',
                'performance_score' => '31.00',
                'is_blacklisted' => true,
                'blacklist_reason' => 'Abandoned two sites after mobilisation payment; debarred state-wide.',
            ],
        ];

        foreach ($firms as $firm) {
            Contractor::query()->updateOrCreate(
                ['name' => $firm['name']],
                [
                    'rc_number' => $firm['rc_number'],
                    'type' => $firm['type'],
                    'category' => $firm['category'],
                    'contact_name' => 'Registry Contact',
                    'contact_email' => 'contact@'.str($firm['name'])->slug().'.test',
                    'contact_phone' => '0800'.str_pad((string) (crc32($firm['name']) % 1000000), 6, '0', STR_PAD_LEFT),
                    'address' => 'Plot 1, Registry Close',
                    'is_blacklisted' => $firm['is_blacklisted'] ?? false,
                    'blacklist_reason' => $firm['blacklist_reason'] ?? null,
                    'performance_score' => $firm['performance_score'],
                    'created_by_id' => $registrar->id,
                    'created_by_tenant_id' => null,
                ],
            );
        }
    }
}
