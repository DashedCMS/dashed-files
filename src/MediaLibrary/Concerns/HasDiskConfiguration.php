<?php

namespace Dashed\DashedFiles\MediaLibrary\Concerns;

trait HasDiskConfiguration
{
    protected string $diskVisibility = 'public';

    public function diskVisibility(string $visibility): static
    {
        $this->diskVisibility = $visibility;

        return $this;
    }

    public function diskVisibilityPublic(): static
    {
        return $this->diskVisibility('public');
    }

    public function diskVisibilityPrivate(): static
    {
        return $this->diskVisibility('private');
    }

    public function getDiskVisibility(): string
    {
        return $this->diskVisibility;
    }
}
