<?php

namespace Qubiqx\QcommerceCore\Filament\Resources\PageResource\Pages;

use Illuminate\Support\Str;
use Qubiqx\QcommerceCore\Models\Page;
use Qubiqx\QcommerceCore\Classes\Sites;
use Filament\Pages\Actions\ButtonAction;
use Filament\Resources\Pages\EditRecord;
use Qubiqx\QcommerceCore\Filament\Resources\PageResource;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditPage extends EditRecord
{
    use Translatable;

    protected static string $resource = PageResource::class;

    protected function getActions(): array
    {
        return array_merge(parent::getActions(), [
            ButtonAction::make('view_page')
                ->label('Bekijk pagina')
                ->openUrlInNewTab()
                ->url($this->record->getUrl()),
            $this->getActiveFormLocaleSelectAction(),
        ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['slug'] = Str::slug($data['slug'] ?: $data['name']);

        while (Page::where('id', '!=', $this->record->id)->where('slug->' . $this->activeFormLocale, $data['slug'])->count()) {
            $data['slug'] .= Str::random(1);
        }

        $data['site_id'] = $data['site_id'] ?? Sites::getFirstSite()['id'];

        $content = $data['content'];
        $data['content'] = $this->record->content;
        $data['content'][$this->activeFormLocale] = $content;

        return $data;
    }
}
