<?php

namespace App\Enterprise;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Liberu\Accounting\Core\Actions\CreateLegalEntity;
use Liberu\Accounting\Core\Actions\SaveBook;
use Liberu\Accounting\Core\Enums\AccountingBasis;
use Liberu\Billing\Core\Actions\CreateBillingAccount;
use Liberu\Foundation\Audit\Contracts\AuditRecorder;
use Liberu\Foundation\Audit\Support\AuditContext;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Settings\Services\ScopedSettings;

final readonly class CompanySetup
{
    public function __construct(
        private CreateLegalEntity $createLegalEntity,
        private SaveBook $saveBook,
        private CreateBillingAccount $createBillingAccount,
        private CurrencyRegistry $currencies,
        private ScopedSettings $settings,
        private CompanyRegionalSettings $regionalSettings,
        private AuditRecorder $audit,
    ) {}

    public function complete(Team $team): bool
    {
        return DB::table('enterprise_company_setups')->where('team_id', $team->getKey())->exists();
    }

    /** @return array{locale: string, timezone: string} */
    public function region(Team $team): array
    {
        return $this->storedRegion($team) ?? CompanyRegionalSettings::defaults();
    }

    /** @param array{locale: string, timezone: string}|null $region */
    public function provision(User $owner, Team $team, string $companyName, string $currency, ?array $region = null): void
    {
        DB::transaction(function () use ($owner, $team, $companyName, $currency, $region): void {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($team->getKey());
            abort_unless($owner->ownsTeam($lockedTeam), 403);

            if ($this->complete($lockedTeam)) {
                return;
            }

            $currency = $this->currencies->get($currency)->code;
            $this->saveRegion($owner, $lockedTeam, [], $region ?? CompanyRegionalSettings::defaults());

            $entity = $this->createLegalEntity->handle([
                'name' => $companyName,
                'currency_code' => $currency,
                'accounting_basis' => AccountingBasis::Accrual->value,
            ]);

            $this->saveBook->handle(null, [
                'legal_entity_id' => $entity->getKey(),
                'name' => 'Primary book',
                'code' => 'PRIMARY',
                'accounting_basis' => AccountingBasis::Accrual->value,
                'is_active' => true,
            ]);

            $account = $this->createBillingAccount->execute([
                'team_id' => $lockedTeam->getKey(),
                'name' => $companyName,
                'currency' => $currency,
            ]);

            DB::table('enterprise_company_setups')->insert([
                'team_id' => $lockedTeam->getKey(),
                'legal_entity_id' => $entity->getKey(),
                'billing_account_id' => $account->getKey(),
            ]);
        }, attempts: 3);
    }

    /** @param array{locale: string, timezone: string} $region */
    public function updateRegion(User $owner, Team $team, array $region): void
    {
        DB::transaction(function () use ($owner, $team, $region): void {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($team->getKey());
            abort_unless($owner->ownsTeam($lockedTeam), 403);
            abort_unless($this->complete($lockedTeam), 404);

            $previous = $this->storedRegion($lockedTeam);
            if ($previous !== $region) {
                $this->saveRegion($owner, $lockedTeam, $previous ?? [], $region);
            }
        }, attempts: 3);
    }

    /** @return array{locale: string, timezone: string}|null */
    private function storedRegion(Team $team): ?array
    {
        return $this->settings->resolve($this->regionalSettings->key(), ['team' => $team->getKey()]);
    }

    /** @param array{locale: string, timezone: string} $region */
    private function saveRegion(User $owner, Team $team, array $previous, array $region): void
    {
        $teamId = (string) $team->getKey();
        $this->settings->put($this->regionalSettings, 'team', $teamId, $region);
        $this->audit->record('enterprise.company_region.updated', $team->getMorphClass(), $teamId, $previous, $region,
            new AuditContext($owner->getKey(), $owner->getMorphClass(), $teamId, null, null));
    }
}
