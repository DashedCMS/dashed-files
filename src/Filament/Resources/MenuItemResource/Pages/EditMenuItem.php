<?php

namespace Qubiqx\QcommerceCore\Filament\Resources\MenuItemResource\Pages;

use Qubiqx\QcommerceCore\Classes\Sites;
use Filament\Pages\Actions\ButtonAction;
use Filament\Resources\Pages\EditRecord;
use Qubiqx\QcommerceCore\Filament\Resources\MenuItemResource;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditMenuItem extends EditRecord
{
    use Translatable;

    protected static string $resource = MenuItemResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['model'] = null;
        $data['model_id'] = null;

        foreach ($data as $formFieldKey => $formFieldValue) {
            foreach (cms()->builder('routeModels') as $routeKey => $routeModel) {
                if ($formFieldKey == "{$routeKey}_id") {
                    $data['model'] = $routeModel['class'];
                    $data['model_id'] = $formFieldValue;
                    unset($data["{$routeKey}_id"]);
                }
            }
        }

        $data['site_ids'] = $data['site_ids'] ?? [Sites::getFirstSite()['id']];

        return $data;
    }

    protected function getCancelButtonFormAction(): ButtonAction
    {
        return ButtonAction::make('return')
            ->label('Terug naar menu')
            ->url(route('filament.resources.menus.edit', [$this->record->menu]));
    }

    protected function getBreadcrumbs(): array
    {
        $breadcrumbs = parent::getBreadcrumbs();
        array_shift($breadcrumbs);
        $breadcrumbs = array_merge([route('filament.resources.menus.edit', [$this->record->menu->id]) => "Menu {$this->record->menu->name}"], $breadcrumbs);

        return $breadcrumbs;
    }
}
