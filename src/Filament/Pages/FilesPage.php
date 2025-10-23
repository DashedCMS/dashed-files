<?php

namespace Dashed\DashedFiles\Filament\Pages;

use UnitEnum;
use BackedEnum;
use Filament\Pages\Page;

class FilesPage extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-text';
    protected static string | UnitEnum | null $navigationGroup = 'Content';
    protected static ?string $navigationLabel = 'Bestanden';

    protected string $view = 'dashed-files::files.pages.files';
}
