<?php

namespace Webkul\Branch\Services;

use Illuminate\Support\Carbon;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferPriceRule;
use Webkul\Product\Models\Product;

/**
 * Internal transfer price resolution (plan §4.3).
 *
 * Resolution order for a line's suggested price — the user can always
 * override the suggestion per line, and the override is stored on the
 * line, never re-applied after confirmation:
 *
 *   1. product + destination-branch rule
 *   2. product rule (any destination)
 *   3. category + destination-branch rule
 *   4. category rule (any destination)
 *   5. cost x (1 + default markup)
 *   6. product cost
 *
 * Default markup decision (plan §10 Q11) — HASAN did not answer the
 * Phase 2 questions, so the plan default applies: 0% (i.e. cost) until
 * he sets a markup. Change DEFAULT_MARKUP_PERCENT here when he does.
 */
class BranchTransferPriceService
{
    public const DEFAULT_MARKUP_PERCENT = 0.0;

    public function suggestPrice(Product $product, ?Branch $destinationBranch = null, ?Carbon $date = null): float
    {
        $cost = (float) ($product->cost ?? 0);
        $date ??= now()->toDateString();
        $companyId = (int) $product->company_id;

        $rule = $this->findRule($companyId, (int) $product->getKey(), (int) ($product->category_id ?? 0), $destinationBranch?->getKey(), $date)
            ?? $this->findRule($companyId, (int) $product->getKey(), (int) ($product->category_id ?? 0), null, $date)
            ?? $this->findCategoryRule($companyId, (int) ($product->category_id ?? 0), $destinationBranch?->getKey(), $date)
            ?? $this->findCategoryRule($companyId, (int) ($product->category_id ?? 0), null, $date);

        if ($rule) {
            return round($rule->priceFor($cost), 4);
        }

        return round($cost * (1 + self::DEFAULT_MARKUP_PERCENT / 100), 4);
    }

    /**
     * The rule that produced the suggestion, if any — shown in the form
     * so the user can see why a price was suggested.
     */
    public function findApplicableRule(Product $product, ?Branch $destinationBranch = null, ?Carbon $date = null): ?BranchTransferPriceRule
    {
        $date ??= now()->toDateString();
        $companyId = (int) $product->company_id;

        return $this->findRule($companyId, (int) $product->getKey(), (int) ($product->category_id ?? 0), $destinationBranch?->getKey(), $date)
            ?? $this->findRule($companyId, (int) $product->getKey(), (int) ($product->category_id ?? 0), null, $date)
            ?? $this->findCategoryRule($companyId, (int) ($product->category_id ?? 0), $destinationBranch?->getKey(), $date)
            ?? $this->findCategoryRule($companyId, (int) ($product->category_id ?? 0), null, $date);
    }

    protected function findRule(int $companyId, int $productId, int $categoryId, ?int $destinationBranchId, string $date): ?BranchTransferPriceRule
    {
        $query = BranchTransferPriceRule::query()
            ->active()
            ->effectiveOn($date)
            ->where('company_id', $companyId)
            ->where('product_id', $productId);

        if ($destinationBranchId === null) {
            $query->whereNull('destination_branch_id');
        } else {
            $query->where('destination_branch_id', $destinationBranchId);
        }

        return $query->orderBy('id')->first();
    }

    protected function findCategoryRule(int $companyId, int $categoryId, ?int $destinationBranchId, string $date): ?BranchTransferPriceRule
    {
        if (! $categoryId) {
            return null;
        }

        $query = BranchTransferPriceRule::query()
            ->active()
            ->effectiveOn($date)
            ->where('company_id', $companyId)
            ->whereNull('product_id')
            ->where('category_id', $categoryId);

        if ($destinationBranchId === null) {
            $query->whereNull('destination_branch_id');
        } else {
            $query->where('destination_branch_id', $destinationBranchId);
        }

        return $query->orderBy('id')->first();
    }
}
