<?php

namespace Dashed\DashedFiles\MediaLibrary\Concerns;

use Illuminate\Contracts\Support\Htmlable;

trait HasNavigationConfiguration
{
    protected ?string $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected ?string $navigationIcon = null;

    protected ?string $activeNavigationIcon = null;

    protected ?string $navigationLabel = null;

    protected ?string $title = null;

    protected ?string $slug = null;

    public function navigationGroup(?string $navigationGroup): static
    {
        $this->navigationGroup = $navigationGroup;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    public function navigationSort(int $navigationSort): static
    {
        $this->navigationSort = $navigationSort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function navigationIcon(?string $navigationIcon): static
    {
        $this->navigationIcon = $navigationIcon;

        return $this;
    }

    public function getNavigationIcon(): ?string
    {
        return $this->navigationIcon;
    }

    public function activeNavigationIcon(?string $activeNavigationIcon): static
    {
        $this->activeNavigationIcon = $activeNavigationIcon;

        return $this;
    }

    public function getActiveNavigationIcon(): ?string
    {
        return $this->activeNavigationIcon ?? $this->getNavigationIcon();
    }

    public function navigationLabel(?string $navigationLabel): static
    {
        $this->navigationLabel = $navigationLabel;

        return $this;
    }

    public function getNavigationLabel(): ?string
    {
        return $this->navigationLabel;
    }

    public function pageTitle(string | Htmlable $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getPageTitle(): string | Htmlable | null
    {
        return $this->title;
    }

    public function slug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }
}
