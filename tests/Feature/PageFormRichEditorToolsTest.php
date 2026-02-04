<?php

use App\Filament\Resources\Pages\Schemas\PageForm;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Livewire\Component as LivewireComponent;

it('adds clear content tool to page form rich editor', function () {
    $livewire = new class extends LivewireComponent implements HasSchemas
    {
        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function getOldSchemaState(string $statePath): mixed
        {
            return null;
        }

        public function getSchemaComponent(string $key, bool $withHidden = false, array $skipComponentsChildContainersWhileSearching = []): Component|Action|ActionGroup|null
        {
            return null;
        }

        public function getSchema(string $name): ?Schema
        {
            return null;
        }

        public function currentlyValidatingSchema(?Schema $schema): void {}

        public function getDefaultTestingSchemaName(): ?string
        {
            return null;
        }
    };

    $schema = PageForm::configure(Schema::make($livewire));

    $richEditor = $schema->getComponent(
        fn ($component) => $component instanceof RichEditor && $component->getName() === 'content',
    );

    expect($richEditor)->not->toBeNull();
    expect($richEditor->getTools())->toHaveKey('clearContent');
    expect($richEditor->hasToolbarButton('clearContent'))->toBeTrue();
});
