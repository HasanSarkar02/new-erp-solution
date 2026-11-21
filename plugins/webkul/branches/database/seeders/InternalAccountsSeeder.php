<?php

namespace Webkul\Branch\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Partner as AccountPartner;
use Webkul\Branch\Models\Branch;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Internal-transfer chart of accounts + journals (plan §5.1).
 *
 * Idempotent: accounts by code, journals by code + company, partner
 * properties overwritten to the internal accounts every run — so
 * re-running branches:install (or this seeder) never duplicates
 * anything and repairs a branch partner that was created before
 * Phase 4.
 *
 * Engine note (deviation from the plan's single ITR journal): the
 * Move engine enforces journal types per document — an out_invoice
 * needs a SALE journal and an in_invoice a PURCHASE journal
 * (Move::computeJournalId replaces any other journal). So three
 * journals are seeded: ITR (general, for ENTRY moves such as COGS
 * and payment legs), ITRS (sale, out_invoices) and ITRP (purchase,
 * in_invoices). Internal amounts still never touch the external
 * INV / BILL journals or 121000 / 211000.
 */
class InternalAccountsSeeder extends Seeder
{
    /** code => [name, account type, reconcile] */
    public const ACCOUNTS = [
        '121500' => ['Inter-Branch Receivable', AccountType::ASSET_RECEIVABLE, true],
        '211500' => ['Inter-Branch Payable', AccountType::LIABILITY_PAYABLE, true],
        '400100' => ['Internal Transfer Sales', AccountType::INCOME, false],
        '500100' => ['Internal Transfer COGS', AccountType::EXPENSE_DIRECT_COST, false],
        '110150' => ['Stock in Transit (Internal)', AccountType::ASSET_CURRENT, false],
    ];

    /** code => [name, journal type, default account code] */
    public const JOURNALS = [
        'ITR'  => ['Internal Transfer', JournalType::GENERAL, '400100'],
        'ITRS' => ['Internal Transfer Sales', JournalType::SALE, '400100'],
        'ITRP' => ['Internal Transfer Purchases', JournalType::PURCHASE, '110100'],
    ];

    public function run(): void
    {
        $companies = Company::withoutGlobalScopes()->get();

        foreach ($companies as $company) {
            $accounts = $this->seedAccounts($company);
            $this->seedJournals($company, $accounts);
            $this->linkBranchPartners($company, $accounts);
        }
    }

    /** @return array<string, Account> keyed by code */
    public function seedAccounts(Company $company): array
    {
        $accounts = [];

        foreach (self::ACCOUNTS as $code => [$name, $type, $reconcile]) {
            $account = Account::withoutGlobalScopes()->firstOrCreate(
                ['code' => $code],
                [
                    'currency_id'  => $company->currency_id,
                    'creator_id'   => Auth::id() ?? User::query()->value('id'),
                    'account_type' => $type->value,
                    'name'         => $name,
                    'deprecated'   => false,
                    'reconcile'    => $reconcile,
                    'non_trade'    => false,
                ],
            );

            $account->companies()->syncWithoutDetaching([$company->getKey()]);

            $accounts[$code] = $account->refresh();
        }

        return $accounts;
    }

    /** @param  array<string, Account>  $accounts */
    public function seedJournals(Company $company, array $accounts): void
    {
        foreach (self::JOURNALS as $code => [$name, $type, $defaultAccountCode]) {
            // The default account is one of the new accounts, except
            // ITRP's 110100 (Stock Valuation), which already exists
            // in the standard chart.
            $defaultAccount = $accounts[$defaultAccountCode]
                ?? Account::withoutGlobalScopes()->where('code', $defaultAccountCode)->firstOrFail();

            Journal::withoutGlobalScopes()->firstOrCreate(
                ['code' => $code, 'company_id' => $company->getKey()],
                [
                    'name'                    => $name,
                    'type'                    => $type->value,
                    'currency_id'             => $company->currency_id,
                    'default_account_id'      => $defaultAccount->getKey(),
                    'invoice_reference_type'  => 'invoice',
                    'invoice_reference_model' => 'aureus',
                    'auto_check_on_post'      => true,
                    'show_on_dashboard'       => false,
                ],
            );
        }
    }

    /**
     * Point every branch Partner at the internal accounts, so the
     * invoice payment-term lines land on 121500 / 211500
     * automatically (MoveLine::computeAccountId reads the partner's
     * company properties). Both properties are set on every branch
     * partner: a branch is a receivable counterparty when it buys
     * (Main invoices it) and a payable counterparty when it sells
     * back (return) — one pair of properties covers both directions.
     *
     * @param  array<string, Account>  $accounts
     */
    public function linkBranchPartners(Company $company, array $accounts): void
    {
        $branches = Branch::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->with('partner')
            ->get();

        foreach ($branches as $branch) {
            $partner = $branch->partner;

            if (! $partner) {
                continue;
            }

            $partner = AccountPartner::withoutGlobalScopes()->find($partner->getKey()) ?? $partner;

            $partner->property_account_receivable_id = $accounts['121500']->getKey();
            $partner->property_account_payable_id = $accounts['211500']->getKey();
            $partner->save();
        }
    }
}
