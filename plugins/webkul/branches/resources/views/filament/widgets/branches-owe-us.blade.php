<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Branches owe us
        </x-slot>

        <x-slot name="description">
            Live internal-invoice residuals — what each Sub branch owes Main. Total outstanding BDT {{ number_format($this->getTotalReceivable(), 2) }}@if ($this->getTotalOverdue() > 0)<span class="text-danger-600">, of which BDT {{ number_format($this->getTotalOverdue(), 2) }} overdue</span>@endif.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pe-4">Branch</th>
                        <th class="py-2 pe-4 text-right">Owes Main</th>
                        <th class="py-2 pe-4 text-right">Overdue</th>
                        <th class="py-2 pe-4 text-right">Main owes branch</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getRows() as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pe-4 font-medium">{{ $row['branch']->code }} — {{ $row['branch']->name }}</td>
                            <td class="py-2 pe-4 text-right">BDT {{ number_format($row['receivable'], 2) }}</td>
                            <td class="py-2 pe-4 text-right {{ $row['overdue'] > 0 ? 'font-semibold text-danger-600' : '' }}">BDT {{ number_format($row['overdue'], 2) }}</td>
                            <td class="py-2 pe-4 text-right">BDT {{ number_format($row['payable'], 2) }}</td>
                            <td class="py-2 text-right">
                                <a class="text-primary-600 hover:underline" href="{{ \Webkul\Branch\Filament\Pages\BranchProfile::getUrl(['branch' => $row['branch']->getKey()]) }}">Profile</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-gray-500">No Sub branches.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
