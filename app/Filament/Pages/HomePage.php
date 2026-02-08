<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\QueuesContentImageDerivatives;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\HeroSliderBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageGalleryBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RawHtmlBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RutubeVideoBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YandexMapBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YoutubeVideoBlock;
use App\Filament\Forms\Components\RichEditor\Plugins\TextSizeRichContentPlugin;
use App\Models\Page;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use UnitEnum;

class HomePage extends FilamentPage
{
    use QueuesContentImageDerivatives;

    private const PAGE_SLUG = 'home';
    private const PAGE_TITLE = 'Главная';

    protected static ?string $title = 'Главная';

    protected static ?string $navigationLabel = 'Главная';

    protected static string | UnitEnum | null $navigationGroup = 'Контент';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'home';

    public ?array $data = [];

    #[Locked]
    public ?Page $page = null;

    public function mount(): void
    {
        $this->page = Page::query()->firstOrCreate(
            ['slug' => self::PAGE_SLUG],
            [
                'title' => self::PAGE_TITLE,
                'meta_title' => self::PAGE_TITLE,
                'content' => null,
                'is_published' => true,
                'published_at' => now(),
            ],
        );

        $this->form->fill([
            'content' => $this->page->content,
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->model($this->page)
            ->operation('edit')
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Контент')
                    ->columns(1)
                    ->components([
                        RichEditor::make('content')
                            ->label('Контент')
                            ->columnSpanFull()
                            ->tools([
                                RichEditorTool::make('clearContent')
                                    ->label('Очистить')
                                    ->icon(Heroicon::Trash)
                                    ->activeStyling(false)
                                    ->jsHandler("confirm('Очистить описание?') && ".'$getEditor'."()?.chain().focus().clearContent().run()"),
                            ])
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'textColor', 'textSize', 'strike', 'subscript', 'superscript', 'small', 'lead', 'link'],
                                ['h1', 'h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                                ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                                ['table', 'attachFiles', 'customBlocks'],
                                ['undo', 'redo'],
                                ['horizontalRule', 'grid', 'gridDelete'],
                            ])
                            ->plugins([
                                TextSizeRichContentPlugin::make(),
                            ])
                            ->enableToolbarButtons([['clearContent', 'clearFormatting']])
                            ->fileAttachmentsDisk('public')
                            ->fileAttachmentsDirectory('pages')
                            ->resizableImages()
                            ->customBlocks([
                                ImageBlock::class,
                                ImageGalleryBlock::class,
                                RutubeVideoBlock::class,
                                YoutubeVideoBlock::class,
                                HeroSliderBlock::class,
                                YandexMapBlock::class,
                                RawHtmlBlock::class,
                            ])
                            ->fileAttachmentsDisk('public')
                            ->fileAttachmentsDirectory('pics')
                            ->fileAttachmentsVisibility('public'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions())
                            ->alignment($this->getFormActionsAlignment())
                            ->fullWidth($this->hasFullWidthFormActions())
                            ->key('form-actions'),
                    ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_webp_derivatives')
                ->label('Сгенерировать WebP')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->disabled(fn () => ! $this->hasAnyContentImages($this->homeContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->homeContentValues(), false);
                    $this->notifyContentImageDerivativesQueued($queued, false);
                }),
            Action::make('regenerate_webp_derivatives')
                ->label('Перегенерировать WebP (force)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->disabled(fn () => ! $this->hasAnyContentImages($this->homeContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->homeContentValues(), true);
                    $this->notifyContentImageDerivativesQueued($queued, true);
                }),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Сохранить')
                ->icon(Heroicon::Check)
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    protected function hasFullWidthFormActions(): bool
    {
        return false;
    }

    private function homeContentValues(): array
    {
        $state = $this->form->getState();

        return [
            $state['content'] ?? $this->page?->content,
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $payload = [
            'content' => $data['content'] ?? null,
            'is_published' => true,
        ];

        if (blank($this->page->published_at)) {
            $payload['published_at'] = now();
        }

        $this->page->fill($payload)->save();

        Notification::make()
            ->title('Главная обновлена')
            ->success()
            ->send();
    }
}
