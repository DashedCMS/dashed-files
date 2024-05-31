<?php

namespace Dashed\DashedFiles\Classes;

use Illuminate\Console\Command;
use RalphJSmit\Filament\MediaLibrary\Forms\Components\MediaPicker;

class MediaHelper extends Command
{
    public function field($name = 'image', $label = 'Afbeelding', $required = false, $multiple = false, $isImage = false)
    {

        return;
        //        $mediaPicker = MediaPicker::make($name)
        //            ->label($label)
        //            ->required($required)
        //            ->multiple($multiple)
        //            ->showFileName()
        //            ->downloadable()
        //            ->reorderable();

        if($isImage) {
            $mediaPicker->acceptedFileTypes(['image/*']);
        }

        return $mediaPicker;
    }
}
