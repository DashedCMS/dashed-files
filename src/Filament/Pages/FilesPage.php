<?php

namespace Qubiqx\QcommerceFiles\Filament\Pages;

use Filament\Pages\Page;

class FilesPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Content';
    protected static ?string $navigationLabel = 'Bestanden';

    protected static string $view = 'qcommerce-files::files.pages.files';
}
