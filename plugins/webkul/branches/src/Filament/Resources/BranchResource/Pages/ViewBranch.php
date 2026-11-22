<?php

namespace Webkul\Branch\Filament\Resources\BranchResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Webkul\Branch\Filament\Pages\BranchProfile;
use Webkul\Branch\Filament\Resources\BranchResource;

class ViewBranch extends ViewRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewProfile')
                ->label('View profile')
                ->icon('heroicon-o-identification')
                ->color('primary')
                ->url(fn () => BranchProfile::getUrl(['branch' => $this->record->getKey()]))
                ->visible(fn () => Gate::allows('viewProfile', $this->record)),
            ...parent::getHeaderActions(),
        ];
    }
}
