<x-filament-panels::page>
    @php
        $kpis = $this->getKpis();
        $transfers = $this->getTransfers();
        $payments = $this->getPayments();
        $ledger = $this->getLedgerLines();
        $stock = $this->getStockSummary();
        $writeOffs = $this->getWriteOffs();
        $money = fn ($v) => number_format((float) $v, 2);
    @endphp

    <div class="space-y-6">
        {{-- Balance cards — read live from invoice residuals, never stored. --}}
        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Receivable outstanding</p>
                <p class="mt-1 text-xl font-semibold">BDT {{ $money($kpis['receivable']) }}</p>
                <p class="text-xs text-gray-400">Sub branches owe Main</p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Payable outstanding</p>
                <p class="mt-1 text-xl font-semibold">BDT {{ $money($kpis['payable']) }}</p>
                <p class="text-xs text-gray-400">Main owes this branch (returns)</p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Net outstanding</p>
                <p class="mt-1 text-xl font-semibold">BDT {{ $money($kpis['net']) }}</p>
                <p class="text-xs text-gray-400">Receivable minus payable</p>
            </div>
            <div class="rounded-xl p-4 shadow-sm ring-1 {{ $this->isOverdue() ? 'bg-red-50 ring-red-200 dark:bg-red-950/40 dark:ring-red-900' : 'bg-white ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' }}">
                <p class="text-sm text-gray-500 dark:text-gray-400">Overdue receivable</p>
                <p class="mt-1 text-xl font-semibold {{ $this->isOverdue() ? 'text-red-600 dark:text-red-400' : '' }}">BDT {{ $money($kpis['overdue']) }}</p>
                <p class="text-xs text-gray-400">{{ $this->isOverdue() ? 'Overdue — follow up with the branch' : 'Nothing overdue' }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Total transferred in</p>
                <p class="mt-1 text-xl font-semibold">BDT {{ $money($kpis['transferred']) }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Total paid</p>
                <p class="mt-1 text-xl font-semibold">BDT {{ $money($kpis['paid']) }}</p>
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $this->branch->code }} · {{ $this->branch->type->getLabel() }}
                · Warehouse: {{ $this->branch->warehouse?->name ?? '—' }}
                · Manager: {{ $this->branch->manager?->name ?? '—' }}
                · {{ $this->branch->is_active ? 'Active' : 'Inactive' }}
                @if ($this->branch->is_demo) · Demo data @endif
            </p>
        </div>

        {{-- Transfer history --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="border-b border-gray-200 px-4 py-3 text-base font-semibold dark:border-white/10">Transfer history</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-2">Transfer</th>
                            <th class="px-4 py-2">Direction</th>
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2 text-right">Total</th>
                            <th class="px-4 py-2 text-right">Outstanding</th>
                            <th class="px-4 py-2">Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transfers as $t)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-4 py-2">
                                    <a class="font-medium text-primary-600 hover:underline" href="{{ \Webkul\Branch\Filament\Resources\BranchTransferSaleResource::getUrl('view', ['record' => $t]) }}">{{ $t->name }}</a>
                                    @if ($t->is_closed_short)
                                        <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800 dark:bg-amber-900 dark:text-amber-200">closed short</span>
                                    @endif
                                    @if ($t->type->value === 'return')
                                        <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">return{{ $t->return_reason ? ' · '.$t->return_reason : '' }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">{{ $t->sourceBranch?->code }} → {{ $t->destinationBranch?->code }}</td>
                                <td class="px-4 py-2">{{ $t->transfer_date?->format('Y-m-d') }}</td>
                                <td class="px-4 py-2">{{ $t->status->getLabel() }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($t->amount_total) }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($t->amount_residual) }}</td>
                                <td class="px-4 py-2">{{ $t->due_date?->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-3 text-gray-500">No transfers yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Payment history --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="border-b border-gray-200 px-4 py-3 text-base font-semibold dark:border-white/10">Payment history</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2">From → To</th>
                            <th class="px-4 py-2 text-right">Amount</th>
                            <th class="px-4 py-2">Reference</th>
                            <th class="px-4 py-2">Allocated to</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payments as $p)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-4 py-2">{{ $p->payment_date?->format('Y-m-d') }}</td>
                                <td class="px-4 py-2">{{ $p->payerBranch?->code }} → {{ $p->payeeBranch?->code }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($p->amount) }}</td>
                                <td class="px-4 py-2">{{ $p->reference ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $p->allocations->map(fn ($a) => $a->transfer?->name.' ('.$money($a->amount).')')->join(', ') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-3 text-gray-500">No payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Stock summary (FIFO layers) --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="border-b border-gray-200 px-4 py-3 text-base font-semibold dark:border-white/10">Stock summary (FIFO layers)</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-2">Product</th>
                            <th class="px-4 py-2">Location</th>
                            <th class="px-4 py-2 text-right">Qty on hand</th>
                            <th class="px-4 py-2 text-right">Value at transfer price</th>
                            <th class="px-4 py-2 text-right">Value at original cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stock as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-4 py-2">{{ $row->product_name }}</td>
                                <td class="px-4 py-2">{{ $row->location_name ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($row->quantity) }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($row->value) }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($row->cost_value) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-3 text-gray-500">No stock layers at this branch.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Write-offs --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="border-b border-gray-200 px-4 py-3 text-base font-semibold dark:border-white/10">Lost / missing write-offs</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-2">Write-off</th>
                            <th class="px-4 py-2">Transfer</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2 text-right">Loss</th>
                            <th class="px-4 py-2">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($writeOffs as $w)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-4 py-2">{{ $w->name }}</td>
                                <td class="px-4 py-2">{{ $w->transfer?->name ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $w->status->getLabel() }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($w->amount_total) }}</td>
                                <td class="px-4 py-2">{{ \Illuminate\Support\Str::limit($w->investigation_note, 80) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-3 text-gray-500">No write-offs.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Internal ledger lines --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="border-b border-gray-200 px-4 py-3 text-base font-semibold dark:border-white/10">Internal ledger (latest 30 lines)</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2">Document</th>
                            <th class="px-4 py-2">Account</th>
                            <th class="px-4 py-2 text-right">Debit</th>
                            <th class="px-4 py-2 text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ledger as $line)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-4 py-2">{{ $line->date?->format('Y-m-d') }}</td>
                                <td class="px-4 py-2">{{ $line->move?->reference ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $line->account?->code }} {{ $line->account?->name }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($line->debit) }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($line->credit) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-3 text-gray-500">No internal ledger lines yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
